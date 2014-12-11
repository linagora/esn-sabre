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
    }

    function post($request, $response) {
        if (substr($request->getPath(), 0, strlen($this->root)) != $this->root) {
            return true;
        }

        $parts = explode("/", substr($request->getPath(), strlen($this->root) + 1));

        if ($parts[0] == "queries" && $parts[1] == "time-range") {
            return $this->timeRangeQuery($request, $response);
        }
        return true;
    }

    function timeRangeQuery($request, $response) {
        $jsonData = json_decode($request->getBodyAsString());
        $calendar = $jsonData->{'scope'}->{'calendars'}[0];

        $node = $this->server->tree->getNodeForPath($calendar);
        if ($node && ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer)) {
            $start = $jsonData->{'match'}->{'start'};
            $end = $jsonData->{'match'}->{'end'};

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

            $props = [ '{' . self::NS_CALDAV . '}calendar-data' ];

            foreach ($nodePaths as $path) {
                list($properties) =
                    $this->server->getPropertiesForPath($calendar . '/' . $path, $props);

                $vObject = VObject\Reader::read($properties[200]['{' . self::NS_CALDAV . '}calendar-data']);
                $vObject->expand($start, $end);

                $results[] = $vObject->jsonSerialize();
            }

            $this->server->httpResponse->setStatus(200);
            $this->server->httpResponse->setHeader('Content-Type','application/json; charset=utf-8');
            $this->server->httpResponse->setBody(json_encode($results));
        } else {
            $this->server->httpResponse->setStatus(404);
        }
        return false;
    }
}
