<?php

namespace ESN\JSON;

use ESN\JSON\CalDAV\CalendarHandler;
use ESN\JSON\CalDAV\CalendarObjectHandler;
use ESN\JSON\CalDAV\SubscriptionHandler;
use ESN\Utils\Utils;
use \Sabre\DAV\Exception\Forbidden;
use \Sabre\VObject,
    \Sabre\DAV;
use Sabre\VObject\ITip\Message;

#[\AllowDynamicProperties]
class Plugin extends \Sabre\CalDAV\Plugin {

    const FREE_BUSY_QUERY = 'free-busy-query';
    const ACCEPT_JSON_VALUES = [
        'application/calendar+json',
        'application/vcard+json',
        'application/json'
    ];

    protected $root;
    protected $server;
    protected $acceptHeader;
    protected $currentUser;

    // Handlers
    protected $calendarHandler;
    protected $calendarObjectHandler;
    protected $subscriptionHandler;

    function __construct($root) {
        $this->root = $root;
    }

    function initialize(DAV\Server $server) {
        $this->server = $server;
        $server->on('beforeMethod:*', [$this, 'beforeMethod'], 15); // 15 is after Auth and before ACL
        $server->on('beforeWriteContent', [$this, 'beforeWriteContent']);
        $server->on('beforeUnbind', [$this, 'beforeUnbind']);
        $server->on('method:REPORT', [$this, 'httpReport'], 80);
        $server->on('method:POST', [$this, 'post'], 80);
        $server->on('method:GET', [$this, 'get'], 80);
        $server->on('method:PROPPATCH', [$this, 'proppatch'], 80);
        $server->on('method:PROPFIND', [$this, 'findProperties'], 80);
        $server->on('method:ACL', [$this, 'changePublicRights'], 80);
        $server->on('afterMethod:REPORT', [$this, 'afterMethodReport']);
    }

    protected function getCalendarHandler() {
        if (!$this->calendarHandler) {
            $this->calendarHandler = new CalendarHandler($this->server, $this->currentUser);
        }
        return $this->calendarHandler;
    }

    protected function getCalendarObjectHandler() {
        if (!$this->calendarObjectHandler) {
            $this->calendarObjectHandler = new CalendarObjectHandler($this->server, $this->currentUser);
        }
        return $this->calendarObjectHandler;
    }

    protected function getSubscriptionHandler() {
        if (!$this->subscriptionHandler) {
            $this->subscriptionHandler = new SubscriptionHandler($this->server, $this->currentUser);
        }
        return $this->subscriptionHandler;
    }

    function afterMethodReport($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $data = $response->getBodyAsString();

        if (isset($data) && $data !== "") {
            $contentTypes = $response->getHeaderAsArray('Content-Type');

            $isIcal = in_array('text/calendar', $contentTypes);
            $isXml = in_array('application/xml', $contentTypes);

            if ($isIcal || $isXml) {
                if ($isIcal) {
                    $result = \Sabre\VObject\Reader::read($data);
                } else {
                    $result = $this->server->xml->parse($data);
                }

                $path = $request->getPath();
                $body = [
                    '_links' => [
                        'self' => [ 'href' =>  $this->server->getBaseUri().$path ]
                    ],
                    'data' => $result
                ];

                $json_encode = json_encode($body);

                $response->removeHeader('Content-Type');
                $response->setHeader('Content-Type', 'application/json; charset=utf-8');

                $response->removeHeader('Content-Length');
                $response->setHeader('Content-Length', strlen($json_encode));

                $response->setBody($json_encode);
            }
        }

        return true;
    }

    function beforeMethod($request, $response) {
        $url = $request->getUrl();
        if (strpos($url, '.json') !== false) {
            $url = str_replace('.json','', $url);
            $request->setUrl($url);
        }

        $this->acceptHeader = explode(', ', $request->getHeader('Accept') ?? '');
        $this->currentUser = $this->server->getPlugin('auth')->getCurrentPrincipal();

        if ($this->_isOldDefaultCalendarUriNotFound($request->getPath())) {
            $defaultCalendarUri = $this->_getDefaultCalendarUri($this->currentUser, $request->getPath());
            $url = str_replace(\ESN\CalDAV\Backend\Esn::EVENTS_URI, $defaultCalendarUri, $url);
        }

        $request->setUrl($url);

        return true;
    }

    function _isOldDefaultCalendarUriNotFound($url) {
        return strpos($url, \ESN\CalDAV\Backend\Esn::EVENTS_URI) && !$this->server->tree->nodeExists($url);
    }

    function _getDefaultCalendarUri($user, $path) {
        list(,,$userId) = explode('/', $user);

        $homePath = substr($path, 0, strpos($path, \ESN\CalDAV\Backend\Esn::EVENTS_URI));
        $node = $this->server->tree->getNodeForPath($homePath);

        $calendars = $node->getChildren();

        foreach ($calendars as $calendar) {
            $name = $calendar->getName();

            if ($name === \ESN\CalDAV\Backend\Esn::EVENTS_URI || $name === $userId ) {
                return $name;
            }
        }

        // No default calendar found - create it
        // This handles the case where a user has delegated calendars but no personal default calendar yet (issue #206)
        $backend = $node->getCalDAVBackend();
        if ($backend instanceof \ESN\CalDAV\Backend\Esn) {
            $properties = [];
            if (Utils::isResourceFromPrincipal($user)) {
                $principalBackend = $backend->getPrincipalBackend();
                $principal = $principalBackend->getPrincipalByPath($user);
                if ($principal) {
                    $properties['{DAV:}displayname'] = $principal['{DAV:}displayname'];
                }
            }
            $backend->createCalendar($user, $userId, $properties);
            return $userId;
        }

        throw new DAV\Exception\NotFound('Unable to find or create user default calendar');
    }

    function beforeUnbind($path) {
        $node = $this->server->tree->getNodeForPath($path);

        if ($node instanceof \Sabre\DAV\IFile) {
            return $this->checkModificationsRights($this->server->tree->getNodeForPath($path));
        }

        if ($node instanceof \Sabre\CalDAV\Calendar) {
            $mainCalendarId = explode('/', $this->currentUser);

            if ($node->getName() === \ESN\CalDAV\Backend\Esn::EVENTS_URI || $node->getName() === $mainCalendarId[2]) {
                throw new DAV\Exception\Forbidden('Forbidden: You can not delete your main calendar');
            }
        }
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        return $this->checkModificationsRights($node);
    }

    function checkModificationsRights(\Sabre\DAV\IFile $node) {
        if ($node instanceof \Sabre\CalDAV\ICalendarObject) {
            $vcalendar = VObject\Reader::read($node->get());
            if (Utils::isHiddenPrivateEvent($vcalendar->VEVENT, $node, $this->currentUser)) {
                throw new DAV\Exception\Forbidden('You can not modify private events you do not own');
            }
        }
        return true;
    }

    function httpReport($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $jsonData = json_decode($request->getBodyAsString());

        if (isset($jsonData->type) && $jsonData->type === self::FREE_BUSY_QUERY) {
            if (!isset($jsonData->match) ||
                !isset($jsonData->match->start) ||
                !isset($jsonData->match->end)) {
                throw new DAV\Exception\BadRequest('Missing report parameters in JSON body');
            }

            $writer = new \Sabre\Xml\Writer();
            $writer->openMemory();
            $writer->startDocument('1.0', 'UTF-8');
            $writer->startElement('{' . Plugin::NS_CALDAV . '}' . self::FREE_BUSY_QUERY);
            $writer->startElement('{' . Plugin::NS_CALDAV . '}time-range');
            $writer->writeAttributes(['start' => $jsonData->match->start, 'end' => $jsonData->match->end]);
            $writer->endElement();
            $writer->endElement();

            $request->setBody($writer->outputMemory());

            return true;
        }

        $code = null;
        $body = null;
        $path = $request->getPath();

        $node = $this->server->tree->getNodeForPath($path);
        $objectHandler = $this->getCalendarObjectHandler();

        // Handle sync-token based requests
        if (isset($jsonData->{'sync-token'})) {
            if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
                list($code, $body) = $objectHandler->getCalendarObjectsBySyncToken($path, $node, $jsonData);
            } else {
                $code = 400;
                $body = null;
            }
        } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
            list($code, $body) = $objectHandler->getCalendarObjectByUID($path, $node, $jsonData);
        } else if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
            list($code, $body) = $objectHandler->getCalendarObjects($path, $node, $jsonData);
        } else if ($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            $subscriptionHandler = $this->getSubscriptionHandler();
            list($code, $body) = $subscriptionHandler->getCalendarObjectsForSubscription($path, $node, $jsonData);
        } else if ($node instanceof \ESN\CalDAV\CalendarRoot) {
            list($code, $body) = $objectHandler->getMultipleCalendarObjectsFromPaths($path, $jsonData);
        } else if ($node instanceof \Sabre\CalDAV\ICalendarObject) {
            // Handle individual calendar object with time-range expansion
            if (isset($jsonData->match) && isset($jsonData->match->start) && isset($jsonData->match->end)) {
                list($code, $body) = $objectHandler->expandEvent($path, $node, $jsonData);
            } else {
                // Invalid request: calendar object requires time-range parameters
                $code = 400;
                $body = null;
            }
        } else {
            $code = 200;
            $body = [];
        }

        return $this->send($code, $body);
    }

    function post($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $code = null;
        $body = null;

        if ($path == 'query') {
            $objectHandler = $this->getCalendarObjectHandler();
            list($code, $body) = $objectHandler->queryCalendarObjects(
                $path,
                null,
                json_decode($request->getBodyAsString())
            );
        } else {
            $node = $this->server->tree->getNodeForPath($path);
            if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
                list($code, $body) = $this->handleJsonRequest(
                    $path,
                    $node,
                    json_decode($request->getBodyAsString())
                );
            } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
                $jsonData = json_decode($request->getBodyAsString());

                $subscriptionHandler = $this->getSubscriptionHandler();
                if ($subscriptionHandler->isBodyForSubscription($jsonData)) {
                    list($code, $body) = $subscriptionHandler->createSubscription($path, $jsonData);
                } else {
                    $calendarHandler = $this->getCalendarHandler();
                    list($code, $body) = $calendarHandler->createCalendar($path, $jsonData);
                }
            }

        }

        return $this->send($code, $body);
    }

    function get($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        $code = null;
        $body = null;

        $queryParams = $request->getQueryParameters();
        $withFreeBusy = $this->getBooleanParameter($queryParams, 'withFreeBusy');
        $withRights = $this->getBooleanParameter($queryParams, 'withRights');
        $sharedPublic = $this->getBooleanParameter($queryParams, 'sharedPublic');
        $calendarFilterParameters = $this->getCalendarFilterParameters($queryParams);

        $calendarHandler = $this->getCalendarHandler();

        if ($node instanceof \ESN\CalDAV\CalendarRoot) {
            $authPlugin = $this->server->getPlugin('auth');
            if (!is_null($authPlugin)) {
                $currentPrincipal = $authPlugin->getCurrentPrincipal();
                list(, $type) = explode('/', $currentPrincipal);

                if ($type !== 'technicalUser') {
                    throw new Forbidden();
                }
            }

            $calendarHomes = $node->getChildren();

            $items = [];
            foreach ($calendarHomes as $home) {
                $items[$home->getName()] = $calendarHandler->listAllPersonalCalendars($home);
            }

            list($code, $body) = [200, $items];
        } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
            list($code, $body) = $calendarHandler->listCalendars($path, $node, $withRights, $calendarFilterParameters, $sharedPublic, $withFreeBusy);
        } else if ($node instanceof \Sabre\CalDAV\Calendar) {
            if ($this->getBooleanParameter($queryParams, 'allEvents')) {
                $children = $node->getChildren();
                $items = [];

                foreach ($children as $child) {
                    $items[] = [
                        '_links' => [ 'self' => [ 'href' => '/' . $path . '/' . $child->getName() ] ],
                        'data' => $child->get()
                    ];
                }

                $result = [
                    '_links' => [
                        'self' => [ 'href' => '/' . $path . '.json' ]
                    ],
                    '_embedded'=> [
                        'dav:item' => $items
                    ]
                ];

                list($code, $body) = [200, $result];
            } else {
                list($code, $body) = $calendarHandler->getCalendarInformation($path, $node, $withRights);
            };
        } else if ($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            $subscriptionHandler = $this->getSubscriptionHandler();
            list($code, $body) = $subscriptionHandler->getSubscriptionInformation($path, $node, $withRights);
        }

        return $this->send($code, $body);
    }

    private function getCalendarFilterParameters($queryParams) {

        $filter = isset($queryParams['personal']) || isset($queryParams['sharedPublicSubscription']) || isset($queryParams['sharedDelegationStatus']);

        $includePersonal = isset($queryParams['personal']) ? $queryParams['personal'] === 'true' : !$filter;
        $includeSharedPublicSubscription = isset($queryParams['sharedPublicSubscription']) ? $queryParams['sharedPublicSubscription'] === 'true' : !$filter;
        $sharedDelegationStatus = null;

        $includeShared = isset($queryParams['sharedDelegationStatus']) || !$filter;

        if (isset($queryParams['sharedDelegationStatus'])) {
            switch ($queryParams['sharedDelegationStatus']) {
                case "accepted":
                    $sharedDelegationStatus = \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED;
                    break;
                case "noresponse":
                    $sharedDelegationStatus = \Sabre\DAV\Sharing\Plugin::INVITE_NORESPONSE;
            }
        } else {
            $includeShared = false;
        }

        return compact('includePersonal', 'includeSharedPublicSubscription', 'includeShared', 'sharedDelegationStatus');
    }

    function proppatch($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        $code = null;
        $body = null;

        if ($node instanceof \Sabre\CalDAV\Calendar) {
            $calendarHandler = $this->getCalendarHandler();
            list($code, $body) = $calendarHandler->changeCalendarProperties(
                $path,
                $node,
                json_decode($request->getBodyAsString())
            );
        }

        if ($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            $subscriptionHandler = $this->getSubscriptionHandler();
            list($code, $body) = $subscriptionHandler->changeSubscriptionProperties(
                $path,
                $node,
                json_decode($request->getBodyAsString())
            );
        }

        return $this->send($code, $body);
    }

    function findProperties($request) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $code = null;
        $body = null;
        $node = $this->server->tree->getNodeForPath($path);

        if ($node instanceof \Sabre\CalDAV\SharedCalendar) {
            $jsonData = json_decode($request->getBodyAsString(), true);
            $result = array();
            if (in_array('cs:invite', $jsonData['prop'])) {
                $result['invite'] = $node->getInvites();
            }
            if (in_array('acl', $jsonData['prop'])) {
                $result['acl'] = $node->getACL();
            }

            $this->send(200, $result);
            return false;
        }

        return true;
    }

    function handleJsonRequest($path, $node, $jsonData) {
        $calendarHandler = $this->getCalendarHandler();

        if (isset($jsonData->{'invite-reply'})) {
            return $calendarHandler->updateInviteStatus($path, $node, $jsonData);
        } else if (isset($jsonData->share)) {
            return $calendarHandler->updateSharees($path, $node, $jsonData);
        }

        return [400, null];
    }

    function changePublicRights($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $calendarHandler = $this->getCalendarHandler();
        $result = $calendarHandler->changePublicRights($request, $response);

        if ($result !== null) {
            $this->send($result[0], $result[1]);
            return false;
        }

        return true;
    }

    function acceptJson() {
        return count(array_intersect(self::ACCEPT_JSON_VALUES, $this->acceptHeader)) > 0;
    }

    function send($code, $body, $setContentType = true) {
        if (!isset($code)) {
            return true;
        }

        if ($body) {
            if ($setContentType) {
                $this->server->httpResponse->setHeader('Content-Type','application/json; charset=utf-8');
            }
            $this->server->httpResponse->setBody(json_encode($body));
        }
        $this->server->httpResponse->setStatus($code);
        return false;
    }

    private function propertyOrDefault($jsonData) {
        return function($key, $default = null) use ($jsonData) {
            return isset($jsonData->{$key}) ? $jsonData->{$key} : $default;
        };
    }

    private function getBooleanParameter($queryParams, $str) {
        return isset($queryParams[$str]) && $queryParams[$str] === 'true';
    }
}
