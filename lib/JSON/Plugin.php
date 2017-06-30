<?php

namespace ESN\JSON;

use \Sabre\VObject,
    \Sabre\DAV;
use Sabre\VObject\ITip\Message;

class Plugin extends \Sabre\CalDAV\Plugin {

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
        $server->on('method:DELETE', [$this, 'delete'], 80);
        $server->on('method:PROPPATCH', [$this, 'proppatch'], 80);
        $server->on('method:PROPFIND', [$this, 'findProperties'], 80);
        $server->on('method:ITIP', [$this, 'itip'], 80);
        $server->on('method:ACL', [$this, 'changePublicRights'], 80);
    }

    function beforeMethod($request, $response) {
        $url = $request->getUrl();
        if (strpos($url, '.json') !== false) {
            $url = str_replace('.json','', $url);
        }
        $this->acceptHeader = explode(', ', $request->getHeader('Accept'));
        $request->setUrl($url);

        $this->currentUser = $this->server->getPlugin('auth')->getCurrentPrincipal();

        return true;
    }

    function beforeUnbind($path) {
        return $this->checkModificationsRights($this->server->tree->getNodeForPath($path));
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        return $this->checkModificationsRights($node);
    }

    function checkModificationsRights(\Sabre\DAV\IFile $node) {
        if ($node instanceof \Sabre\CalDAV\ICalendarObject) {
            $vcalendar = VObject\Reader::read($node->get());
            if ($this->isHiddenPrivateEvent($vcalendar->VEVENT, $node)) {
                throw new DAV\Exception\Forbidden('You can not modify private events you do not own');
            }
        }
        return true;
    }

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
        $message->sender = 'mailto:' . $payload->sender;
        $message->recipient = 'mailto:' . $payload->recipient;
        $message->message = VObject\Reader::read($payload->ical);

        $this->server->getPlugin('caldav-schedule')->scheduleLocalDelivery($message);
        $this->server->emit('itip', [$message]);

        return $this->send(204, null);
    }

    function httpReport($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $code = null;
        $body = null;
        $path = $request->getPath();
        $jsonData = json_decode($request->getBodyAsString());

        $node = $this->server->tree->getNodeForPath($path);

        if ($node instanceof \Sabre\CalDAV\CalendarHome) {
            list($code, $body) = $this->getCalendarObjectByUID($path, $node, $jsonData);
        } else if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
            list($code, $body) = $this->getCalendarObjects($path, $node, $jsonData);
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
        $jsonData = json_decode($request->getBodyAsString());
        $code = null;
        $body = null;

        if ($path == 'query') {
            list($code, $body) = $this->queryCalendarObjects($path, null, $jsonData);
        } else {
            $node = $this->server->tree->getNodeForPath($path);
            if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
                list($code, $body) = $this->updateSharees($path, $node, $jsonData);
            } else if ($node instanceof \Sabre\CalDAV\CalendarHome && $this->isBodyForSubscription($jsonData)) {
                list($code, $body) = $this->createSubscription($path, $node, $jsonData);
            } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
                list($code, $body) = $this->createCalendar($path, $node, $jsonData);
            } else if ($node instanceof \Sabre\CardDAV\AddressBookHome) {
                list($code, $body) = $this->createAddressBook($node, $jsonData);
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
        $withRights = $this->getWithRightsParameter($request);
        $public = $this->getPublicParameter($request);

        if ($node instanceof \ESN\CalDAV\CalendarRoot) {
            list($code, $body) = $this->listCalendarRoot($path, $node, $withRights);
        } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
            list($code, $body) = $this->listCalendarHome($path, $node, $withRights, $public);
        } else if ($node instanceof \Sabre\CalDAV\Calendar) {
            list($code, $body) = $this->getCalendarInformation($path, $node, $withRights);
        } else if ($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            list($code, $body) = $this->getSubscriptionInformation($path, $node, $withRights);
        } else if ($node instanceof \Sabre\CardDAV\AddressBookHome) {
            list($code, $body) = $this->getAddressBooks($path, $node);
        } else if ($node instanceof \Sabre\CardDAV\AddressBook) {
            list($code, $body) = $this->getContacts($request, $response, $path, $node);
        }

        return $this->send($code, $body);

    }

    private function getWithRightsParameter($request) {
        $queryParams = $request->getQueryParameters();
        return isset($queryParams['withRights']) && $queryParams['withRights'] === 'true' ;

    }

    private function getPublicParameter($request) {
        $queryParams = $request->getQueryParameters();
        return isset($queryParams['public']) && $queryParams['public'] === 'true' ;

    }

    function delete($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        $code = null;
        $body = null;

        if ($node instanceof \Sabre\CalDAV\Calendar) {
            list($code, $body) = $this->deleteNode($path, $node);
        }

        if ($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            list($code, $body) = $this->deleteSubscription($node);
        }

        return $this->send($code, $body);
    }

    function proppatch($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);
        $jsonData = json_decode($request->getBodyAsString());

        $code = null;
        $body = null;

        if ($node instanceof \Sabre\CalDAV\Calendar) {
            list($code, $body) = $this->changeCalendarProperties($path, $node, $jsonData);
        }

        if ($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            list($code, $body) = $this->changeSubscriptionProperties($path, $node, $jsonData);
        }

        return $this->send($code, $body);

    }

    function createCalendar($nodePath, $node, $jsonData) {
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

        $node->createExtendedCollection($jsonData->id, new \Sabre\DAV\MkCol($rt, $props));

        return [201, null];
    }

    function createSubscription($nodePath, $node, $jsonData) {
        $issetdef = $this->propertyOrDefault($jsonData);

        if (!isset($jsonData->id) || !$jsonData->id) {
            return [400, null];
        }

        $rt = ['{DAV:}collection', '{http://calendarserver.org/ns/}subscribed'];
        $props = [
            '{DAV:}displayname' => $issetdef('dav:name'),
            '{http://apple.com/ns/ical/}calendar-color' => $issetdef('apple:color'),
            '{http://apple.com/ns/ical/}calendar-order' => $issetdef('apple:order'),
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href($issetdef('calendarserver:source')->href, false)
        ];

        $node->createExtendedCollection($jsonData->id, new \Sabre\DAV\MkCol($rt, $props));

        return [201, null];
    }

    function deleteNode($nodePath, $node) {
        if ($node->getName() === \ESN\CalDAV\Backend\Esn::EVENTS_URI) {
            return [403, [
                'status' => 403,
                'message' => 'Forbidden: You can not delete your main calendar'
            ]];
        }

        $this->server->tree->delete($nodePath);
        return [204, null];
    }

    function deleteSubscription($node) {
        $node->delete();

        return [204, null];
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

    function createAddressBook($node, $jsonData) {
        $issetdef = $this->propertyOrDefault($jsonData);

        if (!isset($jsonData->id) || !$jsonData->id) {
            return [400, null];
        }

        $rt = ['{DAV:}collection', '{urn:ietf:params:xml:ns:carddav}addressbook'];
        $props = [
            '{DAV:}displayname' => $issetdef('dav:name'),
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => $issetdef('carddav:description'),
            '{DAV:}acl' => $issetdef('dav:acl'),
            '{http://open-paas.org/contacts}type' => $issetdef('type')
        ];

        $node->createExtendedCollection($jsonData->id, new \Sabre\DAV\MkCol($rt, $props));

        return [201, null];
    }

    function findProperties($request) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $code = null;
        $body = null;
        $node = $this->server->tree->getNodeForPath($path);
        $jsonData = json_decode($request->getBodyAsString(), true);

        if ($node instanceof \Sabre\CardDAV\AddressBook) {
            if ($node->getProperties($jsonData['properties'])) {
                $this->server->httpResponse->setHeader('Content-Type','application/json; charset=utf-8');
                $this->server->httpResponse->setBody(json_encode($node->getProperties($jsonData['properties'])));
            }
            $this->server->httpResponse->setStatus(200);
            return false;
        }
        else if ($node instanceof \Sabre\CalDAV\SharedCalendar) {
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


    function listCalendarRoot($nodePath, $node, $withRights = null) {
        $homes = $node->getChildren();
        $baseUri = $this->server->getBaseUri();

        $items = [];
        foreach ($homes as $home) {
            if ($home instanceof \Sabre\CalDAV\CalendarHome) {
                $noderef = $nodePath . '/' . $home->getName();
                list($code, $result) = $this->listCalendarHome($noderef, $home, $withRights);
                if (!empty($result)) {
                    $items[] = $result;
                }
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

    function listCalendarHome($nodePath, $node, $withRights = null, $public = false) {
        $calendars = $node->getChildren();
        $baseUri = $this->server->getBaseUri();

        if ($public) {
            $items = $this->listPublicCalendars($nodePath, $node, $withRights);
        } else {
            $items = $this->listAllCalendarsWithReadRight($nodePath, $node, $withRights);
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

    function listAllCalendarsWithReadRight($nodePath, $node, $withRights = null) {
        $calendars = $node->getChildren();
        $baseUri = $this->server->getBaseUri();

        $items = [];
        foreach ($calendars as $calendar) {
            if ($calendar instanceof \Sabre\CalDAV\Calendar) {
                if ($this->server->getPlugin('acl')->checkPrivileges($nodePath . '/' . $calendar->getName(), '{DAV:}read', \Sabre\DAVACL\Plugin::R_PARENT, false)) {
                    $items[] = $this->listCalendar($nodePath . '/' . $calendar->getName(), $calendar, $withRights);
                }
            }

            if ($calendar instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
                if ($this->server->getPlugin('acl')->checkPrivileges($nodePath . '/' . $calendar->getName(), '{DAV:}read', \Sabre\DAVACL\Plugin::R_PARENT, false)) {
                    $subscription = $this->listSubscription($nodePath . '/' . $calendar->getName(), $calendar, $withRights);

                    if(isset($subscription)) {
                        $items[] = $subscription;
                    }
                }
            }
        }

        return $items;

    }

    function listPublicCalendars($nodePath, $node, $withRights = null) {
        $calendars = $node->getChildren();

        $items = [];
        foreach ($calendars as $calendar) {
            if ($calendar instanceof \ESN\CalDAV\SharedCalendar && !$calendar->isSharedInstance() && $calendar->isPublic()) {
                $items[] = $this->listCalendar($nodePath . '/' . $calendar->getName(), $calendar, $withRights);
            }
        }

        return $items;

    }

    function getCalendarInformation($nodePath, $node, $withRights) {
        $baseUri = $this->server->getBaseUri();
        $requestPath = $baseUri . $nodePath . '.json';

        return [200, $this->listCalendar($nodePath, $node, $withRights)];
    }

    function getSubscriptionInformation($nodePath, $node, $withRights) {
        $baseUri = $this->server->getBaseUri();
        $requestPath = $baseUri . $nodePath . '.json';
        $subscription = $this->listSubscription($nodePath, $node, $withRights);

        if(!isset($subscription)) {
            return [404, null];
        }

        return [200, $subscription];
    }

    function listCalendar($nodePath, $calendar, $withRights = null) {
        $baseUri = $this->server->getBaseUri();
        $calprops = $calendar->getProperties([]);
        $node = $calendar;

        $calendar = [
            '_links' => [
                'self' => [ 'href' => $baseUri . $nodePath . '.json' ],
            ]
        ];

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

    function listSubscription($nodePath, $subscription, $withRights = null) {
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
            $sourcePath = $this->server->calculateUri($subprops['{http://calendarserver.org/ns/}source']->getHref());

            if (substr($sourcePath, -5) == '.json') {
                $sourcePath = substr($sourcePath, 0, -5);
            }

            if (!$this->server->tree->nodeExists($sourcePath)) {
                return null;
            }

            $sourceNode = $this->server->tree->getNodeForPath($sourcePath);
            $subscription['calendarserver:source'] = $this->listCalendar($sourcePath, $sourceNode, true);
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
                $calendar = $this->listCalendar($calendarPath, $node);
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

    private function getMultipleDAVItems($parentNodePath, $parentNode, $paths, $start = false, $end = false) {
        $items = [];
        $baseUri = $this->server->getBaseUri();
        $props = [ '{' . self::NS_CALDAV . '}calendar-data', '{DAV:}getetag' ];

        foreach ($paths as $path) {
            list($properties) = $this->server->getPropertiesForPath($parentNodePath . '/' . $path, $props);

            $vObject = VObject\Reader::read($properties[200]['{' . self::NS_CALDAV . '}calendar-data']);

            // If we have start and end date, we're getting an expanded list of occurrences between these dates
            if ($start && $end) {
                $vObject = $vObject->expand($start, $end);

                // Sabre's VObject doesn't return the RECURRENCE-ID in the first
                // occurrence, we'll need to do this ourselves. We take advantage
                // of the fact that the object getter for VEVENT will always return
                // the first one.
                $vevent = $vObject->VEVENT;
                if (!!$vevent->RRULE && !$vevent->{'RECURRENCE-ID'}) {
                    $recurid = clone $vevent->DTSTART;
                    $recurid->name = 'RECURRENCE-ID';
                    $vevent->add($recurid);
                }
            }

            $newEvents = array();
            foreach ($vObject->VEVENT as $vevent) {
                if ($this->isHiddenPrivateEvent($vevent, $parentNode)) {
                    $vevent = new \Sabre\VObject\Component\VEvent($vObject, 'VEVENT', [
                      'UID' => $vevent->UID,
                      'SUMMARY' => 'Busy',
                      'CLASS' => 'PRIVATE',
                      'ORGANIZER' => $vevent->ORGANIZER,
                      'DTSTART' => $vevent->DTSTART,
                      'DTEND' => $vevent->DTEND,
                    ]);
                }
                $newEvents[] = $vevent;
            }
            $vObject->remove('VEVENT');
            foreach ($newEvents as &$vevent) {
                $vObject->add($vevent);
            }

            $items[] = [
                '_links' => [
                    'self' => [ 'href' => $baseUri . $properties['href' ] ]
                ],
                'etag' => $properties[200]['{DAV:}getetag'],
                'data' => $vObject->jsonSerialize()
            ];
        }

        return [
            '_links' => [
                'self' => [ 'href' => $baseUri . $parentNodePath . '.json']
            ],
            '_embedded' => [ 'dav:item' => $items ]
        ];
    }

    function isHiddenPrivateEvent($vevent, $node) {
        return $vevent->CLASS == 'PRIVATE' && $node->getOwner() != $this->currentUser;
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
        $jsonData = json_decode($request->getBodyAsString());

        if ($node instanceof \ESN\CalDAV\SharedCalendar) {
            if (!isset($jsonData->public_right)) {
                throw new DAV\Exception\BadRequest('JSON body expected in ACL request');
            }

            $supportedPrivileges = $this->server->getPlugin('acl')->getFlatPrivilegeSet($node);
            if (!isset($supportedPrivileges[$jsonData->public_right])) {
                throw new \Sabre\DAVACL\Exception\NotSupportedPrivilege('The privilege you specified (' . $jsonData->public_right . ') is not recognized by this server');
            }

            $node->savePublicRight($jsonData->public_right);

            $this->send(200, $node->getACL());
            return false;
        }
        return true;
    }

    function getAddressBooks($nodePath, $node) {
        $addressBooks = $node->getChildren();
        $baseUri = $this->server->getBaseUri();

        $items = [];
        foreach ($addressBooks as $addressBook) {
            if ($addressBook instanceof \Sabre\CardDAV\AddressBook) {
                $items[] = $this->getAddressBookDetail($nodePath . '/' . $addressBook->getName(), $addressBook);
            }
        }

        $requestPath = $baseUri . $nodePath . '.json';
        $result = [
            '_links' => [
                'self' => [ 'href' => $requestPath ]
            ],
            '_embedded' => [ 'dav:addressbook' => $items ]
        ];

        return [200, $result];
    }

    function getAddressBookDetail($nodePath, \Sabre\CardDAV\AddressBook $addressBook) {
        $baseUri = $this->server->getBaseUri();
        $bookProps = $addressBook->getProperties(['{DAV:}displayname', '{DAV:}acl', '{http://open-paas.org/contacts}type', '{urn:ietf:params:xml:ns:carddav}addressbook-description']);

        return [
            '_links' => [
                'self' => [ 'href' => $baseUri . $nodePath . '.json' ],
            ],
            'dav:name' => $bookProps['{DAV:}displayname'],
            'carddav:description' => $bookProps['{urn:ietf:params:xml:ns:carddav}addressbook-description'],
            'dav:acl' => $bookProps['{DAV:}acl'],
            'type' => $bookProps['{http://open-paas.org/contacts}type'],
        ];
    }

    function getContacts($request, $response, $nodePath, $node) {
        $queryParams = $request->getQueryParameters();
        $offset = isset($queryParams['offset']) ? $queryParams['offset'] : 0;
        $limit = isset($queryParams['limit']) ? $queryParams['limit'] : 0;
        $sort = isset($queryParams['sort']) ? $queryParams['sort'] : null;
        $modifiedBefore = isset($queryParams['modifiedBefore']) ? (int)$queryParams['modifiedBefore'] : 0;

        $filters = null;
        if ($modifiedBefore > 0) {
            $filters = [
                'modifiedBefore' => $modifiedBefore
            ];
        }

        $cards = $node->getChildren($offset, $limit, $sort, $filters);
        $count = $node->getChildCount();

        $items = [];
        $baseUri = $this->server->getBaseUri();
        foreach ($cards as $card) {
            $vobj = VObject\Reader::read($card->get());
            $cardItem = [
                '_links' => [
                  'self' => [ 'href' =>  $baseUri . $nodePath . '/' . $card->getName() ]
                ],
                'etag' => $card->getETag(),
                'data' => $vobj->jsonSerialize()
            ];
            $items[] = $cardItem;
        }

        $requestPath = $baseUri . $request->getPath() . '.json';

        $result = [
            '_links' => [
                'self' => [ 'href' => $requestPath ]
            ],
            'dav:syncToken' => $node->getSyncToken(),
            '_embedded' => [ 'dav:item' => $items ]
        ];

        if ($limit > 0 && ($offset + $limit < $count)) {
            $queryParams['offset'] = $offset + $limit;
            $href = $requestPath . '?' . http_build_query($queryParams);
            $result['_links']['next'] = [ 'href' => $href ];
        }

        return [200, $result];
    }

    function acceptJson() {
        return in_array('application/calendar+json', $this->acceptHeader) ||
               in_array('application/vcard+json', $this->acceptHeader) ||
               in_array('application/json', $this->acceptHeader);
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
}
