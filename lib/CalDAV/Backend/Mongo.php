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

        $this->ensureIndex();
    }

    function getEventEmitter() {
        return $this->eventEmitter;
    }

    function getCalendarsForUser($principalUri) {
        $fields = array_values($this->propertyMap);
        $fields[] = 'calendarid';
        $fields[] = 'uri';
        $fields[] = 'synctoken';
        $fields[] = 'components';
        $fields[] = 'principaluri';
        $fields[] = 'transparent';
        $fields[] = 'access';
        $fields[] = 'share_invitestatus';

        $sort = ['calendarorder' => 1];
        $res = $this->calendarInstanceDAO->findByPrincipalUri($principalUri, $fields, $sort);

        $calendarInstances = [];
        $calendarIds = [];

        foreach ($res as $row) {
            $calendarId = (string) $row['calendarid'];

            $calendarIds[] = new \MongoDB\BSON\ObjectId($calendarId);

            //Little fix for avoid duplication calendarInstance,
            //a calendarInstance is linked with only one $calendarId
            //so if a $calendarInstance have been already in the array it gonna be replaced
            $calendarInstances[$calendarId] = $row;
        }

        $projection = [
            '_id' => 1,
            'synctoken' => 1,
            'components' => 1
        ];
        $result = $this->calendarDAO->findByIds($calendarIds, $projection);

        $calendars = [];

        foreach ($result as $row) {
            $calendars[(string) $row['_id']] = $row;
        }

        $userCalendars = [];
        foreach ($calendarInstances as $calendarInstance) {
            $currentCalendarId = (string) $calendarInstance['calendarid'];

            if (!isset($calendars[$currentCalendarId])) {
                $this->server->getLogger().error(
                    'No matching calendar found',
                    'Calendar '.$currentCalendarId.' not found for calendar instance '.(string) $calendarInstance['_id']
                );

                continue;
            }

            $calendar = $calendars[$currentCalendarId];

            $components = (array) $calendar['components'];

            $userCalendar = [
                'id' => [ (string) $calendarInstance['calendarid'], (string) $calendarInstance['_id'] ],
                'uri' => $calendarInstance['uri'],
                'principaluri' => $calendarInstance['principaluri'],
                '{' . \Sabre\CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' =>
                    'http://sabre.io/ns/sync/' . ($calendar['synctoken'] ? $calendar['synctoken'] : '0'),
                '{http://sabredav.org/ns}sync-token' =>
                    $calendar['synctoken'] ? $calendar['synctoken'] : '0',
                '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' =>
                    new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet($components),
                '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' =>
                    new \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp($calendarInstance['transparent'] ? 'transparent' : 'opaque'),
                'share-resource-uri' => '/ns/share/' . $calendarInstance['_id'],
                'share-invitestatus' => $calendarInstance['share_invitestatus']
            ];

            // 1 = owner, 2 = readonly, 3 = readwrite
            if ($calendarInstance['access'] > 1) {
                $userCalendar['share-access'] = (int) $calendarInstance['access'];
                // read-only is for backwards compatibility.
                $userCalendar['read-only'] = (int) $calendarInstance['access'] === \Sabre\DAV\Sharing\Plugin::ACCESS_READ;
            }

            if (!$calendarInstance['displayname'] ) {
                $calendarInstance['displayname'] = '#default';
            }

            foreach($this->propertyMap as $xmlName=>$dbName) {
                $userCalendar[$xmlName] = $calendarInstance[$dbName];
            }

            $userCalendars[] = $userCalendar;
        }

        // Extract principalId from principalUri (e.g., "principals/users/123" -> "123")
        $principalUriParts = explode('/', $principalUri);
        $principalId = end($principalUriParts);

        // Reorder calendars to put the default calendar (calendarid == principalId) first
        $defaultCalendar = null;
        $otherCalendars = [];

        foreach ($userCalendars as $calendar) {
            $calendarUriParts = explode('/', $calendar['uri']);
            $calendarId = end($calendarUriParts);

            if ($calendarId === $principalId && $defaultCalendar === null) {
                $defaultCalendar = $calendar;
            } else {
                $otherCalendars[] = $calendar;
            }
        }

        if ($defaultCalendar) {
            array_unshift($otherCalendars, $defaultCalendar);
            return $otherCalendars;
        }

        return $userCalendars;
    }

    private function checkIfCalendarInstanceExist($principalUri, $calendarUri) {
        $calendar = $this->calendarInstanceDAO->findInstanceByPrincipalUriAndUri($principalUri, $calendarUri, 1);

        return isset($calendar['_id']) ? [(string) $calendar['calendarid'], (string) $calendar['_id']] : false;
    }

    function createCalendar($principalUri, $calendarUri, array $properties) {
        $calendar = $this->checkIfCalendarInstanceExist($principalUri, $calendarUri);

        if ($calendar) {
            return $calendar;
        }
        $sccs = '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set';

        // Insert in calendars collection
        $obj = [
          'synctoken' => 1
        ];
        if (!isset($properties[$sccs])) {
            // Default value
            $obj['components'] = ['VEVENT', 'VTODO'];
        } else {
            if (!($properties[$sccs] instanceof \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet)) {
                throw new \Sabre\DAV\Exception('The ' . $sccs . ' property must be of type: \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet');
            }
            $obj['components'] = $properties[$sccs]->getValue();
        }

        $calendarId = $this->calendarDAO->createCalendar($obj);

        // Insert in calendarinstances collection
        $obj = [
            'principaluri' => $principalUri,
            'uri' => $calendarUri,
            'transparent' => 0,
            'access' => 1,
            'share_invitestatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED,
            'calendarid' => new \MongoDB\BSON\ObjectId($calendarId)
        ];

        $transp = '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp';
        if (isset($properties[$transp])) {
            $obj['transparent'] = $properties[$transp]->getValue() === 'transparent';
        }
        foreach($this->propertyMap as $xmlName=>$dbName) {
            if (isset($properties[$xmlName])) {
                $obj[$dbName] = $properties[$xmlName];
            } else {
                $obj[$dbName] = null;
            }
        }

        if ($this->isPrincipalResource($obj['principaluri'])) {
            $obj['public_right'] = self::RESOURCE_CALENDAR_PUBLIC_PRIVILEGE;
        }

        $instanceId = $this->calendarInstanceDAO->createInstance($obj);

        $this->eventEmitter->emit('esn:calendarCreated', [$this->getCalendarPath($principalUri, $calendarUri)]);

        return [$calendarId, $instanceId];
    }

    private function isPrincipalResource($principalUri) {
        if (!$principalUri) {
            return false;
        }

        $uriExploded = explode('/', $principalUri);

        return $uriExploded[1] === 'resources';
    }

    private function getCalendarPath($principalUri, $calendarUri) {
        $uriExploded = explode('/', $principalUri);

        return '/calendars/' . $uriExploded[2] . '/' . $calendarUri;
    }

    public function getCalendarPath($principalUri, $calendarUri) {
        $uriExploded = explode('/', $principalUri);
        return '/calendars/' . $uriExploded[2] . '/' . $calendarUri;
    }

    function updateCalendar($calendarId, \Sabre\DAV\PropPatch $propPatch) {
        $this->_assertIsArray($calendarId);

        list($calendarId, $instanceId) = $calendarId;

        $supportedProperties = array_keys($this->propertyMap);
        $supportedProperties[] = '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp';

        $propPatch->handle($supportedProperties, function($mutations) use ($calendarId, $instanceId) {
            $newValues = [];
            foreach($mutations as $propertyName=>$propertyValue) {

                switch($propertyName) {
                    case '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' :
                        $fieldName = 'transparent';
                        $newValues[$fieldName] = $propertyValue->getValue()==='transparent';
                        break;
                    default :
                        $fieldName = $this->propertyMap[$propertyName];
                        $newValues[$fieldName] = $propertyValue;
                        break;
                }

            }

            $this->calendarInstanceDAO->updateInstanceById($instanceId, $newValues);
            $this->addChange($calendarId, "", 2);

            $projection = [
                'uri' => 1,
                'principaluri' => 1
            ];
            $row = $this->calendarInstanceDAO->findInstanceById($instanceId, $projection);

            $this->eventEmitter->emit('esn:calendarUpdated', [$this->getCalendarPath($row['principaluri'], $row['uri'])]);

            return true;

        });

    }

    function deleteCalendar($calendarIdArray) {
        $this->_assertIsArray($calendarIdArray);

        list($calendarId, $instanceId) = $calendarIdArray;

        $row = $this->calendarInstanceDAO->findInstanceById($instanceId);

        if ((int) $row['access'] === \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER) {
            $currentInvites = $this->getInvites($calendarIdArray);

            foreach($currentInvites as $sharee) {
                $sharee->access = \Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS;
            }

            $this->updateInvites($calendarIdArray, $currentInvites);
            $this->deleteSubscribers($row['principaluri'], $row['uri']);

            $this->calendarObjectDAO->deleteAllObjectsByCalendarId($calendarId);
            $this->calendarChangeDAO->deleteChangesByCalendarId($calendarId);
            $this->calendarInstanceDAO->deleteInstancesByCalendarId($calendarId);
            $this->calendarDAO->deleteById($calendarId);
        } else {
            $this->calendarInstanceDAO->deleteInstanceById($instanceId);
        }

        $this->eventEmitter->emit('esn:calendarDeleted', [$this->getCalendarPath($row['principaluri'], $row['uri'])]);
    }

    function deleteSubscribers($principaluri, $uri) {
        $principalUriExploded = explode('/', $principaluri);
        $source = 'calendars/' . $principalUriExploded[2] . '/' . $uri;

        $subscriptions = $this->getSubscribers($source);
        foreach($subscriptions as $subscription) {
            $this->deleteSubscription($subscription['_id']);
        }
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
        $calendarUris = $this->getCalendarInstancesByPrincipalUri($principalUri);
        if (empty($calendarUris)) return null;

        $projection = [
            'uri' => 1,
            'calendarid' => 1
        ];
        $objrow = $this->calendarObjectDAO->findByUid(array_keys($calendarUris), $uid, $projection);
        if (!$objrow) return null;

        return $calendarUris[(string) $objrow['calendarid']] . '/' . $objrow['uri'];
    }

    function getDuplicateCalendarObjectsByURI($principalUri, $uri) {
        $calendarUris = $this->getCalendarInstancesByPrincipalUri($principalUri);

        if (empty($calendarUris)) return null;

        // find the uid of the event having the provided URI
        $projection = ['uid' => 1];
        $objrow = $this->calendarObjectDAO->findByUri(array_keys($calendarUris), $uri, $projection);

        if (!$objrow) return [];

        // find the events having the found uid.
        $projection = [
            'uri' => 1,
            'calendarid' => 1
        ];
        $objrows = $this->calendarObjectDAO->findByUidMultiple(array_keys($calendarUris), $objrow['uid'], $projection);
        $result = [];

        foreach($objrows as $row) {
            $result[] = $calendarUris[(string) $row['calendarid']] . '/' . $row['uri'];
        }

        return $result;
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
        $fields = array_values($this->subscriptionPropertyMap);
        $fields[] = '_id';
        $fields[] = 'uri';
        $fields[] = 'source';
        $fields[] = 'principaluri';
        $fields[] = 'lastmodified';

        $sort = ['calendarorder' => 1];
        $res = $this->calendarSubscriptionDAO->findByPrincipalUri($principalUri, $fields, $sort);

        $subscriptions = [];
        foreach ($res as $row) {
            $subscription = [
                'id'           => (string) $row['_id'],
                'uri'          => $row['uri'],
                'principaluri' => $row['principaluri'],
                'source'       => $row['source'],
                'lastmodified' => $row['lastmodified'],

                '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' =>
                    new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(['VTODO', 'VEVENT']),
            ];

            foreach($this->subscriptionPropertyMap as $xmlName=>$dbName) {
                if (!is_null($row[$dbName])) {
                    $subscription[$xmlName] = $row[$dbName];
                }
            }

            $subscriptions[] = $subscription;

        }

        return $subscriptions;

    }

    function createSubscription($principalUri, $uri, array $properties) {
        if (!isset($properties['{http://calendarserver.org/ns/}source'])) {
            throw new \Sabre\DAV\Exception\Forbidden('The {http://calendarserver.org/ns/}source property is required when creating subscriptions');
        }

        $obj = [
            'principaluri' => $principalUri,
            'uri'          => $uri,
            'source'       => $properties['{http://calendarserver.org/ns/}source']->getHref(),
            'lastmodified' => time(),
        ];

        foreach($this->subscriptionPropertyMap as $xmlName=>$dbName) {
            if (isset($properties[$xmlName])) {
                $obj[$dbName] = $properties[$xmlName];
            } else {
                $obj[$dbName] = null;
            }
        }

        $subscriptionId = $this->calendarSubscriptionDAO->createSubscription($obj);

        $this->eventEmitter->emit('esn:subscriptionCreated', [$this->getCalendarPath($principalUri, $uri)]);

        return $subscriptionId;
    }

    function updateSubscription($subscriptionId, \Sabre\DAV\PropPatch $propPatch) {
        $supportedProperties = array_keys($this->subscriptionPropertyMap);
        $supportedProperties[] = '{http://calendarserver.org/ns/}source';

        $propPatch->handle($supportedProperties, function($mutations) use ($subscriptionId) {
            $newValues = [];
            $newValues['lastmodified'] = time();

            foreach($mutations as $propertyName=>$propertyValue) {
                if ($propertyName === '{http://calendarserver.org/ns/}source') {
                    $newValues['source'] = $propertyValue->getHref();
                } else {
                    $fieldName = $this->subscriptionPropertyMap[$propertyName];
                    $newValues[$fieldName] = $propertyValue;
                }

            }

            $this->calendarSubscriptionDAO->updateSubscriptionById($subscriptionId, $newValues);

            $projection = [
                'uri' => 1,
                'principaluri' => 1
            ];
            $row = $this->calendarSubscriptionDAO->findSubscriptionById($subscriptionId, $projection);

            $this->eventEmitter->emit('esn:subscriptionUpdated', [$this->getCalendarPath($row['principaluri'], $row['uri'])]);

            return true;
        });
    }

    function deleteSubscription($subscriptionId) {
        $projection = [
            'uri' => 1,
            'principaluri' => 1,
            'source' => 1
        ];
        $row = $this->calendarSubscriptionDAO->findSubscriptionById($subscriptionId, $projection);
        $this->calendarSubscriptionDAO->deleteSubscriptionById($subscriptionId);

        $this->eventEmitter->emit('esn:subscriptionDeleted', [$this->getCalendarPath($row['principaluri'], $row['uri']), '/' . $row['source']]);

    }

    function getSubscribers($source) {
        $projection = [
            '_id' => 1,
            'principaluri' => 1,
            'uri' => 1
        ];
        $res = $this->calendarSubscriptionDAO->findSubscribersBySource($source, $projection);

        $result = [];
        foreach ($res as $row) {
            $result[] = [
                '_id' => $row['_id'],
                'principaluri' => $row['principaluri'],
                'uri' => $row['uri']
            ];
        }

        return $result;
    }

    function getSchedulingObject($principalUri, $objectUri) {
        $projection = [
            'uri' => 1,
            'calendardata' => 1,
            'lastmodified' => 1,
            'etag' => 1,
            'size' => 1
        ];
        $row = $this->schedulingObjectDAO->findByPrincipalUriAndUri($principalUri, $objectUri, $projection);
        if (!$row) return null;

        return [
            'uri'          => $row['uri'],
            'calendardata' => $row['calendardata'],
            'lastmodified' => $row['lastmodified'],
            'etag'         => '"' . $row['etag'] . '"',
            'size'         => (int) $row['size'],
        ];
    }

    function getSchedulingObjects($principalUri) {
        $projection = [
            'uri' => 1,
            'calendardata' => 1,
            'lastmodified' => 1,
            'etag' => 1,
            'size' => 1
        ];

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

    function deleteSchedulingObject($principalUri, $objectUri) {
        $this->schedulingObjectDAO->deleteSchedulingObject($principalUri, $objectUri);
    }

    function createSchedulingObject($principalUri, $objectUri, $objectData) {
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

    private function getCalendarInstancesByPrincipalUri($principalUri) {
        $projection = [
            'calendarid' => 1,
            'uri' => 1,
            'access' => 1
        ];
        $calendarInstances = $this->calendarInstanceDAO->findInstancesByPrincipalUriWithAccess($principalUri, $projection);
        if (!$calendarInstances) return [];

        $calendarUris = array();
        foreach($calendarInstances as $calendarInstance) {
            // Because we do not want retrieve event from delegation
            // This check make sense only for event where I am attendee and also have a delegation
            // So we are able to retrieve event from delegation and add new event in default calendar because I am attendee
            if ($calendarInstance['access'] === 1) {
                $calendarUris[(string) $calendarInstance['calendarid']] = (string) $calendarInstance['uri'];
            }
        }

        return $calendarUris;
    }
}
