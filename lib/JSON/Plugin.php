<?php

namespace ESN\JSON;

use ESN\Utils\Utils;
use \Sabre\DAV\Exception\Forbidden;
use \Sabre\VObject,
    \Sabre\DAV;
use Sabre\VObject\ITip\Message;

class Plugin extends \Sabre\CalDAV\Plugin {

    const FREE_BUSY_QUERY = 'free-busy-query';
    const ACCEPT_JSON_VALUES = [
        'application/calendar+json',
        'application/vcard+json',
        'application/json'
    ];

    function __construct($root) {
        $this->root = $root;
    }

    function initialize(DAV\Server $server) {
        $this->server = $server;
        $server->on('beforeMethod', [$this, 'beforeMethod'], 15); // 15 is after Auth and before ACL
        $server->on('beforeWriteContent', [$this, 'beforeWriteContent']);
        $server->on('beforeUnbind', [$this, 'beforeUnbind']);
        $server->on('method:REPORT', [$this, 'httpReport'], 80);
        $server->on('method:POST', [$this, 'post'], 80);
        $server->on('method:GET', [$this, 'get'], 80);
        $server->on('method:PROPPATCH', [$this, 'proppatch'], 80);
        $server->on('method:PROPFIND', [$this, 'findProperties'], 80);
        $server->on('method:ITIP', [$this, 'itip'], 80);
        $server->on('method:ACL', [$this, 'changePublicRights'], 80);
        $server->on('afterMethod:REPORT', [$this, 'afterMethodReport']);
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

        $this->acceptHeader = explode(', ', $request->getHeader('Accept'));
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

        throw new DAV\Exception\NotFound('Unable to find user default calendar');
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

    /*
    This is the method called when a user receives an invitation through EMAIL.
    */
    function itip($request) {
        $payload = json_decode($request->getBodyAsString());
        $issetdef = $this->propertyOrDefault($payload);

        if (!isset($payload->uid) || !$payload->sender || !$payload->recipient || !$payload->ical) {
            return $this->send(400, null);
        }

        $message = new Message();
        $message->component = 'VEVENT';
        $message->uid = $payload->uid;
        $message->method = $issetdef('method', 'REQUEST');
        $message->sequence = $issetdef('sequence', '0');
        $message->sender = 'mailto:' . $issetdef('replyTo', $payload->sender);
        $message->recipient = 'mailto:' . $payload->recipient;
        $message->message = VObject\Reader::read($payload->ical);

        // we need to check that the current user ($message->recipient) is related to the event,
        // because he's either organizer, or attendee, or both.
        //
        // Some use cases, like a user forwarding an invite email to another user, brings a recipient
        // that is not, at all, in the event. We ignore it
        if (!$this->assertRecipientIsConcernedByEvent($message->message->vevent, $message->recipient)) {
            error_log("Recipient ". $message->recipient ." is not organizer, not attendee of event ". (string)$message->message->VEVENT->UID .": skipping");
            return $this->send(400, null);
        }

        if($message->method !== 'COUNTER'){
            $this->server->getPlugin('caldav-schedule')->scheduleLocalDelivery($message);
            $this->server->emit('itip', [$message]);
        } else {
            $this->server->emit('schedule', [$message]);
        }

        return $this->send(204, null);
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

        if ($node instanceof \Sabre\CalDAV\CalendarHome) {
            list($code, $body) = $this->getCalendarObjectByUID($path, $node, $jsonData);
        } else if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
            list($code, $body) = $this->getCalendarObjects($path, $node, $jsonData);
        } else if ($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            list($code, $body) = $this->getCalendarObjectsForSubscription($path, $node, $jsonData);
        } else if ($node instanceof \ESN\CalDAV\CalendarRoot) {
            list($code, $body) = $this->getMultipleCalendarObjectsFromPaths($path, $jsonData);
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
            list($code, $body) = $this->queryCalendarObjects(
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

                if ($this->isBodyForSubscription($jsonData)) {
                    list($code, $body) = $this->createSubscription($path, $jsonData);
                } else {
                    list($code, $body) = $this->createCalendar($path, $jsonData);
                }
            }

        }

        return $this->send($code, $body);
    }

    function isBodyForSubscription($jsonData) {
        $issetdef = $this->propertyOrDefault($jsonData);

        return $issetdef('calendarserver:source');
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
                $items[$home->getName()] = $this->listAllPersonalCalendars($home);
            }

            list($code, $body) = [200, $items];
        } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
            list($code, $body) = $this->listCalendars($path, $node, $withRights, $calendarFilterParameters, $sharedPublic, $withFreeBusy);
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
                list($code, $body) = $this->getCalendarInformation($path, $node, $withRights);
            };
        } else if ($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            list($code, $body) = $this->getSubscriptionInformation($path, $node, $withRights);
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
            list($code, $body) = $this->changeCalendarProperties(
                $path,
                $node,
                json_decode($request->getBodyAsString())
            );
        }

        if ($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            list($code, $body) = $this->changeSubscriptionProperties(
                $path,
                $node,
                json_decode($request->getBodyAsString())
            );
        }

        return $this->send($code, $body);

    }

    function createCalendar($homePath, $jsonData) {
        $issetdef = $this->propertyOrDefault($jsonData);

        if (!isset($jsonData->id) || !$jsonData->id) {
            return [400, null];
        }

        $rt = ['{DAV:}collection', '{urn:ietf:params:xml:ns:caldav}calendar'];
        $props = [
            '{DAV:}displayname' => $issetdef('dav:name'),
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => $issetdef('caldav:description'),
            '{http://apple.com/ns/ical/}calendar-color' => $issetdef('apple:color'),
            '{http://apple.com/ns/ical/}calendar-order' => $issetdef('apple:order')
        ];

        $this->server->createCollection($homePath . '/' . $jsonData->id, new \Sabre\DAV\MkCol($rt, $props));

        return [201, null];
    }

    function createSubscription($homePath, $jsonData) {
        $issetdef = $this->propertyOrDefault($jsonData);

        if (!isset($jsonData->id) || !$jsonData->id) {
            return [400, null];
        }

        $sourcePath = $this->server->calculateUri($issetdef('calendarserver:source')->href);

        if (substr($sourcePath, -5) == '.json') {
            $sourcePath = substr($sourcePath, 0, -5);
        }

        $rt = ['{DAV:}collection', '{http://calendarserver.org/ns/}subscribed'];
        $props = [
            '{DAV:}displayname' => $issetdef('dav:name'),
            '{http://apple.com/ns/ical/}calendar-color' => $issetdef('apple:color'),
            '{http://apple.com/ns/ical/}calendar-order' => $issetdef('apple:order'),
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href($sourcePath, false)
        ];

        $this->server->createCollection($homePath . '/' . $jsonData->id, new \Sabre\DAV\MkCol($rt, $props));

        return [201, null];
    }

    function changeCalendarProperties($nodePath, $node, $jsonData) {
        $propnameMap = [
            'dav:name' => '{DAV:}displayname',
            'dav:getetag' => '{DAV:}getetag',
            'caldav:description' => '{urn:ietf:params:xml:ns:caldav}calendar-description',
            'apple:color' => '{http://apple.com/ns/ical/}calendar-color',
            'apple:order' => '{http://apple.com/ns/ical/}calendar-order'
        ];

        $davProps = [];
        foreach ($jsonData as $jsonProp => $value) {
            if (isset($propnameMap[$jsonProp])) {
                $davProps[$propnameMap[$jsonProp]] = $value;
            }
        }

        $result = $this->server->updateProperties($nodePath, $davProps);

        $returncode = 204;
        foreach ($result as $prop => $code) {
            if ((int)$code > 299) {
                $returncode = (int)$code;
                break;
            }
        }

        return [$returncode, null];
    }

    function changeSubscriptionProperties($nodePath, $node, $jsonData) {
        $returncode = 204;
        $davProps = [];
        $propnameMap = [
            'dav:name' => '{DAV:}displayname',
            'apple:color' => '{http://apple.com/ns/ical/}calendar-color',
            'apple:order' => '{http://apple.com/ns/ical/}calendar-order'
        ];

        foreach ($jsonData as $jsonProp => $value) {
            if (isset($propnameMap[$jsonProp])) {
                $davProps[$propnameMap[$jsonProp]] = $value;
            }
        }

        $result = $this->server->updateProperties($nodePath, $davProps);

        foreach ($result as $prop => $code) {
            if ((int)$code > 299) {
                $returncode = (int)$code;
                break;
            }
        }

        return [$returncode, null];
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

    function listCalendarHomes($nodePath, $node, $withRights, $calendarTypeOptions) {
        $homes = $node->getChildren();
        $baseUri = $this->server->getBaseUri();

        $items = [];
        foreach ($homes as $home) {
            $noderef = $nodePath . '/' . $home->getName();
            list($code, $result) = $this->listCalendars($noderef, $home, $withRights, $calendarTypeOptions);
            if (!empty($result)) {
                $items[] = $result;
            }
        }

        $requestPath = $baseUri . $nodePath . '.json';
        $result = [
            '_links' => [
              'self' => [ 'href' => $requestPath ]
            ],
            '_embedded' => [ 'dav:home' => $items ]
        ];

        return [200, $result];
    }

    function listCalendars($nodePath, $node, $withRights, $calendarTypeOptions, $sharedPublic = false, $withFreeBusy = false) {
        $baseUri = $this->server->getBaseUri();

        if ($sharedPublic) {
            $items = $this->listPublicCalendars($nodePath, $node, $withRights);
        } else {
            $items = $this->listAllCalendarsWithReadRight($nodePath, $node, $withRights, $calendarTypeOptions, $withFreeBusy);
        }

        $requestPath = $baseUri . $nodePath . '.json';
        $result = [];
        if (!empty($items)) {
            $result = [
                '_links' => [
                    'self' => [ 'href' => $requestPath ]
                ],
                '_embedded' => [ 'dav:calendar' => $items ]
            ];
        }

        return [200, $result];

    }

    function listAllCalendarsWithReadRight($nodePath, $node, $withRights, $calendarTypeOptions, $withFreeBusy) {
        $right = $withFreeBusy ? '{' . Plugin::NS_CALDAV . '}read-free-busy' : '{DAV:}read';

        $calendars = $node->getChildren();

        $items = [];
        foreach ($calendars as $calendar) {
            if ($calendar instanceof \Sabre\CalDAV\Calendar) {
                if ($this->server->getPlugin('acl')->checkPrivileges($nodePath . '/' . $calendar->getName(), $right, \Sabre\DAVACL\Plugin::R_PARENT, false) &&
                  ($calendar instanceof \ESN\CalDAV\SharedCalendar)) {
                    //Personnal Calendars
                    if (!$calendar->isSharedInstance() && !empty($calendarTypeOptions['includePersonal'])) {
                        $items[] = $this->calendarToJson($nodePath . '/' . $calendar->getName(), $calendar, $withRights);
                    }

                    //Shared Calendars
                    if ($calendar->isSharedInstance() && !empty($calendarTypeOptions['includeShared']) && (!isset($calendarTypeOptions['sharedDelegationStatus']) || $calendar->getInviteStatus() === $calendarTypeOptions['sharedDelegationStatus'] )) {
                        $items[] = $this->calendarToJson($nodePath . '/' . $calendar->getName(), $calendar, $withRights);
                    }
                }
            }

            // Subscriptions
            if ($calendar instanceof \Sabre\CalDAV\Subscriptions\Subscription && !empty($calendarTypeOptions['includeSharedPublicSubscription'])) {
                if ($this->server->getPlugin('acl')->checkPrivileges($nodePath . '/' . $calendar->getName(), $right, \Sabre\DAVACL\Plugin::R_PARENT, false)) {
                    $subscription = $this->subscriptionToJson($nodePath . '/' . $calendar->getName(), $calendar, $withRights);

                    if(isset($subscription)) {
                        $items[] = $subscription;
                    }
                }
            }
        }

        return $items;

    }

    function listAllPersonalCalendars($calendarHomeNode) {
        $calendars = $calendarHomeNode->getChildren();

        $personalCalendars = [];
        foreach ($calendars as $calendar) {
            if ($calendar instanceof \ESN\CalDAV\SharedCalendar) {
                if (!$calendar->isSharedInstance()) {
                    $personalCalendars[] = $calendar->getName();
                }
            }
        }

        return $personalCalendars;
    }

    function listPublicCalendars($nodePath, $node, $withRights = null) {
        $calendars = $node->getChildren();

        $items = [];
        foreach ($calendars as $calendar) {
            if ($calendar instanceof \ESN\CalDAV\SharedCalendar && !$calendar->isSharedInstance() && $calendar->isPublic()) {
                $items[] = $this->calendarToJson($nodePath . '/' . $calendar->getName(), $calendar, $withRights);
            }
        }

        return $items;

    }

    function getCalendarInformation($nodePath, $node, $withRights) {
        $baseUri = $this->server->getBaseUri();
        $requestPath = $baseUri . $nodePath . '.json';

        return [200, $this->calendarToJson($nodePath, $node, $withRights)];
    }

    function getSubscriptionInformation($nodePath, $node, $withRights) {
        $baseUri = $this->server->getBaseUri();
        $requestPath = $baseUri . $nodePath . '.json';
        $subscription = $this->subscriptionToJson($nodePath, $node, $withRights);

        if(!isset($subscription)) {
            return [404, null];
        }

        return [200, $subscription];
    }

    function calendarToJson($nodePath, $calendar, $withRights = null) {
        $baseUri = $this->server->getBaseUri();
        $calprops = $calendar->getProperties([]);
        $node = $calendar;

        if ($calendar instanceof \ESN\CalDAV\SharedCalendar && $calendar->isSharedInstance()) {
            $calendarid = $calendar->getCalendarId();
            $invites = $calendar->getInvites();

            foreach($invites as $user) {
                if ($user->access == \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER) {
                    $uriExploded = explode('/', $user->principal);
                    $sourceCalendarOwner = $uriExploded[2];
                    $ownerHomePath = '/calendars/' . $sourceCalendarOwner;

                    $myNode = $this->server->tree->getNodeForPath($ownerHomePath);
                    $ownerCalendars = $myNode->getChildren();

                    foreach($ownerCalendars as $ownerCalendar) {
                        if ($ownerCalendar instanceof \ESN\CalDAV\SharedCalendar && $ownerCalendar->getCalendarId() == $calendarid) {
                            $sourceCalendarUri = $ownerCalendar->getName();

                            break 2;
                        }
                    }
                }
            }
        }

        $calendar = [
            '_links' => [
                'self' => [ 'href' => $baseUri . $nodePath . '.json' ],
            ]
        ];

        if (isset($sourceCalendarUri)) {
            $calendar['calendarserver:delegatedsource'] = $baseUri . 'calendars/' . $sourceCalendarOwner . '/' . $sourceCalendarUri . '.json';
        }

        if (isset($calprops['{DAV:}displayname'])) {
            $calendar['dav:name'] = $calprops['{DAV:}displayname'];
        }

        if (isset($calprops['{urn:ietf:params:xml:ns:caldav}calendar-description'])) {
            $calendar['caldav:description'] = $calprops['{urn:ietf:params:xml:ns:caldav}calendar-description'];
        }

        if (isset($calprops['{http://calendarserver.org/ns/}getctag'])) {
            $calendar['calendarserver:ctag'] = $calprops['{http://calendarserver.org/ns/}getctag'];
        }

        if (isset($calprops['{http://apple.com/ns/ical/}calendar-color'])) {
            $calendar['apple:color'] = $calprops['{http://apple.com/ns/ical/}calendar-color'];
        }

        if (isset($calprops['{http://apple.com/ns/ical/}calendar-order'])) {
            $calendar['apple:order'] = $calprops['{http://apple.com/ns/ical/}calendar-order'];
        }

        if ($withRights) {
            if ($node->getInvites()) {
                $calendar['invite'] = $node->getInvites();
            }

            if ($node->getACL()) {
                $calendar['acl'] = $node->getACL();
            }
        }

        return $calendar;
    }

    function subscriptionToJson($nodePath, $subscription, $withRights = null) {
        $baseUri = $this->server->getBaseUri();
        $propertiesList = [
            '{DAV:}displayname',
            '{http://calendarserver.org/ns/}source',
            '{http://apple.com/ns/ical/}calendar-color',
            '{http://apple.com/ns/ical/}calendar-order'
        ];
        $subprops = $subscription->getProperties($propertiesList);
        $node = $subscription;

        $subscription = [
            '_links' => [
                'self' => [ 'href' => $baseUri . $nodePath . '.json' ],
            ]
        ];

        if (isset($subprops['{DAV:}displayname'])) {
            $subscription['dav:name'] = $subprops['{DAV:}displayname'];
        }

        if (isset($subprops['{http://calendarserver.org/ns/}source'])) {
            $sourcePath = $subprops['{http://calendarserver.org/ns/}source']->getHref();

            if (!$this->server->tree->nodeExists($sourcePath)) {
                return null;
            }

            $sourceNode = $this->server->tree->getNodeForPath($sourcePath);
            $subscription['calendarserver:source'] = $this->calendarToJson($sourcePath, $sourceNode, true);
        }

        if (isset($subprops['{http://apple.com/ns/ical/}calendar-color'])) {
            $subscription['apple:color'] = $subprops['{http://apple.com/ns/ical/}calendar-color'];
        }

        if (isset($subprops['{http://apple.com/ns/ical/}calendar-order'])) {
            $subscription['apple:order'] = $subprops['{http://apple.com/ns/ical/}calendar-order'];
        }

        if ($withRights) {
            if ($node->getACL()) {
                $subscription['acl'] = $node->getACL();
            }
        }

        return $subscription;
    }

    function queryCalendarObjects($nodePath, $node, $jsonData) {
        if (!isset($jsonData) || !isset($jsonData->scope) ||
            !isset($jsonData->scope->calendars)) {
            return [400, null];
        }

        $calendars = $jsonData->scope->calendars;
        $baseUri = $this->server->getBaseUri();
        $baseUriLen = strlen($baseUri);
        $items = [];

        foreach ($calendars as $calendarPath) {
            if (substr($calendarPath, 0, $baseUriLen) == $baseUri) {
                $calendarPath = substr($calendarPath, $baseUriLen);
            }

            if (substr($calendarPath, -5) == '.json') {
                $calendarPath = substr($calendarPath, 0, -5);
            }

            $node = $this->server->tree->getNodeForPath($calendarPath);

            if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
                $calendar = $this->calendarToJson($calendarPath, $node);
                list($code, $calendarObjects) = $this->getCalendarObjects($calendarPath, $node, $jsonData);
                $calendar['_embedded'] = [
                    'dav:item' => $calendarObjects['_embedded']['dav:item']
                ];
                $items[] = $calendar;
            }
        }

        $requestPath = $this->server->getBaseUri() . $nodePath . '.json';
        $result = [
            '_links' => [
              'self' => [ 'href' => $requestPath ]
            ],
            '_embedded' => [ 'dav:calendar' => $items ]
        ];

        return [200, $result];
    }

    function getCalendarObjectByUID($nodePath, $node, $jsonData) {
        if (!isset($jsonData->uid)) {
            return [400, null];
        }

        $eventPath = $node->getCalendarObjectByUID($jsonData->uid);

        if (!$eventPath) {
            return [404, null];
        }

        return [200, $this->getMultipleDAVItems($nodePath, $node, [$eventPath])];
    }

    function getMultipleCalendarObjectsFromPaths($nodePath, $jsonData) {
        if (!isset($jsonData->eventPaths)) {
            return [400, null];
        }

        $eventUrisByCalendar = [];
        foreach ($jsonData->eventPaths as $eventPath) {
            list($calendarPath, $eventUri) = Utils::splitEventPath($eventPath);

            if (!$calendarPath) continue;

            if (isset($eventUrisByCalendar[$calendarPath])) {
                $eventUrisByCalendar[$calendarPath][] = $eventUri;
            } else {
                $eventUrisByCalendar[$calendarPath] = [$eventUri];
            }
        }

        $items = [];
        foreach ($eventUrisByCalendar as $calendarPath => $eventUris) {
            $calendarNode = $this->server->tree->getNodeForPath($calendarPath);

            $davItems = $this->getMultipleDAVItems(
                $calendarPath,
                $calendarNode,
                $eventUris
            )['_embedded']['dav:item'];

            foreach ($davItems as $davItem) {
                $items[] = $davItem;
            }
        }

        return [207, [
            '_links' => [
                'self' => [ 'href' => $this->server->getBaseUri() . $nodePath . '.json']
            ],
            '_embedded' => [ 'dav:item' => $items ]
        ]];
    }

    function getCalendarObjectsForSubscription($nodePath, $subscription, $jsonData) {
        $propertiesList = ['{http://calendarserver.org/ns/}source'];
        $subprops = $subscription->getProperties($propertiesList);
        $node = $subscription;

        if (isset($subprops['{http://calendarserver.org/ns/}source'])) {
            $sourcePath = $subprops['{http://calendarserver.org/ns/}source']->getHref();

            if (!$this->server->tree->nodeExists($sourcePath)) {
                return [404, null];
            }

            $sourceNode = $this->server->tree->getNodeForPath($sourcePath);

            return $this->getCalendarObjects($sourcePath, $sourceNode, $jsonData);
        }

        return [404, null];
    }

    function getCalendarObjects($nodePath, $node, $jsonData) {
        if (!isset($jsonData->match) || !isset($jsonData->match->start) ||
            !isset($jsonData->match->end)) {
            return [400, null];
        }

        $start = VObject\DateTimeParser::parseDateTime($jsonData->match->start);
        $end = VObject\DateTimeParser::parseDateTime($jsonData->match->end);
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => $start,
                        'end' => $end,
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        return [200, $this->getMultipleDAVItems($nodePath, $node, $node->calendarQuery($filters), $start, $end)];
    }

    private function getMultipleDAVItems($parentNodePath, $parentNode, $calendarObjectUris, $start = false, $end = false) {
        $baseUri = $this->server->getBaseUri();
        $props = [ '{' . self::NS_CALDAV . '}calendar-data', '{DAV:}getetag' ];

        $paths = [];
        foreach ($calendarObjectUris as $calendarObjectUri) {
            $paths[] = $parentNodePath . '/' . $calendarObjectUri;
        }

        $propertyList = [];
        foreach ($this->server->getPropertiesForMultiplePaths($paths, $props) as $objProps) {
            $vObject = VObject\Reader::read($objProps[200][$props[0]]);

            // If we have start and end date, we're getting an expanded list of occurrences between these dates
            if ($start && $end) {
                $vObject = $vObject->expand($start, $end);

                // Sabre's VObject doesn't return the RECURRENCE-ID in the first
                // occurrence, we'll need to do this ourselves. We take advantage
                // of the fact that the object getter for VEVENT will always return
                // the first one.
                $vevent = $vObject->VEVENT;

                // This hack is to fix the prod. We need to investigate more about this bug
                if (!is_object($vevent)) {
                    error_log('/!\ vevent is not an object');

                    continue;
                }

                if (!!$vevent->RRULE && !$vevent->{'RECURRENCE-ID'}) {
                    $recurid = clone $vevent->DTSTART;
                    $recurid->name = 'RECURRENCE-ID';
                    $vevent->add($recurid);
                }
            }

            $vevent = Utils::hidePrivateEventInfoForUser($vObject, $parentNode, $this->currentUser);
            $objProps[200][$props[0]] = $vObject->jsonSerialize();

            $propertyList[] = $objProps;
        }

        return [
            '_links' => [
                'self' => [ 'href' => $baseUri . $parentNodePath . '.json']
            ],
            '_embedded' => [
                'dav:item' => Utils::generateJSONMultiStatus([
                    'fileProperties' => $propertyList,
                    'dataKey' => $props[0],
                    'baseUri' => $baseUri
                ])
            ]
        ];
    }

    function handleJsonRequest($path, $node, $jsonData) {
        if (isset($jsonData->{'invite-reply'})) {
            return $this->updateInviteStatus($path, $node, $jsonData);
        } else if (isset($jsonData->share)) {
            return $this->updateSharees($path, $node, $jsonData);
        }

        return [400, null];
    }

    function updateInviteStatus($path, $node, $jsonData) {
        if(isset($jsonData->{'invite-reply'}->invitestatus)) {
            switch ($jsonData->{'invite-reply'}->{'invitestatus'}) {
                case 'accepted':
                    $inviteStatus = \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED;
                    break;
                case 'noresponse':
                    $inviteStatus = \ESN\DAV\Sharing\Plugin::INVITE_NORESPONSE;
            }

            if (isset($inviteStatus)) {
                $node->updateInviteStatus($inviteStatus);

                // see vendor/sabre/dav/lib/CalDAV/SharingPlugin.php:268
                $this->server->httpResponse->setHeader('X-Sabre-Status', 'everything-went-well');

                return [200, null];
            }
        }

        return [400, null];
    }

    function updateSharees($path, $node, $jsonData) {
        $sharingPlugin = $this->server->getPlugin('sharing');
        $sharees = [];

        if (isset($jsonData->share->set)) {
            foreach ($jsonData->share->set as $sharee) {
                $properties = [];
                if (isset($sharee->{'common-name'})) {
                    $properties['{DAV:}displayname'] = $sharee->{'common-name'};
                }

                if(isset($sharee->{'dav:administration'})) {
                    $access = \ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION;
                } else if (isset($sharee->{'dav:read-write'})) {
                    $access = \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE;
                } else if (isset($sharee->{'dav:read'})) {
                    $access = \Sabre\DAV\Sharing\Plugin::ACCESS_READ;
                } else if (isset($sharee->{'dav:freebusy'})) {
                    $access = \ESN\DAV\Sharing\Plugin::ACCESS_FREEBUSY;
                }

                $sharees[] = new \Sabre\DAV\Xml\Element\Sharee([
                    'href'       => $sharee->{'dav:href'},
                    'properties' => $properties,
                    'access'     => $access,
                    'comment'    => isset($sharee->summary) ? $sharee->summary : null
                ]);
            }
        }

        if (isset($jsonData->share->remove)) {
            foreach ($jsonData->share->remove as $sharee) {
                $sharees[] = new \Sabre\DAV\Xml\Element\Sharee([
                    'href'   => $sharee->{'dav:href'},
                    'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS
                ]);
            }
        }

        $sharingPlugin->shareResource($path, $sharees);

        // see vendor/sabre/dav/lib/CalDAV/SharingPlugin.php:268
        $this->server->httpResponse->setHeader('X-Sabre-Status', 'everything-went-well');

        return [200, null];
    }

    function changePublicRights($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        //this is a very simplified version of Sabre\DAVACL\Plugin#httpacl function
        //here we do not consider a normal acl payload but only a json formatted like {public_right: aprivilege}
        //if the request is not 400 we need to store this info inside the calendarinstance node (i.e. $node->savePublicRight)
        //the info is then available through node->getACL() alongside hardcoded acls

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        if ($node instanceof \ESN\CalDAV\SharedCalendar) {
            $jsonData = json_decode($request->getBodyAsString());

            if (!isset($jsonData->public_right)) {
                throw new DAV\Exception\BadRequest('JSON body expected in ACL request');
            }

            $supportedPrivileges = $this->server->getPlugin('acl')->getFlatPrivilegeSet($node);
            $supportedPrivileges[""] = "Private";
            if (!isset($supportedPrivileges[$jsonData->public_right])) {
                throw new \Sabre\DAVACL\Exception\NotSupportedPrivilege('The privilege you specified (' . $jsonData->public_right . ') is not recognized by this server');
            }

            $node->savePublicRight($jsonData->public_right);

            $this->send(200, $node->getACL());
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

    private function assertRecipientIsConcernedByEvent($vevent, $recipient) {
        $isConcerned = false;
        try {
            $organizer = (string)$vevent->ORGANIZER;
            if (strtolower($organizer) === strtolower($recipient)) {
                $isConcerned = true;
            }
        } catch (Exception $e) {
            error_log("Error while trying to fetch event organizer: ".(string)$e);
        }
        if ($vevent->ATTENDEE) {
            foreach($vevent->ATTENDEE as $attendee) {
                if (strtolower((string)$attendee) === strtolower($recipient)) {
                    $isConcerned = true;
                    break 1;
                }
            }
        }

        return $isConcerned;
    }
}
