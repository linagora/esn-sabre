<?php

namespace ESN\JSON\CalDAV;

use ESN\Utils\Utils;
use \Sabre\VObject,
    \Sabre\DAV;

/**
 * Calendar Object Handler
 *
 * Handles calendar object (events) operations including:
 * - Event querying and filtering
 * - Event expansion (recurring events)
 * - Sync-token based synchronization
 * - Multi-calendar queries
 */
class CalendarObjectHandler {
    protected $server;
    protected $currentUser;

    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    public function __construct($server, $currentUser) {
        $this->server = $server;
        $this->currentUser = $currentUser;
    }

    public function queryCalendarObjects($nodePath, $node, $jsonData) {
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
                $calendarHandler = new CalendarHandler($this->server, $this->currentUser);
                $calendar = $calendarHandler->calendarToJson($calendarPath, $node);
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

    public function getCalendarObjectByUID($nodePath, $node, $jsonData) {
        if (!isset($jsonData->uid)) {
            return [400, null];
        }

        $eventPath = $node->getCalendarObjectByUID($jsonData->uid);

        if (!$eventPath) {
            return [404, null];
        }

        return [200, $this->getMultipleDAVItems($nodePath, $node, [$eventPath])];
    }

    public function getMultipleCalendarObjectsFromPaths($nodePath, $jsonData) {
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

    public function getCalendarObjects($nodePath, $node, $jsonData) {
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

        // Use optimized method if available (Mongo backend with SharedCalendar node)
        if ($node instanceof \ESN\CalDAV\SharedCalendar && $node->getBackend() instanceof \ESN\CalDAV\Backend\Mongo) {
            // getFullCalendarId returns the full calendar id array [calendarId, instanceId]
            return [200, $this->getMultipleDAVItemsOptimized($nodePath, $node, $filters, $start, $end)];
        }

        // Fallback to standard method for other backends
        return [200, $this->getMultipleDAVItems($nodePath, $node, $node->calendarQuery($filters), $start, $end)];
    }

    /**
     * Get calendar objects based on sync-token for incremental synchronization.
     *
     * This method implements CalDAV sync-token based synchronization, allowing clients to
     * retrieve only the calendar changes (added, modified, deleted) since a previous sync state.
     *
     * PERFORMANCE: This optimized implementation only reads the calendarchanges table without
     * reading or parsing individual calendar events, providing fast synchronization.
     *
     * Workflow:
     * 1. Extracts and normalizes the sync-token from the request (supports URL and numeric formats)
     * 2. Retrieves the calendar backend and calendar ID
     * 3. Calls backend's getChangesForCalendar() to get changes since the sync-token
     * 4. Builds a multistatus response with:
     *    - Added/modified events: status 200 with href only (no etag, no calendar-data)
     *    - Deleted events: status 404 with only the URI
     *    - New sync-token for the next synchronization
     *
     * Sync-token formats supported:
     * - Empty string "": Initial sync, returns all calendar objects
     * - Numeric: "123" - Sync since token 123
     * - URL format: "http://sabre.io/ns/sync/123" - Extracts numeric token 123
     *
     * Response format:
     * {
     *   "_links": {"self": {"href": "/calendars/userId/calendarId.json"}},
     *   "_embedded": {
     *     "dav:item": [
     *       {
     *         "_links": {"self": {"href": "/calendars/.../event.ics"}},
     *         "status": 200
     *       },
     *       {
     *         "_links": {"self": {"href": "/calendars/.../deleted.ics"}},
     *         "status": 404
     *       }
     *     ]
     *   },
     *   "sync-token": "http://sabre.io/ns/sync/124"
     * }
     *
     * @param string $nodePath Path to the calendar resource (e.g., "/calendars/userId/calendarId")
     * @param \Sabre\CalDAV\ICalendarObjectContainer $node Calendar node instance (Calendar or SharedCalendar)
     * @param object $jsonData JSON request data containing the 'sync-token' property
     * @return array Tuple of [int $statusCode, array|null $responseBody]
     *               - [207, array] on success with multistatus response
     *               - [400, null] if calendar not found or invalid request
     */
    public function getCalendarObjectsBySyncToken($nodePath, $node, $jsonData) {
        $syncToken = isset($jsonData->{'sync-token'}) ? $jsonData->{'sync-token'} : null;

        // Extract numeric sync token from URL format if needed
        // Format can be: "http://example.com/sync/153" or just "153"
        // Handle trailing slashes: "http://example.com/sync/153/" -> "153"
        if ($syncToken && is_string($syncToken)) {
            $parts = explode('/', rtrim($syncToken, '/'));
            $syncToken = end($parts);
        }

        // Validate that sync token is numeric (empty string is valid for initial sync)
        if ($syncToken !== '' && $syncToken !== null && !is_numeric($syncToken)) {
            return [400, null];
        }

        // Get calendar backend
        $backend = method_exists($node, 'getBackend') ? $node->getBackend() : $node->getCalDAVBackend();

        // Get calendar ID - use the same approach as getMultipleDAVItemsOptimized
        if ($node instanceof \ESN\CalDAV\SharedCalendar) {
            $calendarId = $node->getFullCalendarId();
            if (!is_array($calendarId)) {
                return [400, null];
            }
        } else {
            // For standard Sabre calendars, get ID from backend
            $principalUri = $node->getOwner();
            $calendarUri = $node->getName();
            $calendars = $backend->getCalendarsForUser($principalUri);
            $calendarId = null;
            foreach ($calendars as $calendar) {
                if ($calendar['uri'] === $calendarUri) {
                    $calendarId = $calendar['id'];
                    break;
                }
            }
            if ($calendarId === null) {
                return [400, null];
            }
            // Ensure it's an array for getChangesForCalendar
            if (!is_array($calendarId)) {
                $calendarId = [$calendarId, null];
            }
        }

        // Get changes from backend
        // syncLevel 1 = include calendar object changes (added, modified, deleted)
        $changes = $backend->getChangesForCalendar($calendarId, $syncToken, 1);

        // If null is returned, the sync token is invalid
        if ($changes === null) {
            return [400, null];
        }

        $baseUri = $this->server->getBaseUri();
        $items = [];

        // Process added and modified events
        // Optimized: build href directly from URI without reading or parsing each event
        foreach (array_merge($changes['added'], $changes['modified']) as $uri) {
            $items[] = [
                '_links' => [
                    'self' => [ 'href' => $baseUri . $nodePath . '/' . $uri ]
                ],
                'status' => 200
            ];
        }

        // Process deleted events
        foreach ($changes['deleted'] as $uri) {
            $items[] = [
                '_links' => [
                    'self' => [ 'href' => $baseUri . $nodePath . '/' . $uri ]
                ],
                'status' => 404
            ];
        }

        // Build sync-token URL
        $newSyncToken = 'http://sabre.io/ns/sync/' . $changes['syncToken'];

        $result = [
            '_links' => [
                'self' => [ 'href' => $baseUri . $nodePath . '.json' ]
            ],
            '_embedded' => [
                'dav:item' => $items
            ],
            'sync-token' => $newSyncToken
        ];

        return [207, $result];
    }

    /**
     * Expands a single calendar event within a time range.
     *
     * This method is used when the client needs to retrieve a specific event
     * (identified by its URI) and expand it within a time window. This is typically
     * used after a sync-token sync to get the full expanded data for changed events.
     *
     * Workflow:
     * 1. Parse the time-range from the request (match.start and match.end)
     * 2. Read the calendar event data from the node
     * 3. Expand recurring events within the specified time range
     * 4. Hide private event info if necessary
     * 5. Return the expanded event in JSON format
     *
     * @param string $nodePath Path to the calendar object (e.g., "/calendars/userId/calendarId/event.ics")
     * @param \Sabre\CalDAV\ICalendarObject $node Calendar object node instance
     * @param object $jsonData JSON request data containing match.start and match.end
     * @return array Tuple of [int $statusCode, array|null $responseBody]
     *               - [200, array] on success with event data
     *               - [400, null] if time-range parameters are invalid
     */
    public function expandEvent($nodePath, $node, $jsonData) {
        // Parse time-range parameters

        $start = VObject\DateTimeParser::parseDateTime($jsonData->match->start);
        $end = VObject\DateTimeParser::parseDateTime($jsonData->match->end);

        // Read the calendar event data
        $calendarData = $node->get();
        $vObject = VObject\Reader::read($calendarData);

        // Get parent calendar node for permission checks
        // Extract parent path by removing the last segment (event filename)
        $pathParts = explode('/', trim($nodePath, '/'));
        array_pop($pathParts); // Remove event filename
        $parentPath = '/' . implode('/', $pathParts);
        $parentNode = $this->server->tree->getNodeForPath($parentPath);

        // Expand the event in the time range
        $vObject = $this->expandAndNormalizeVObject($vObject, $start, $end);

        // Post-filtering: Remove events outside the time range
        $vevents = $vObject->select('VEVENT');
        foreach ($vevents as $vevent) {
            $eventStart = $vevent->DTSTART->getDateTime();

            // Calculate event end: use DTEND if available, otherwise calculate from DURATION
            if (isset($vevent->DTEND)) {
                $eventEnd = $vevent->DTEND->getDateTime();
            } elseif (isset($vevent->DURATION)) {
                $eventEnd = clone $eventStart;
                $eventEnd->add($vevent->DURATION->getDateInterval());
            } else {
                // No DTEND or DURATION: event is instantaneous or all-day
                $eventEnd = $eventStart;
            }

            // If event is completely outside the time range, remove it
            if ($eventEnd <= $start || $eventStart >= $end) {
                $vObject->remove($vevent);
            }
        }

        // Hide private event info if necessary (only if there are events)
        $remainingEvents = $vObject->select('VEVENT');
        if (count($remainingEvents) > 0) {
            $vObject = Utils::hidePrivateEventInfoForUser($vObject, $parentNode, $this->currentUser);
        }

        // Get etag
        $etag = $node->getETag();

        // Format response
        $baseUri = $this->server->getBaseUri();

        $result = [
            '_links' => [
                'self' => [ 'href' => $baseUri . $nodePath ]
            ],
            'data' => $vObject->jsonSerialize(),
            'etag' => $etag
        ];

        $vObject->destroy();

        return [200, $result];
    }

    /**
     * Expands and normalizes a VObject for recurring events.
     * Handles event expansion between dates, RECURRENCE-ID addition, and UTC conversion.
     *
     * @param VObject\Component\VCalendar $vObject The calendar object to process
     * @param \DateTime|false $start Start date for expansion
     * @param \DateTime|false $end End date for expansion
     * @return VObject\Component\VCalendar The processed calendar object
     */
    private function expandAndNormalizeVObject($vObject, $start, $end) {
        // If we have start and end date, we're getting an expanded list of occurrences between these dates
        if ($start && $end) {
            $expandedObject = $vObject->expand($start, $end);

            // Sabre's VObject doesn't return the RECURRENCE-ID in the first
            // occurrence, we'll need to do this ourselves. We take advantage
            // of the fact that the object getter for VEVENT will always return
            // the first one.
            $vevent = $expandedObject->VEVENT;

            // When an event has only RECURRENCE-ID exceptions without a master event (RRULE),
            // the expand() method returns an empty VCALENDAR with no VEVENT.
            // This happens when a user is invited to only one occurrence of a recurring event.
            // In this case, we use the original unexpanded object and normalize it.
            if (!is_object($vevent)) {
                // Convert dates to UTC to match expand() behavior
                // IMPORTANT: Must be done BEFORE removing VTIMEZONE, as conversion needs timezone info
                foreach ($vObject->VEVENT as $vevent) {
                    $this->convertDateTimeToUTC($vevent, 'DTSTART');
                    $this->convertDateTimeToUTC($vevent, 'DTEND');
                }

                // Remove VTIMEZONE to match expand() behavior
                unset($vObject->VTIMEZONE);
                // Keep the original vObject instead of the empty expanded one
            } else {
                $vObject = $expandedObject;

                if (isset($vevent->RRULE) && !isset($vevent->{'RECURRENCE-ID'})) {
                    $recurid = clone $vevent->DTSTART;
                    $recurid->name = 'RECURRENCE-ID';
                    $vevent->add($recurid);
                }
            }
        }

        return $vObject;
    }

    private function getMultipleDAVItems($parentNodePath, $parentNode, $calendarObjectUris, $start = false, $end = false) {
        $baseUri = $this->server->getBaseUri();
        $props = [ '{' . self::NS_CALDAV . '}calendar-data', '{DAV:}getetag' ];

        // Retrieve the syncToken from the calendar (only for ICalendarObjectContainer nodes)
        $syncToken = null;
        if ($parentNode instanceof \Sabre\CalDAV\ICalendarObjectContainer && method_exists($parentNode, 'getProperties')) {
            $calendarProps = $parentNode->getProperties(['{http://sabredav.org/ns}sync-token']);
            $syncToken = isset($calendarProps['{http://sabredav.org/ns}sync-token'])
                ? $calendarProps['{http://sabredav.org/ns}sync-token']
                : null;
        }

        $paths = [];
        foreach ($calendarObjectUris as $calendarObjectUri) {
            $paths[] = $parentNodePath . '/' . $calendarObjectUri;
        }

        $propertyList = [];
        foreach ($this->server->getPropertiesForMultiplePaths($paths, $props) as $objProps) {
            $vObject = VObject\Reader::read($objProps[200][$props[0]]);
            $vObject = $this->expandAndNormalizeVObject($vObject, $start, $end);
            $vObject = Utils::hidePrivateEventInfoForUser($vObject, $parentNode, $this->currentUser);
            $objProps[200][$props[0]] = $vObject->jsonSerialize();
            $vObject->destroy();

            $propertyList[] = $objProps;
        }

        $embedded = [
            'dav:item' => Utils::generateJSONMultiStatus([
                'fileProperties' => $propertyList,
                'dataKey' => $props[0],
                'baseUri' => $baseUri
            ])
        ];

        // Add the syncToken if it exists
        if ($syncToken !== null) {
            $embedded['sync-token'] = 'http://sabre.io/ns/sync/' . $syncToken;
        }

        return [
            '_links' => [
                'self' => [ 'href' => $baseUri . $parentNodePath . '.json']
            ],
            '_embedded' => $embedded
        ];
    }

    /**
     * Optimized version of getMultipleDAVItems that works with data already fetched from the database.
     * This avoids the expensive getPropertiesForMultiplePaths call.
     *
     * @param string $parentNodePath
     * @param mixed $parentNode
     * @param array $calendarObjects Array of objects with 'uri', 'calendardata', and 'etag' keys
     * @param \DateTime|false $start
     * @param \DateTime|false $end
     * @return array
     */
    private function getMultipleDAVItemsOptimized($parentNodePath, $parentNode, $filters, $start = false, $end = false) {
        $baseUri = $this->server->getBaseUri();
        $props = [ '{' . self::NS_CALDAV . '}calendar-data', '{DAV:}getetag' ];

        // Retrieve the syncToken from the calendar (only for ICalendarObjectContainer nodes)
        $syncToken = null;
        if ($parentNode instanceof \Sabre\CalDAV\ICalendarObjectContainer && method_exists($parentNode, 'getProperties')) {
            $calendarProps = $parentNode->getProperties(['{http://sabredav.org/ns}sync-token']);
            $syncToken = isset($calendarProps['{http://sabredav.org/ns}sync-token'])
                ? $calendarProps['{http://sabredav.org/ns}sync-token']
                : null;
        }

        $propertyList = [];
        $backend = $parentNode->getBackend();
        $id = $parentNode->getFullCalendarId();
        foreach ($backend->calendarQueryWithAllData($id, $filters) as $calendarObject) {
            // Use pre-parsed VObject if available (from requirePostFilter), otherwise parse now
            $vObject = $calendarObject['vObject'] ?? VObject\Reader::read($calendarObject['calendardata']);
            $vObject = $this->expandAndNormalizeVObject($vObject, $start, $end);
            $vObject = Utils::hidePrivateEventInfoForUser($vObject, $parentNode, $this->currentUser);

            // Build the property list in the same format as getPropertiesForMultiplePaths would return
            $objProps = [
                200 => [
                    $props[0] => $vObject->jsonSerialize(),
                    $props[1] => $calendarObject['etag']
                ],
                404 => [],  // Required by Utils::generateJSONMultiStatus
                'href' => $parentNodePath . '/' . $calendarObject['uri']
            ];
            $vObject->destroy();

            $propertyList[] = $objProps;
        }

        $embedded = [
            'dav:item' => Utils::generateJSONMultiStatus([
                'fileProperties' => $propertyList,
                'dataKey' => $props[0],
                'baseUri' => $baseUri
            ])
        ];

        // Add the syncToken if it exists
        if ($syncToken !== null) {
            $embedded['sync-token'] = 'http://sabre.io/ns/sync/' . $syncToken;
        }

        return [
            '_links' => [
                'self' => [ 'href' => $baseUri . $parentNodePath . '.json']
            ],
            '_embedded' => $embedded
        ];
    }

    /**
     * Converts a date/time property to UTC timezone and removes TZID parameter.
     *
     * @param \Sabre\VObject\Component $vevent The event component
     * @param string $propertyName The property name (DTSTART or DTEND)
     */
    private function convertDateTimeToUTC($vevent, $propertyName) {
        if (isset($vevent->$propertyName) && $vevent->$propertyName->hasTime()) {
            $dt = $vevent->$propertyName->getDateTime();
            $dt->setTimezone(new \DateTimeZone('UTC'));

            // Recreate the property with UTC value
            // setDateTime() alone doesn't properly convert, we need to set the raw value
            $vevent->$propertyName->setValue($dt->format('Ymd\THis\Z'));
            unset($vevent->$propertyName['TZID']);
        }
    }
}
