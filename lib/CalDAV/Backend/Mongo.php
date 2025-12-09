<?php

namespace ESN\CalDAV\Backend;

use \Sabre\VObject;
use Sabre\Event\EventEmitter;
use ESN\CalDAV\Backend\DAO\CalendarDAO;
use ESN\CalDAV\Backend\DAO\CalendarInstanceDAO;
use ESN\CalDAV\Backend\DAO\CalendarObjectDAO;
use ESN\CalDAV\Backend\DAO\CalendarChangeDAO;
use ESN\CalDAV\Backend\DAO\SchedulingObjectDAO;
use ESN\CalDAV\Backend\DAO\CalendarSubscriptionDAO;
use ESN\CalDAV\Backend\Service\CalendarSharingService;
use ESN\CalDAV\Backend\Service\CalendarDataNormalizer;
use ESN\CalDAV\Backend\Service\SubscriptionService;
use ESN\CalDAV\Backend\Service\SchedulingService;
use ESN\CalDAV\Backend\Service\CalendarService;

#[\AllowDynamicProperties]
class Mongo extends \Sabre\CalDAV\Backend\AbstractBackend implements
    \Sabre\CalDAV\Backend\SubscriptionSupport,
    \Sabre\CalDAV\Backend\SyncSupport,
    \Sabre\CalDAV\Backend\SchedulingSupport,
    \Sabre\CalDAV\Backend\SharingSupport {

    protected $db;
    protected $eventEmitter;
    protected $schedulingObjectTTLInDays;

    protected $calendarDAO;
    protected $calendarInstanceDAO;
    protected $calendarObjectDAO;
    protected $calendarChangeDAO;
    protected $schedulingObjectDAO;
    protected $calendarSubscriptionDAO;
    protected $calendarSharingService;
    protected $calendarDataNormalizer;
    protected $subscriptionService;
    protected $schedulingService;
    protected $calendarService;

    public $propertyMap = [
        '{DAV:}displayname' => 'displayname',
        '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
        '{urn:ietf:params:xml:ns:caldav}calendar-timezone' => 'timezone',
        '{http://apple.com/ns/ical/}calendar-order' => 'calendarorder',
        '{http://apple.com/ns/ical/}calendar-color' => 'calendarcolor',
    ];

    public $subscriptionPropertyMap = [
        '{DAV:}displayname' => 'displayname',
        '{http://apple.com/ns/ical/}refreshrate' => 'refreshrate',
        '{http://apple.com/ns/ical/}calendar-order' => 'calendarorder',
        '{http://apple.com/ns/ical/}calendar-color' => 'calendarcolor',
        '{http://calendarserver.org/ns/}subscribed-strip-todos' => 'striptodos',
        '{http://calendarserver.org/ns/}subscribed-strip-alarms' => 'stripalarms',
        '{http://calendarserver.org/ns/}subscribed-strip-attachments' => 'stripattachments',
    ];

    const MAX_DATE = '2038-01-01';
    const RESOURCE_CALENDAR_PUBLIC_PRIVILEGE = '{DAV:}read';

    function __construct(\MongoDB\Database $db, $schedulingObjectTTLInDays = 0) {
        $this->db = $db;
        $this->eventEmitter = new EventEmitter();
        $this->schedulingObjectTTLInDays = $schedulingObjectTTLInDays;

        // Initialize DAOs
        $this->calendarDAO = new CalendarDAO($db);
        $this->calendarInstanceDAO = new CalendarInstanceDAO($db);
        $this->calendarObjectDAO = new CalendarObjectDAO($db);
        $this->calendarChangeDAO = new CalendarChangeDAO($db);
        $this->schedulingObjectDAO = new SchedulingObjectDAO($db, $schedulingObjectTTLInDays);
        $this->calendarSubscriptionDAO = new CalendarSubscriptionDAO($db);

        // Initialize Services
        $this->calendarSharingService = new CalendarSharingService($this->calendarInstanceDAO, $this->eventEmitter);
        $this->calendarDataNormalizer = new CalendarDataNormalizer();
        $this->subscriptionService = new SubscriptionService($this->calendarSubscriptionDAO, $this->eventEmitter, $this->subscriptionPropertyMap);
        $this->schedulingService = new SchedulingService($this->schedulingObjectDAO);
        $this->calendarService = new CalendarService(
            $this->calendarDAO,
            $this->calendarInstanceDAO,
            $this->calendarObjectDAO,
            $this->calendarChangeDAO,
            $this->eventEmitter,
            $this->propertyMap,
            $this->server ?? null
        );

        $this->ensureIndex();
    }

    function getEventEmitter() {
        return $this->eventEmitter;
    }

    function getCalendarsForUser($principalUri) {
        return $this->calendarService->getCalendarsForUser($principalUri);
    }

    function createCalendar($principalUri, $calendarUri, array $properties) {
        return $this->calendarService->createCalendar($principalUri, $calendarUri, $properties);
    }

    public function getCalendarPath($principalUri, $calendarUri) {
        $uriExploded = explode('/', $principalUri);
        return '/calendars/' . $uriExploded[2] . '/' . $calendarUri;
    }

    function updateCalendar($calendarId, \Sabre\DAV\PropPatch $propPatch) {
        $this->_assertIsArray($calendarId);
        return $this->calendarService->updateCalendar($calendarId, $propPatch);
    }

    function deleteCalendar($calendarIdArray) {
        $this->_assertIsArray($calendarIdArray);

        $deleteSubscribersCallback = function($principaluri, $uri) {
            $this->deleteSubscribers($principaluri, $uri);
        };

        $updateInvitesCallback = function($calendarId, $invites) {
            $this->updateInvites($calendarId, $invites);
        };

        $getInvitesCallback = function($calendarId) {
            return $this->getInvites($calendarId);
        };

        return $this->calendarService->deleteCalendar(
            $calendarIdArray,
            $deleteSubscribersCallback,
            $updateInvitesCallback,
            $getInvitesCallback
        );
    }

    function deleteSubscribers($principaluri, $uri) {
        $getSubscribersCallback = function($source) {
            return $this->getSubscribers($source);
        };

        $deleteSubscriptionCallback = function($subscriptionId) {
            $this->deleteSubscription($subscriptionId);
        };

        return $this->calendarService->deleteSubscribers($principaluri, $uri, $getSubscribersCallback, $deleteSubscriptionCallback);
    }

    function getCalendarObjects($calendarId) {
        $this->_assertIsArray($calendarId);

        $calendarId = $calendarId[0];

        $projection = [
            '_id' => 1,
            'uri' => 1,
            'lastmodified' => 1,
            'etag' => 1,
            'calendarid' => 1,
            'size' => 1,
            'componenttype' => 1
        ];

        $result = [];
        foreach ($this->calendarObjectDAO->findByCalendarId($calendarId, $projection) as $row) {
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

    function getCalendarObject($calendarId, $objectUri) {
        $result = $this->getMultipleCalendarObjects($calendarId, [ $objectUri ]);

        return array_shift($result);
    }

    function getMultipleCalendarObjects($calendarId, array $uris) {
        $this->_assertIsArray($calendarId);

        $calendarId = $calendarId[0];

        $projection = [
            '_id' => 1,
            'uri' => 1,
            'lastmodified' => 1,
            'etag' => 1,
            'calendarid' => 1,
            'size' => 1,
            'calendardata' => 1,
            'componenttype' => 1
        ];

        $result = [];
        foreach ($this->calendarObjectDAO->findByCalendarIdAndUris($calendarId, $uris, $projection) as $row) {
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

    function createCalendarObject($calendarId, $objectUri, $calendarData) {
        $this->_assertIsArray($calendarId);

        $calendarId = $calendarId[0];

        $extraData = $this->getDenormalizedData($calendarData);

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
        $this->addChange($calendarId, $objectUri, 1);

        return '"' . $extraData['etag'] . '"';
    }

    function updateCalendarObject($calendarId, $objectUri, $calendarData) {
        $this->_assertIsArray($calendarId);

        $calendarId = $calendarId[0];

        $extraData = $this->getDenormalizedData($calendarData);

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
        $this->addChange($calendarId, $objectUri, 2);

        return '"' . $extraData['etag'] . '"';
    }

    function deleteCalendarObject($calendarId, $objectUri) {
        $this->_assertIsArray($calendarId);

        $calendarId = $calendarId[0];

        $this->calendarObjectDAO->deleteCalendarObject($calendarId, $objectUri);
        $this->addChange($calendarId, $objectUri, 3);
    }

    function calendarQuery($calendarId, array $filters) {
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
     * @param mixed $calendarId
     * @param array $filters
     * @return array Array of objects with 'uri', 'calendardata', and 'etag' keys
     */
    function calendarQueryWithAllData($calendarId, array $filters) {
        return $this->executeCalendarQuery($calendarId, $filters, true);
    }

    /**
     * Execute calendar query with optional full data return
     *
     * @param mixed $calendarId [calendarId, instanceId]
     * @param array $filters CalDAV filters
     * @param bool $returnFullData If true, yields full data; if false, yields uri only
     * @return \Generator Yields calendar objects
     */
    private function executeCalendarQuery($calendarId, array $filters, $returnFullData) {
        $this->_assertIsArray($calendarId);
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
    protected function validateFilterForObjectWithVObject($vObject, array $filters) {
        $validator = new \Sabre\CalDAV\CalendarQueryValidator();
        return $validator->validate($vObject, $filters);
    }

    function getCalendarObjectByUID($principalUri, $uid) {
        return $this->calendarService->getCalendarObjectByUID($principalUri, $uid);
    }

    function getDuplicateCalendarObjectsByURI($principalUri, $uri) {
        return $this->calendarService->getDuplicateCalendarObjectsByURI($principalUri, $uri);
    }

    function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null) {
        $this->_assertIsArray($calendarId);

        $calendarId = $calendarId[0];

        // Current synctoken
        $row = $this->calendarDAO->getSyncToken($calendarId);
        if (!$row || is_null($row['synctoken'])) return null;

        $currentToken = $row['synctoken'];

        $result = [
            'syncToken' => $currentToken,
            'added'     => [],
            'modified'  => [],
            'deleted'   => [],
        ];

        if ($syncToken) {
            $res = $this->calendarChangeDAO->findChangesBySyncToken($calendarId, $syncToken, $currentToken, $limit);

            // Fetching all changes
            $changes = [];

            // This loop ensures that any duplicates are overwritten, only the
            // last change on a node is relevant.
            foreach ($res as $row) {
                $changes[$row['uri']] = $row['operation'];
            }

            foreach($changes as $uri => $operation) {
                switch($operation) {
                    case 1 :
                        $result['added'][] = $uri;
                        break;
                    case 2 :
                        $result['modified'][] = $uri;
                        break;
                    case 3 :
                        $result['deleted'][] = $uri;
                        break;
                }
            }
        } else {
            // No synctoken supplied, this is the initial sync.
            $projection = [ 'uri' => 1 ];

            $added = [];
            foreach ($this->calendarObjectDAO->findByCalendarId($calendarId, $projection) as $row) {
                $added[] = $row['uri'];
            }
            $result['added'] = $added;
        }
        return $result;
    }

    function getSubscriptionsForUser($principalUri) {
        return $this->subscriptionService->getSubscriptionsForUser($principalUri);
    }

    function createSubscription($principalUri, $uri, array $properties) {
        return $this->subscriptionService->createSubscription($principalUri, $uri, $properties, [$this, 'getCalendarPath']);
    }

    function updateSubscription($subscriptionId, \Sabre\DAV\PropPatch $propPatch) {
        $this->subscriptionService->updateSubscription($subscriptionId, $propPatch, [$this, 'getCalendarPath']);
    }

    function deleteSubscription($subscriptionId) {
        $this->subscriptionService->deleteSubscription($subscriptionId, [$this, 'getCalendarPath']);
    }

    function getSubscribers($source) {
        return $this->subscriptionService->getSubscribers($source);
    }

    function getSchedulingObject($principalUri, $objectUri) {
        return $this->schedulingService->getSchedulingObject($principalUri, $objectUri);
    }

    function getSchedulingObjects($principalUri) {
        return $this->schedulingService->getSchedulingObjects($principalUri);
    }

    function deleteSchedulingObject($principalUri, $objectUri) {
        $this->schedulingService->deleteSchedulingObject($principalUri, $objectUri);
    }

    function createSchedulingObject($principalUri, $objectUri, $objectData) {
        $this->schedulingService->createSchedulingObject($principalUri, $objectUri, $objectData);
    }

    function updateInvites($calendarId, array $sharees) {
        $this->_assertIsArray($calendarId);

        return $this->calendarSharingService->updateInvites($calendarId, $sharees);
    }

    function getInvites($calendarId) {
        $this->_assertIsArray($calendarId);

        return $this->calendarSharingService->getInvites($calendarId);
    }

    function prepareRequestForCalendarPublicRight($calendarId) {
        $this->_assertIsArray($calendarId);
        return $this->calendarSharingService->prepareRequestForCalendarPublicRight($calendarId);
    }

    function saveCalendarPublicRight($calendarId, $privilege, $calendarInfo) {
        $this->_assertIsArray($calendarId);

        $this->calendarSharingService->saveCalendarPublicRight(
            $calendarId,
            $privilege,
            $calendarInfo,
            [$this, 'deleteSubscribers'],
            [$this, 'getCalendarPath']
        );
    }

    function saveCalendarInviteStatus($calendarId, $status) {
        $this->_assertIsArray($calendarId);

        $this->calendarSharingService->saveCalendarInviteStatus($calendarId, $status);
    }

    function getCalendarPublicRight($calendarId) {
        $this->_assertIsArray($calendarId);

        return $this->calendarSharingService->getCalendarPublicRight($calendarId);
    }

    function setPublishStatus($calendarId, $value) {
        throw new \Exception('Not implemented');
    }

    protected function _assertIsArray($calendarId) {
        if (!is_array($calendarId)) {
            throw new \LogicException('The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
        }
    }

    /**
     * @codeCoverageIgnore      Copy/Paste from sabre/dav
     */
    protected function getDenormalizedData($calendarData) {
        return $this->calendarDataNormalizer->getDenormalizedData($calendarData);
    }

    protected function addChange($calendarId, $objectUri, $operation) {
        $res = $this->calendarDAO->getSyncToken($calendarId);

        if (!$res) {
            return;
        }

        $this->calendarChangeDAO->addChange($calendarId, $objectUri, $res['synctoken'], $operation);
        $this->calendarDAO->incrementSyncToken($calendarId);
    }

    private function ensureIndex() {
        // Skip index creation if disabled via environment variable
        // Rational: calling createIndex on every request doesn't make sense in production
        $shouldCreateIndex = getenv('SHOULD_CREATE_INDEX');
        $isUndefined = $shouldCreateIndex === false;
        if ($isUndefined || $shouldCreateIndex === 'true') {
            $this->calendarDAO->ensureIndexes();
            $this->calendarInstanceDAO->ensureIndexes();
            $this->calendarObjectDAO->ensureIndexes();
            $this->calendarChangeDAO->ensureIndexes();
            $this->calendarSubscriptionDAO->ensureIndexes();
            $this->schedulingObjectDAO->ensureIndexes();
        }
    }

}
