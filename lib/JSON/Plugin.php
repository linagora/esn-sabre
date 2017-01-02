<?php

namespace ESN\JSON;

use \Sabre\VObject,
    \Sabre\DAV;

class Plugin extends \Sabre\CalDAV\Plugin {

    function __construct($root) {
        $this->root = $root;
    }

    function initialize(DAV\Server $server) {
        $this->server = $server;
        $server->on('beforeMethod', [$this, 'beforeMethod'], 15); // 15 is after Auth and before ACL
        $server->on('method:REPORT', [$this, 'httpReport'], 80);
        $server->on('method:POST', [$this, 'post'], 80);
        $server->on('method:GET', [$this, 'get'], 80);
        $server->on('method:DELETE', [$this, 'delete'], 80);
        $server->on('method:PROPPATCH', [$this, 'proppatch'], 80);
        $server->on('method:PROPFIND', [$this, 'findProperties'], 80);
    }

    function beforeMethod($request, $response) {
        $url = $request->getUrl();
        if (strpos($url, ".json") !== false) {
            $url = str_replace(".json","", $url);
        }
        $this->acceptHeader = explode(', ', $request->getHeader("Accept"));
        $request->setUrl($url);
        return true;
    }

    function httpReport($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $jsonData = json_decode($request->getBodyAsString());
        $code = null;
        $body = null;

        $node = $this->server->tree->getNodeForPath($path);
        if (!($node instanceof \Sabre\CalDAV\ICalendarObjectContainer)) {
            return true;
        }

        list($code, $body) = $this->getCalendarObjects($path, $node, $jsonData);

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

        if ($path == "query") {
            list($code, $body) = $this->queryCalendarObjects($path, null, $jsonData);
        } else {
            $node = $this->server->tree->getNodeForPath($path);
            if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
                list($code, $body) = $this->updateSharees($path, $node, $jsonData);
            } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
                list($code, $body) = $this->createCalendar($path, $node, $jsonData);
            } else if ($node instanceof \Sabre\CardDAV\AddressBookHome) {
                list($code, $body) = $this->createAddressBook($node, $jsonData);
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

        if ($node instanceof \ESN\CalDAV\CalendarRoot) {
            list($code, $body) = $this->listCalendarRoot($path, $node);
        } else if ($node instanceof \Sabre\CalDAV\CalendarHome) {
            list($code, $body) = $this->listCalendarHome($path, $node);
        } else if ($node instanceof \Sabre\CalDAV\Calendar) {
            list($code, $body) = $this->getCalendarInformation($path, $node);
        } else if ($node instanceof \Sabre\CardDAV\AddressBookHome) {
            list($code, $body) = $this->getAddressBooks($path, $node);
        } else if ($node instanceof \Sabre\CardDAV\AddressBook) {
            list($code, $body) = $this->getContacts($request, $response, $path, $node);
        }

        return $this->send($code, $body);
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

        return $this->send($code, $body);

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

    function changeCalendarProperties($nodePath, $node, $jsonData) {
        $propnameMap = [
            "dav:name" => "{DAV:}displayname",
            "dav:getetag" => "{DAV:}getetag",
            "caldav:description" => "{urn:ietf:params:xml:ns:caldav}calendar-description",
            "apple:color" => "{http://apple.com/ns/ical/}calendar-color",
            "apple:order" => "{http://apple.com/ns/ical/}calendar-order"
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

    function createAddressBook($node, $jsonData) {
        $issetdef = function($key, $default=null) use ($jsonData) {
             return isset($jsonData->{$key}) ? $jsonData->{$key} : $default;
        };

        if (!isset($jsonData->id) || !$jsonData->id) {
            return [400, null];
        }

        $rt = ['{DAV:}collection', '{urn:ietf:params:xml:ns:carddav}addressbook'];
        $props = [
            "{DAV:}displayname" => $issetdef("dav:name"),
            "{urn:ietf:params:xml:ns:carddav}addressbook-description" => $issetdef("carddav:description"),
            "{DAV:}acl" => $issetdef("dav:acl"),
            "{http://open-paas.org/contacts}type" => $issetdef("type")
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
                $this->server->httpResponse->setHeader("Content-Type","application/json; charset=utf-8");
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

    function getCalendarInformation($nodePath, $node) {
        $baseUri = $this->server->getBaseUri();
        $requestPath = $baseUri . $nodePath . ".json";
        return [200, $this->listCalendar($nodePath, $node)];
    }

    function listCalendar($nodePath, $calendar) {
        $baseUri = $this->server->getBaseUri();
        $calprops = $calendar->getProperties([]);

        $calendar = [
            "_links" => [
                "self" => [ "href" => $baseUri . $nodePath . ".json" ],
            ]
        ];

        if (isset($calprops["{DAV:}displayname"])) {
            $calendar["dav:name"] = $calprops["{DAV:}displayname"];
        }

        if (isset($calprops["{urn:ietf:params:xml:ns:caldav}calendar-description"])) {
            $calendar["caldav:description"] = $calprops["{urn:ietf:params:xml:ns:caldav}calendar-description"];
        }

        if (isset($calprops["{http://calendarserver.org/ns/}getctag"])) {
            $calendar["calendarserver:ctag"] = $calprops["{http://calendarserver.org/ns/}getctag"];
        }

        if (isset($calprops["{http://apple.com/ns/ical/}calendar-color"])) {
            $calendar["apple:color"] = $calprops["{http://apple.com/ns/ical/}calendar-color"];
        }

        if (isset($calprops["{http://apple.com/ns/ical/}calendar-order"])) {
            $calendar["apple:order"] = $calprops["{http://apple.com/ns/ical/}calendar-order"];
        }

        return $calendar;
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
            $vObject = $vObject->expand($start, $end);

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

    function updateSharees($path, $node, $jsonData) {
        $sharingPlugin = $this->server->getPlugin('sharing');
        $sharees = [];

        if (isset($jsonData->share->set)) {
            foreach ($jsonData->share->set as $sharee) {
                $properties = [];
                if (isset($sharee->{"common-name"})) {
                    $properties['{DAV:}displayname'] = $sharee->{"common-name"};
                }
                $access = isset($sharee->{"dav:read-write"})
                    ? \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE
                    : \Sabre\DAV\Sharing\Plugin::ACCESS_READ;
                $sharees[] = new \Sabre\DAV\Xml\Element\Sharee([
                    'href'       => $sharee->{"dav:href"},
                    'properties' => $properties,
                    'access'     => $access,
                    'comment'    => isset($sharee->summary) ? $sharee->summary : null
                ]);
            }
        }

        if (isset($jsonData->share->remove)) {
            foreach ($jsonData->share->remove as $sharee) {
                $sharees[] = new \Sabre\DAV\Xml\Element\Sharee([
                    'href'   => $sharee->{"dav:href"},
                    'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS
                ]);
            }
        }
        $sharingPlugin->shareResource($path, $sharees);

        // see vendor/sabre/dav/lib/CalDAV/SharingPlugin.php:268
        $this->server->httpResponse->setHeader('X-Sabre-Status', 'everything-went-well');
        return [200, null];
    }

    function getAddressBooks($nodePath, $node) {
        $addressBooks = $node->getChildren();
        $baseUri = $this->server->getBaseUri();

        $items = [];
        foreach ($addressBooks as $addressBook) {
            if ($addressBook instanceof \Sabre\CardDAV\AddressBook) {
                $items[] = $this->getAddressBookDetail($nodePath . "/" . $addressBook->getName(), $addressBook);
            }
        }

        $requestPath = $baseUri . $nodePath . ".json";
        $result = [
            "_links" => [
                "self" => [ "href" => $requestPath ]
            ],
            "_embedded" => [ "dav:addressbook" => $items ]
        ];

        return [200, $result];
    }

    function getAddressBookDetail($nodePath, \Sabre\CardDAV\AddressBook $addressBook) {
        $baseUri = $this->server->getBaseUri();
        $bookProps = $addressBook->getProperties(["{DAV:}displayname", "{DAV:}acl", "{http://open-paas.org/contacts}type", "{urn:ietf:params:xml:ns:carddav}addressbook-description"]);

        return [
            "_links" => [
                "self" => [ "href" => $baseUri . $nodePath . ".json" ],
            ],
            "dav:name" => $bookProps["{DAV:}displayname"],
            "carddav:description" => $bookProps["{urn:ietf:params:xml:ns:carddav}addressbook-description"],
            "dav:acl" => $bookProps["{DAV:}acl"],
            "type" => $bookProps["{http://open-paas.org/contacts}type"],
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
                  "self" => [ "href" =>  $baseUri . $nodePath . "/" . $card->getName() ]
                ],
                "etag" => $card->getETag(),
                "data" => $vobj->jsonSerialize()
            ];
            $items[] = $cardItem;
        }

        $requestPath = $baseUri . $request->getPath() . ".json";

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

    function acceptJson() {
        return in_array("application/calendar+json", $this->acceptHeader) ||
               in_array("application/vcard+json", $this->acceptHeader) ||
               in_array("application/json", $this->acceptHeader);
    }

    function send($code, $body, $setContentType = true) {
        if (!isset($code)) {
            return true;
        }

        if ($body) {
            if ($setContentType) {
                $this->server->httpResponse->setHeader("Content-Type","application/json; charset=utf-8");
            }
            $this->server->httpResponse->setBody(json_encode($body));
        }
        $this->server->httpResponse->setStatus($code);
        return false;
    }
}
