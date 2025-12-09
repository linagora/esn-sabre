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
    const PROJECTION = [
        'uri' => 1,
        'calendardata' => 1,
        'lastmodified' => 1,
        'etag' => 1,
        'size' => 1
    ];

    private $schedulingObjectDAO;

    public function __construct(SchedulingObjectDAO $schedulingObjectDAO) {
        $this->schedulingObjectDAO = $schedulingObjectDAO;
    }

    /**
     * Transform MongoDB row to domain object
     *
     * @param array $row MongoDB document
     * @return array Domain object
     */
    private function asDomainObject($row) {
        return [
            'uri'          => $row['uri'],
            'calendardata' => $row['calendardata'],
            'lastmodified' => $row['lastmodified'],
            'etag'         => '"' . $row['etag'] . '"',
            'size'         => (int) $row['size'],
        ];
    }

    /**
     * Transform domain data to MongoDB document
     *
     * @param string $principalUri Principal URI
     * @param string $objectUri Object URI
     * @param string $objectData iCalendar data
     * @return array MongoDB document
     */
    private function asDocument($principalUri, $objectUri, $objectData) {
        return [
            'principaluri' => $principalUri,
            'calendardata' => $objectData,
            'uri' => $objectUri,
            'lastmodified' => time(),
            'etag' => md5($objectData),
            'size' => strlen($objectData),
            'dateCreated' => new \MongoDB\BSON\UTCDateTime(time() * 1000)
        ];
    }

    /**
     * Get a single scheduling object
     *
     * @param string $principalUri
     * @param string $objectUri
     * @return array|null Scheduling object data or null if not found
     */
    public function getSchedulingObject($principalUri, $objectUri) {
        $row = $this->schedulingObjectDAO->findByPrincipalUriAndUri($principalUri, $objectUri, self::PROJECTION);

        if (!$row) {
            return null;
        }

        return $this->asDomainObject($row);
    }

    /**
     * Get all scheduling objects for a principal
     *
     * @param string $principalUri
     * @return array Array of scheduling objects
     */
    public function getSchedulingObjects($principalUri) {
        $result = [];
        foreach($this->schedulingObjectDAO->findByPrincipalUri($principalUri, self::PROJECTION) as $row) {
            $result[] = $this->asDomainObject($row);
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
        $document = $this->asDocument($principalUri, $objectUri, $objectData);
        $this->schedulingObjectDAO->createSchedulingObject($document);
    }
}
