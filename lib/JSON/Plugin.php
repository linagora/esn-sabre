<?php

namespace ESN\JSON;

use
    \Sabre\VObject,
    \Sabre\DAV;

class Plugin extends \Sabre\CalDAV\Plugin {

    function __construct($root) {
        $this->root = $root;
    }

    function initialize(DAV\Server $server) {
        $this->server = $server;
        $server->on('method:POST', [$this, 'post'], 80);
        $server->on('method:GET', [$this, 'get'], 80);
    }

    function post($request, $response) {
        $path = $request->getPath();
        if (substr($path, -5) == ".json") {
            $nodePath = substr($path, 0, -5);
            $jsonData = json_decode($request->getBodyAsString());
            $code = null;
            $body = null;
            if ($nodePath == "query") {
                list($code, $body) = $this->queryCalendarObjects($nodePath, null, $jsonData);
            } else {
                $node = $this->server->tree->getNodeForPath($nodePath);
                if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
                    list($code, $body) = $this->getCalendarObjects($nodePath, $node, $jsonData);
                } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
                    list($code, $body) = $this->createCalendar($nodePath, $node, $jsonData);
                } else if ($node instanceof \Sabre\CardDAV\AddressBookHome) {
                    list($code, $body) = $this->createAddressBook($node, $jsonData);
                }
            }

            if ($code) {
                if ($body) {
                    $this->server->httpResponse->setHeader("Content-Type","application/json; charset=utf-8");
                    $this->server->httpResponse->setBody(json_encode($body));
                }
                $this->server->httpResponse->setStatus($code);
                return false;
            }
        }
        return true;
    }

    function get($request, $response) {
        $path = $request->getPath();
        if (substr($path, -5) == ".json") {
            $nodePath = substr($path, 0, -5);
            $node = $this->server->tree->getNodeForPath($nodePath);

            $code = null;
            $body = null;

            if ($node instanceof \ESN\CalDAV\CalendarRoot) {
                list($code, $body) = $this->listCalendarRoot($nodePath, $node);
            } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
                list($code, $body) = $this->listCalendarHome($nodePath, $node);
            } else if ($node instanceof \Sabre\CardDAV\AddressBook) {
                list($code, $body) = $this->getContacts($request, $response, $nodePath, $node);
            }

            if ($code) {
                if ($body) {
                    $this->server->httpResponse->setHeader("Content-Type","application/json; charset=utf-8");
                    $this->server->httpResponse->setBody(json_encode($body));
                }
                $this->server->httpResponse->setStatus($code);
                return false;
            }
        }
        return true;
    }

    function createCalendar($nodePath, $node, $jsonData) {
        $issetdef = function($key, $default=null) use ($jsonData) {
            return isset($jsonData->{$key}) ? $jsonData->{$key} : $default;
        };

        if (!isset($jsonData->id) || !$jsonData->id) {
            return [400, null];
        }

        $rt = ['{DAV:}collection', '{urn:ietf:params:xml:ns:caldav}calendar'];
        $props = [
            "{DAV:}displayname" => $issetdef("dav:name"),
            "{urn:ietf:params:xml:ns:caldav}calendar-description" => $issetdef("caldav:description"),
            "{http://apple.com/ns/ical/}calendar-color" => $issetdef("apple:color"),
            "{http://apple.com/ns/ical/}calendar-order" => $issetdef("apple:order")
        ];
        $node->createExtendedCollection($jsonData->id, new \Sabre\DAV\MkCol($rt, $props));
        return [201, null];
    }

    function createAddressbook($node, $jsonData) {
        $issetdef = function($key, $default=null) use ($jsonData) {
             return isset($jsonData->{$key}) ? $jsonData->{$key} : $default;
        };

        if (!isset($jsonData->id) || !$jsonData->id) {
            return [400, null];
        }

        $rt = ['{DAV:}collection', '{urn:ietf:params:xml:ns:carddav}addressbook'];
        $props = [
            "{DAV:}displayname" => $issetdef("dav:name"),
            "{urn:ietf:params:xml:ns:carddav}addressbook-description" => $issetdef("carddav:description")
        ];
        $node->createExtendedCollection($jsonData->id, new \Sabre\DAV\MkCol($rt, $props));
        return [201, null];
    }

    function listCalendarRoot($nodePath, $node) {
        $homes = $node->getChildren();
        $baseUri = $this->server->getBaseUri();

        $items = [];
        foreach ($homes as $home) {
            if ($home instanceof \Sabre\CalDAV\CalendarHome) {
                $noderef = $nodePath . "/" . $home->getName();
                list($code, $result) = $this->listCalendarHome($noderef, $home);
                $items[] = $result;
            }
        }

        $requestPath = $baseUri . $nodePath . ".json";
        $result = [
            "_links" => [
              "self" => [ "href" => $requestPath ]
            ],
            "_embedded" => [ "dav:home" => $items ]
        ];

        return [200, $result];
    }

    function listCalendarHome($nodePath, $node) {
        $calendars = $node->getChildren();
        $baseUri = $this->server->getBaseUri();

        $items = [];
        foreach ($calendars as $calendar) {
            if ($calendar instanceof \Sabre\CalDAV\Calendar) {
                $items[] = $this->listCalendar($nodePath . "/" . $calendar->getName(), $calendar);
            }
        }


        $requestPath = $baseUri . $nodePath . ".json";
        $result = [
            "_links" => [
              "self" => [ "href" => $requestPath ]
            ],
            "_embedded" => [ "dav:calendar" => $items ]
        ];

        return [200, $result];
    }

    function listCalendar($nodePath, $calendar) {
        $baseUri = $this->server->getBaseUri();
        $calprops = $calendar->getProperties([]);
        return [
            "_links" => [
                "self" => [ "href" => $baseUri . $nodePath . ".json" ],
            ],
            "dav:name" => $calprops["{DAV:}displayname"],
            "caldav:description" => $calprops["{urn:ietf:params:xml:ns:caldav}calendar-description"],
            "calendarserver:ctag" => $calprops["{http://calendarserver.org/ns/}getctag"],
            "apple:color" => $calprops["{http://apple.com/ns/ical/}calendar-color"],
            "apple:order" => $calprops["{http://apple.com/ns/ical/}calendar-order"]
        ];
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
            if (substr($calendarPath, -5) == ".json") {
                $calendarPath = substr($calendarPath, 0, -5);
            }
            $node = $this->server->tree->getNodeForPath($calendarPath);
            if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
                $calendar = $this->listCalendar($calendarPath, $node);
                list($code, $calendarObjects) = $this->getCalendarObjects($calendarPath, $node, $jsonData);
                $calendar["_embedded"] = [
                    "dav:item" => $calendarObjects["_embedded"]["dav:item"]
                ];
                $items[] = $calendar;
            }
        }

        $requestPath = $this->server->getBaseUri() . $nodePath . ".json";
        $result = [
            "_links" => [
              "self" => [ "href" => $requestPath ]
            ],
            "_embedded" => [ "dav:calendar" => $items ]
        ];
        return [200, $result];
    }

    function getCalendarObjects($nodePath, $node, $jsonData) {
        if (!isset($jsonData->match) || !isset($jsonData->match->start) ||
            !isset($jsonData->match->end)) {
            return [400, null];
        }

        $start = $jsonData->match->start;
        $end = $jsonData->match->end;

        $start = VObject\DateTimeParser::parseDateTime($start);
        $end = VObject\DateTimeParser::parseDateTime($end);
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
        $nodePaths = $node->calendarQuery($filters);
        $baseUri = $this->server->getBaseUri();

        $props = [ '{' . self::NS_CALDAV . '}calendar-data', '{DAV:}getetag' ];

        $items = [];
        foreach ($nodePaths as $path) {
            list($properties) =
                $this->server->getPropertiesForPath($nodePath . "/" . $path, $props);

            $vObject = VObject\Reader::read($properties[200]['{' . self::NS_CALDAV . '}calendar-data']);

            $isRecurring = !!$vObject->VEVENT->RRULE;
            $vObject->expand($start, $end);

            // Sabre's VObject doesn't return the RECURRENCE-ID in the first
            // occurrence, we'll need to do this ourselves. We take advantage
            // of the fact that the object getter for VEVENT will always return
            // the first one.
            $vevent = $vObject->VEVENT;
            if ($isRecurring && !$vevent->{'RECURRENCE-ID'}) {
                $recurid = clone $vevent->DTSTART;
                $recurid->name = 'RECURRENCE-ID';
                $vevent->add($recurid);
            }

            $items[] = [
                "_links" => [
                  "self" => [ "href" =>  $baseUri . $properties["href"] ]
                ],
                "etag" => $properties[200]['{DAV:}getetag'],
                "data" => $vObject->jsonSerialize()
            ];
        }

        $requestPath = $this->server->getBaseUri() . $nodePath . '.json';
        $result = [
            "_links" => [
              "self" => [ "href" => $requestPath ]
            ],
            "_embedded" => [ "dav:item" => $items ]
        ];

        return [200, $result];
    }

    function getContacts($request, $response, $nodePath, $node) {
        $queryParams = $request->getQueryParameters();
        $offset = isset($queryParams['offset']) ? $queryParams['offset'] : 0;
        $limit = isset($queryParams['limit']) ? $queryParams['limit'] : 0;
        $sort = isset($queryParams['sort']) ? $queryParams['sort'] : null;

        $cards = $node->getChildren($offset, $limit, $sort);
        $count = $node->getChildCount();

        $items = [];
        $baseUri = $this->server->getBaseUri();
        foreach ($cards as $card) {
            $vobj = VObject\Reader::read($card->get());
            $cardItem = [
                '_links' => [
                  "self" => [ "href" =>  $baseUri . $nodePath . "/" . $card->getName() ]
                ],
                "etag" => $card->getETag(),
                "data" => $vobj->jsonSerialize()
            ];
            $items[] = $cardItem;
        }

        $requestPath = $baseUri . $request->getPath();

        $result = [
            "_links" => [
                "self" => [ "href" => $requestPath ]
            ],
            "dav:syncToken" => $node->getSyncToken(),
            "_embedded" => [ 'dav:item' => $items ]
        ];

        if ($limit > 0 && ($offset + $limit < $count)) {
            $queryParams['offset'] = $offset + $limit;
            $href = $requestPath . "?" . http_build_query($queryParams);
            $result['_links']['next'] = [ 'href' => $href ];
        }

        return [200, $result];
    }
}
