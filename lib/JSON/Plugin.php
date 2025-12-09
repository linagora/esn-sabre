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

        $calendarHandler = $this->getCalendarHandler();
        if ($calendarHandler->isOldDefaultCalendarUriNotFound($request->getPath())) {
            $defaultCalendarUri = $calendarHandler->getDefaultCalendarUri($this->currentUser, $request->getPath());
            $url = str_replace(\ESN\CalDAV\Backend\Esn::EVENTS_URI, $defaultCalendarUri, $url);
        }

        $request->setUrl($url);

        return true;
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

        // Handle sync-token based requests
        if (isset($jsonData->{'sync-token'})) {
            list($code, $body) = $this->handleSyncTokenReport($path, $node, $jsonData);
        } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
            list($code, $body) = $this->getCalendarObjectHandler()->getCalendarObjectByUID($path, $node, $jsonData);
        } else if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
            list($code, $body) = $this->getCalendarObjectHandler()->getCalendarObjects($path, $node, $jsonData);
        } else if ($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            list($code, $body) = $this->getSubscriptionHandler()->getCalendarObjectsForSubscription($path, $node, $jsonData);
        } else if ($node instanceof \ESN\CalDAV\CalendarRoot) {
            list($code, $body) = $this->getCalendarObjectHandler()->getMultipleCalendarObjectsFromPaths($path, $jsonData);
        } else if ($node instanceof \Sabre\CalDAV\ICalendarObject) {
            list($code, $body) = $this->handleCalendarObjectReport($path, $node, $jsonData);
        } else {
            $code = 200;
            $body = [];
        }

        return $this->send($code, $body);
    }

    private function handleSyncTokenReport($path, $node, $jsonData) {
        if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
            return $this->getCalendarObjectHandler()->getCalendarObjectsBySyncToken($path, $node, $jsonData);
        }
        return [400, null];
    }

    private function handleCalendarObjectReport($path, $node, $jsonData) {
        if (isset($jsonData->match) && isset($jsonData->match->start) && isset($jsonData->match->end)) {
            return $this->getCalendarObjectHandler()->expandEvent($path, $node, $jsonData);
        }
        return [400, null];
    }

    function post($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $code = null;
        $body = null;

        if ($path == 'query') {
            $jsonData = json_decode($request->getBodyAsString());
            list($code, $body) = $this->getCalendarObjectHandler()->queryCalendarObjects($path, null, $jsonData);
            return $this->send($code, $body);
        }

        $node = $this->server->tree->getNodeForPath($path);

        // Only handle calendar nodes, let other plugins handle other nodes (like addressbooks)
        if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
            $jsonData = json_decode($request->getBodyAsString());
            list($code, $body) = $this->handleJsonRequest($path, $node, $jsonData);
        } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
            $jsonData = json_decode($request->getBodyAsString());
            list($code, $body) = $this->handleCalendarHomePost($path, $jsonData);
        } else {
            // Not a calendar node - let other plugins handle it
            return true;
        }

        return $this->send($code, $body);
    }

    private function handleCalendarHomePost($path, $jsonData) {
        if ($this->getSubscriptionHandler()->isBodyForSubscription($jsonData)) {
            return $this->getSubscriptionHandler()->createSubscription($path, $jsonData);
        }
        return $this->getCalendarHandler()->createCalendar($path, $jsonData);
    }

    function get($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);
        $queryParams = $request->getQueryParameters();

        $code = null;
        $body = null;

        if ($node instanceof \ESN\CalDAV\CalendarRoot) {
            list($code, $body) = $this->getCalendarRoot($node);
        } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
            list($code, $body) = $this->getCalendarHome($path, $node, $queryParams);
        } else if ($node instanceof \Sabre\CalDAV\Calendar) {
            list($code, $body) = $this->getCalendar($path, $node, $queryParams);
        } else if ($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            list($code, $body) = $this->getSubscription($path, $node, $queryParams);
        }

        return $this->send($code, $body);
    }

    private function getCalendarRoot($node) {
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
            $items[$home->getName()] = $this->getCalendarHandler()->listAllPersonalCalendars($home);
        }

        return [200, $items];
    }

    private function getCalendarHome($path, $node, $queryParams) {
        $withFreeBusy = $this->getBooleanParameter($queryParams, 'withFreeBusy');
        $withRights = $this->getBooleanParameter($queryParams, 'withRights');
        $sharedPublic = $this->getBooleanParameter($queryParams, 'sharedPublic');
        $calendarFilterParameters = $this->getCalendarFilterParameters($queryParams);

        return $this->getCalendarHandler()->listCalendars($path, $node, $withRights, $calendarFilterParameters, $sharedPublic, $withFreeBusy);
    }

    private function getCalendar($path, $node, $queryParams) {
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

            return [200, $result];
        }

        $withRights = $this->getBooleanParameter($queryParams, 'withRights');
        return $this->getCalendarHandler()->getCalendarInformation($path, $node, $withRights);
    }

    private function getSubscription($path, $node, $queryParams) {
        $withRights = $this->getBooleanParameter($queryParams, 'withRights');
        return $this->getSubscriptionHandler()->getSubscriptionInformation($path, $node, $withRights);
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
            $jsonData = json_decode($request->getBodyAsString());
            list($code, $body) = $this->handleCalendarProppatch($path, $node, $jsonData);
        } else if ($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            $jsonData = json_decode($request->getBodyAsString());
            list($code, $body) = $this->handleSubscriptionProppatch($path, $node, $jsonData);
        }

        return $this->send($code, $body);
    }

    private function handleCalendarProppatch($path, $node, $jsonData) {
        return $this->getCalendarHandler()->changeCalendarProperties($path, $node, $jsonData);
    }

    private function handleSubscriptionProppatch($path, $node, $jsonData) {
        return $this->getSubscriptionHandler()->changeSubscriptionProperties($path, $node, $jsonData);
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

        // Try calendar handler
        $result = $this->getCalendarHandler()->changePublicRights($request, $response);

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
