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
use ESN\CalDAV\Backend\Service\CalendarObjectService;

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
    protected $calendarObjectService;

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
        $this->calendarObjectService = new CalendarObjectService(
            $this->calendarObjectDAO,
            $this->calendarDataNormalizer
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

        return $this->calendarService->deleteCalendar(
            $calendarIdArray,
            [$this, 'deleteSubscribers'],
            [$this, 'updateInvites'],
            [$this, 'getInvites']
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
        return $this->calendarObjectService->getCalendarObjects($calendarId);
    }

    function getCalendarObject($calendarId, $objectUri) {
        return $this->calendarObjectService->getCalendarObject($calendarId, $objectUri);
    }

    function getMultipleCalendarObjects($calendarId, array $uris) {
        $this->_assertIsArray($calendarId);
        return $this->calendarObjectService->getMultipleCalendarObjects($calendarId, $uris);
    }

    function createCalendarObject($calendarId, $objectUri, $calendarData) {
        $this->_assertIsArray($calendarId);
        return $this->calendarObjectService->createCalendarObject($calendarId, $objectUri, $calendarData, [$this, 'addChange']);
    }

    function updateCalendarObject($calendarId, $objectUri, $calendarData) {
        $this->_assertIsArray($calendarId);
        return $this->calendarObjectService->updateCalendarObject($calendarId, $objectUri, $calendarData, [$this, 'addChange']);
    }

    function deleteCalendarObject($calendarId, $objectUri) {
        $this->_assertIsArray($calendarId);
        $this->calendarObjectService->deleteCalendarObject($calendarId, $objectUri, [$this, 'addChange']);
    }

    function calendarQuery($calendarId, array $filters) {
        $this->_assertIsArray($calendarId);
        return $this->calendarObjectService->calendarQuery($calendarId, $filters);
    }

    function calendarQueryWithAllData($calendarId, array $filters) {
        $this->_assertIsArray($calendarId);
        return $this->calendarObjectService->calendarQueryWithAllData($calendarId, $filters);
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

        if ($syncToken) {
            return array_merge(
                ['syncToken' => $currentToken],
                $this->getChangesSince($calendarId, $syncToken, $currentToken, $limit)
            );
        }

        return [
            'syncToken' => $currentToken,
            'added' => $this->calendarObjectService->getAllUris([$calendarId, null]),
            'modified' => [],
            'deleted' => []
        ];
    }

    /**
     * Get changes since a specific sync token
     *
     * @param string $calendarId
     * @param int $syncToken
     * @param int $currentToken
     * @param int|null $limit
     * @return array ['added' => [], 'modified' => [], 'deleted' => []]
     */
    private function getChangesSince($calendarId, $syncToken, $currentToken, $limit) {
        $res = $this->calendarChangeDAO->findChangesBySyncToken($calendarId, $syncToken, $currentToken, $limit);

        // Fetching all changes
        $changes = [];

        // This loop ensures that any duplicates are overwritten, only the
        // last change on a node is relevant.
        foreach ($res as $row) {
            $changes[$row['uri']] = $row['operation'];
        }

        $result = [
            'added' => [],
            'modified' => [],
            'deleted' => []
        ];

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

    public function addChange($calendarId, $objectUri, $operation) {
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
