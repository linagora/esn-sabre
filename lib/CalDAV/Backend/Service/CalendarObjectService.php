<?php

namespace ESN\CalDAV\Backend\Service;

use ESN\CalDAV\Backend\DAO\CalendarObjectDAO;
use \Sabre\VObject;

/**
 * Calendar Object Service
 *
 * Handles calendar object (events, todos) operations including:
 * - CRUD operations on calendar objects
 * - Calendar queries with filtering
 * - Validation and normalization
 */
class CalendarObjectService {
    const LIGHT_PROJECTION = [
        '_id' => 1,
        'uri' => 1,
        'lastmodified' => 1,
        'etag' => 1,
        'calendarid' => 1,
        'size' => 1,
        'componenttype' => 1
    ];

    const FULL_PROJECTION = self::LIGHT_PROJECTION + [
        'calendardata' => 1
    ];

    private $calendarObjectDAO;
    private $calendarDataNormalizer;

    public function __construct(
        CalendarObjectDAO $calendarObjectDAO,
        CalendarDataNormalizer $calendarDataNormalizer
    ) {
        $this->calendarObjectDAO = $calendarObjectDAO;
        $this->calendarDataNormalizer = $calendarDataNormalizer;
    }

    /**
     * Get all calendar objects for a calendar
     *
     * @param array $calendarId [calendarId, instanceId]
     * @return array Array of calendar objects with metadata
     */
    public function getCalendarObjects($calendarId) {
        $calendarId = $calendarId[0];

        $result = [];
        foreach ($this->calendarObjectDAO->findByCalendarId($calendarId, self::LIGHT_PROJECTION) as $row) {
            $result[] = [
                'id'           => (string) $row['_id'],
                'uri'          => $row['uri'],
                'lastmodified' => $row['lastmodified'],
                'etag'         => '"' . $row['etag'] . '"',
                'size'         => (int) $row['size'],
                'component'    => strtolower($row['componenttype']),
            ];
        }

        return $result;
    }

    /**
     * Get a single calendar object
     *
     * @param array $calendarId [calendarId, instanceId]
     * @param string $objectUri URI of the object
     * @return array|null Calendar object data or null
     */
    public function getCalendarObject($calendarId, $objectUri) {
        $result = $this->getMultipleCalendarObjects($calendarId, [ $objectUri ]);
        return array_shift($result);
    }

    /**
     * Get multiple calendar objects by URIs
     *
     * @param array $calendarId [calendarId, instanceId]
     * @param array $uris Array of object URIs
     * @return array Array of calendar objects
     */
    public function getMultipleCalendarObjects($calendarId, array $uris) {
        $calendarId = $calendarId[0];

        $result = [];
        foreach ($this->calendarObjectDAO->findByCalendarIdAndUris($calendarId, $uris, self::FULL_PROJECTION) as $row) {
            $result[] = [
                'id'           => (string) $row['_id'],
                'uri'          => $row['uri'],
                'lastmodified' => $row['lastmodified'],
                'etag'         => '"' . $row['etag'] . '"',
                'size'         => (int) $row['size'],
                'calendardata' => $row['calendardata'],
                'component'    => strtolower($row['componenttype']),
            ];
        }

        return $result;
    }

    /**
     * Create a new calendar object
     *
     * @param array $calendarId [calendarId, instanceId]
     * @param string $objectUri URI for the new object
     * @param string $calendarData iCalendar data
     * @param callable $addChangeCallback Callback to add change tracking
     * @return string ETag of the created object
     */
    public function createCalendarObject($calendarId, $objectUri, $calendarData, $addChangeCallback) {
        $calendarId = $calendarId[0];

        $extraData = $this->calendarDataNormalizer->getDenormalizedData($calendarData);

        $obj = [
            'calendarid' => $calendarId,
            'uri' => $objectUri,
            'calendardata' => $calendarData,
            'lastmodified' => time(),
            'etag' => $extraData['etag'],
            'size' => $extraData['size'],
            'componenttype' => $extraData['componentType'],
            'firstoccurence' => $extraData['firstOccurence'],
            'lastoccurence' => $extraData['lastOccurence'],
            'uid' => $extraData['uid']
        ];
        $this->calendarObjectDAO->createCalendarObject($obj);
        $addChangeCallback($calendarId, $objectUri, 1);

        return '"' . $extraData['etag'] . '"';
    }

    /**
     * Update an existing calendar object
     *
     * @param array $calendarId [calendarId, instanceId]
     * @param string $objectUri URI of the object to update
     * @param string $calendarData New iCalendar data
     * @param callable $addChangeCallback Callback to add change tracking
     * @return string ETag of the updated object
     */
    public function updateCalendarObject($calendarId, $objectUri, $calendarData, $addChangeCallback) {
        $calendarId = $calendarId[0];

        $extraData = $this->calendarDataNormalizer->getDenormalizedData($calendarData);

        $updateData = [
            'calendardata' => $calendarData,
            'lastmodified' => time(),
            'etag' => $extraData['etag'],
            'size' => $extraData['size'],
            'componenttype' => $extraData['componentType'],
            'firstoccurence' => $extraData['firstOccurence'],
            'lastoccurence' => $extraData['lastOccurence'],
            'uid' => $extraData['uid'],
        ];

        $this->calendarObjectDAO->updateCalendarObject($calendarId, $objectUri, $updateData);
        $addChangeCallback($calendarId, $objectUri, 2);

        return '"' . $extraData['etag'] . '"';
    }

    /**
     * Delete a calendar object
     *
     * @param array $calendarId [calendarId, instanceId]
     * @param string $objectUri URI of the object to delete
     * @param callable $addChangeCallback Callback to add change tracking
     */
    public function deleteCalendarObject($calendarId, $objectUri, $addChangeCallback) {
        $calendarId = $calendarId[0];

        $this->calendarObjectDAO->deleteCalendarObject($calendarId, $objectUri);
        $addChangeCallback($calendarId, $objectUri, 3);
    }

    /**
     * Perform a calendar query returning URIs
     *
     * @param array $calendarId [calendarId, instanceId]
     * @param array $filters CalDAV filters
     * @return array Array of URIs matching the filters
     */
    public function calendarQuery($calendarId, array $filters) {
        $result = [];
        foreach ($this->executeCalendarQuery($calendarId, $filters, false) as $item) {
            $result[] = $item['uri'];
        }
        return $result;
    }

    /**
     * Optimized version of calendarQuery that returns full object data (uri, calendardata, etag)
     * instead of just URIs. This avoids the need for a subsequent getPropertiesForMultiplePaths call.
     *
     * @param array $calendarId [calendarId, instanceId]
     * @param array $filters CalDAV filters
     * @return array Array of objects with 'uri', 'calendardata', and 'etag' keys
     */
    public function calendarQueryWithAllData($calendarId, array $filters) {
        return $this->executeCalendarQuery($calendarId, $filters, true);
    }

    /**
     * Execute calendar query with optional full data return
     *
     * @param array $calendarId [calendarId, instanceId]
     * @param array $filters CalDAV filters
     * @param bool $returnFullData If true, yields full data; if false, yields uri only
     * @return \Generator Yields calendar objects
     */
    private function executeCalendarQuery($calendarId, array $filters, $returnFullData) {
        $calendarId = $calendarId[0];

        list($query, $projection, $requirePostFilter) =
            $this->buildQueryFromFilters($calendarId, $filters, $returnFullData);

        foreach ($this->calendarObjectDAO->findWithQuery($query, $projection) as $row) {
            $result = $this->processQueryResult($row, $filters, $requirePostFilter, $returnFullData);

            if ($result !== null) {
                yield $result;
            }
        }
    }

    /**
     * Build MongoDB query and projection from CalDAV filters
     *
     * @param string $calendarId
     * @param array $filters
     * @param bool $returnFullData
     * @return array [query, projection, requirePostFilter]
     */
    private function buildQueryFromFilters($calendarId, array $filters, $returnFullData) {
        $query = ['calendarid' => $calendarId];
        $requirePostFilter = true;

        // if no filters were specified, we don't need to filter after a query
        if (empty($filters['prop-filters']) && empty($filters['comp-filters'])) {
            $requirePostFilter = false;
        }

        list($componentType, $timeRange, $requirePostFilter) =
            $this->extractComponentFilters($filters, $requirePostFilter);

        if ($componentType) {
            $query['componenttype'] = $componentType;
        }

        if ($timeRange && $timeRange['start']) {
            $query['lastoccurence'] = ['$gte' => $timeRange['start']->getTimeStamp()];
        }
        if ($timeRange && $timeRange['end']) {
            $query['firstoccurence'] = ['$lt' => $timeRange['end']->getTimeStamp()];
        }

        $projection = $this->buildProjection($requirePostFilter, $returnFullData);

        return [$query, $projection, $requirePostFilter];
    }

    /**
     * Extract component type and time range from filters
     *
     * @param array $filters
     * @param bool $requirePostFilter
     * @return array [componentType, timeRange, requirePostFilter]
     */
    private function extractComponentFilters(array $filters, $requirePostFilter) {
        $componentType = null;
        $timeRange = null;

        // Figuring out if there's a component filter
        if (!empty($filters['comp-filters']) && is_array($filters['comp-filters']) && !$filters['comp-filters'][0]['is-not-defined']) {
            $componentType = $filters['comp-filters'][0]['name'];

            // Checking if we need post-filters
            if (empty($filters['prop-filters']) && empty($filters['comp-filters'][0]['comp-filters']) && empty($filters['comp-filters'][0]['time-range']) && empty($filters['comp-filters'][0]['prop-filters'])) {
                $requirePostFilter = false;
            }

            // There was a time-range filter
            if ($componentType == 'VEVENT' && is_array($filters['comp-filters'][0]['time-range'])) {
                $timeRange = $filters['comp-filters'][0]['time-range'];

                // If start time OR the end time is not specified, we can do a 100% accurate query
                if (empty($filters['prop-filters']) && empty($filters['comp-filters'][0]['comp-filters']) && empty($filters['comp-filters'][0]['prop-filters']) && (empty($timeRange['start']) || empty($timeRange['end']))) {
                    $requirePostFilter = false;
                }
            }
        }

        return [$componentType, $timeRange, $requirePostFilter];
    }

    /**
     * Build projection based on requirements
     *
     * @param bool $requirePostFilter
     * @param bool $returnFullData
     * @return array Projection array
     */
    private function buildProjection($requirePostFilter, $returnFullData) {
        if ($returnFullData) {
            return ['uri' => 1, 'calendardata' => 1, 'etag' => 1];
        }

        if ($requirePostFilter) {
            return ['uri' => 1, 'calendardata' => 1];
        }

        return ['uri' => 1];
    }

    /**
     * Process a single query result row
     *
     * @param array $row Database row
     * @param array $filters CalDAV filters
     * @param bool $requirePostFilter Whether post-filtering is needed
     * @param bool $returnFullData Whether to return full data
     * @return array|null Processed result or null if filtered out
     */
    private function processQueryResult($row, $filters, $requirePostFilter, $returnFullData) {
        $vObject = null;

        if ($requirePostFilter) {
            $vObject = VObject\Reader::read($row['calendardata']);

            if (!$this->validateFilterForObjectWithVObject($vObject, $filters)) {
                $vObject->destroy();
                return null;
            }
        }

        if ($returnFullData) {
            return [
                'uri' => $row['uri'],
                'calendardata' => $row['calendardata'],
                'etag' => '"' . $row['etag'] . '"',
                'vObject' => $vObject
            ];
        }

        if ($vObject) {
            $vObject->destroy();
        }

        return ['uri' => $row['uri']];
    }

    /**
     * Optimized version of validateFilterForObject that accepts a pre-parsed VObject.
     * This avoids re-parsing the calendar data when the VObject is already available.
     *
     * @param VObject\Component\VCalendar $vObject
     * @param array $filters
     * @return bool
     */
    private function validateFilterForObjectWithVObject($vObject, array $filters) {
        $validator = new \Sabre\CalDAV\CalendarQueryValidator();
        return $validator->validate($vObject, $filters);
    }
}
