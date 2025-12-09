<?php

namespace ESN\CalDAV\Backend\Service;

use ESN\CalDAV\Backend\DAO\SchedulingObjectDAO;

/**
 * Scheduling Service
 *
 * Handles scheduling object operations including:
 * - Creating, deleting scheduling objects (inbox/outbox items)
 * - Retrieving scheduling objects for a principal
 */
class SchedulingService {
    private $schedulingObjectDAO;

    public function __construct(SchedulingObjectDAO $schedulingObjectDAO) {
        $this->schedulingObjectDAO = $schedulingObjectDAO;
    }

    /**
     * Get a single scheduling object
     *
     * @param string $principalUri
     * @param string $objectUri
     * @return array|null Scheduling object data or null if not found
     */
    public function getSchedulingObject($principalUri, $objectUri) {
        $projection = ['uri' => 1, 'calendardata' => 1, 'lastmodified' => 1, 'etag' => 1, 'size' => 1];
        $row = $this->schedulingObjectDAO->findByPrincipalUriAndUri($principalUri, $objectUri, $projection);

        if (!$row) {
            return null;
        }

        return [
            'uri'          => $row['uri'],
            'calendardata' => $row['calendardata'],
            'lastmodified' => $row['lastmodified'],
            'etag'         => '"' . $row['etag'] . '"',
            'size'         => (int) $row['size'],
        ];
    }

    /**
     * Get all scheduling objects for a principal
     *
     * @param string $principalUri
     * @return array Array of scheduling objects
     */
    public function getSchedulingObjects($principalUri) {
        $projection = ['uri' => 1, 'calendardata' => 1, 'lastmodified' => 1, 'etag' => 1, 'size' => 1];

        $result = [];
        foreach($this->schedulingObjectDAO->findByPrincipalUri($principalUri, $projection) as $row) {
            $result[] = [
                'calendardata' => $row['calendardata'],
                'uri'          => $row['uri'],
                'lastmodified' => $row['lastmodified'],
                'etag'         => '"' . $row['etag'] . '"',
                'size'         => (int) $row['size'],
            ];
        }

        return $result;
    }

    /**
     * Delete a scheduling object
     *
     * @param string $principalUri
     * @param string $objectUri
     */
    public function deleteSchedulingObject($principalUri, $objectUri) {
        $this->schedulingObjectDAO->deleteSchedulingObject($principalUri, $objectUri);
    }

    /**
     * Create a new scheduling object
     *
     * @param string $principalUri
     * @param string $objectUri
     * @param string $objectData
     */
    public function createSchedulingObject($principalUri, $objectUri, $objectData) {
        $obj = [
            'principaluri' => $principalUri,
            'calendardata' => $objectData,
            'uri' => $objectUri,
            'lastmodified' => time(),
            'etag' => md5($objectData),
            'size' => strlen($objectData),
            'dateCreated' => new \MongoDB\BSON\UTCDateTime(time() * 1000)
        ];

        $this->schedulingObjectDAO->createSchedulingObject($obj);
    }
}
