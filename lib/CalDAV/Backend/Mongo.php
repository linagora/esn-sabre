<?php

namespace ESN\CalDAV\Backend;

use \Sabre\VObject;
use Sabre\Event\EventEmitter;

class Mongo extends \Sabre\CalDAV\Backend\AbstractBackend implements
    \Sabre\CalDAV\Backend\SubscriptionSupport,
    \Sabre\CalDAV\Backend\SyncSupport,
    \Sabre\CalDAV\Backend\SchedulingSupport,
    \Sabre\CalDAV\Backend\SharingSupport {

    protected $db;
    protected $eventEmitter;
    protected $schedulingObjectTTLInDays;

    public $calendarTableName = 'calendars';
    public $calendarInstancesTableName = 'calendarinstances';
    public $calendarObjectTableName = 'calendarobjects';
    public $calendarChangesTableName = 'calendarchanges';
    public $schedulingObjectTableName = 'schedulingobjects';
    public $calendarSubscriptionsTableName = 'calendarsubscriptions';

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

        $collection = $this->db->selectCollection($this->calendarInstancesTableName);

        $query = [ 'principaluri' => $principalUri ];
        $projection = array_fill_keys($fields, 1);
        $options = [
            'projection' => $projection,
            'sort' => ['calendarorder' => 1]
        ];

        $res = $collection->find($query, $options);

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

        $collection = $this->db->selectCollection($this->calendarTableName);
        $query = [ '_id' => [ '$in' => $calendarIds ] ];
        $projection = [
            '_id' => 1,
            'synctoken' => 1,
            'components' => 1
        ];
        $result = $collection->find($query, [ 'projection' => $projection ]);

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
        $collection = $this->db->selectCollection($this->calendarInstancesTableName);
        $query = [
            'principaluri' => $principalUri,
            'uri' => $calendarUri,
            'access' => 1
        ];
        $projection = [
            '_id' => 1,
            'calendarid' => 1
        ];
        $calendar = $collection->findOne($query, [ 'projection' => $projection ]);

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

        $collection = $this->db->selectCollection($this->calendarTableName);
        $insertResult = $collection->insertOne($obj);
        $calendarId = (string) $insertResult->getInsertedId();

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

        $collection = $this->db->selectCollection($this->calendarInstancesTableName);
        $insertResult = $collection->insertOne($obj);

        $this->eventEmitter->emit('esn:calendarCreated', [$this->getCalendarPath($principalUri, $calendarUri)]);

        return [$calendarId, (string) $insertResult->getInsertedId()];
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

            $collection = $this->db->selectCollection($this->calendarInstancesTableName);
            $query = [ '_id' => new \MongoDB\BSON\ObjectId($instanceId) ];
            $collection->updateOne($query, [ '$set' => $newValues ]);
            $this->addChange($calendarId, "", 2);

            $collection = $this->db->selectCollection($this->calendarInstancesTableName);
            $query = [ '_id' => new \MongoDB\BSON\ObjectId($instanceId) ];
            $projection = [
                'uri' => 1,
                'principaluri' => 1
            ];
            $row = $collection->findOne($query, [ 'projection' => $projection ]);

            $this->eventEmitter->emit('esn:calendarUpdated', [$this->getCalendarPath($row['principaluri'], $row['uri'])]);

            return true;

        });

    }

    function deleteCalendar($calendarIdArray) {
        $this->_assertIsArray($calendarIdArray);

        list($calendarId, $instanceId) = $calendarIdArray;
        $mongoId = new \MongoDB\BSON\ObjectId($calendarId);
        $mongoInstanceId = new \MongoDB\BSON\ObjectId($instanceId);

        $collection = $this->db->selectCollection($this->calendarInstancesTableName);
        $query = [ '_id' => $mongoInstanceId ];
        $row = $collection->findOne($query);

        if ((int) $row['access'] === \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER) {
            $currentInvites = $this->getInvites($calendarIdArray);

            foreach($currentInvites as $sharee) {
                $sharee->access = \Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS;
            }

            $this->updateInvites($calendarIdArray, $currentInvites);
            $this->deleteSubscribers($row['principaluri'], $row['uri']);

            $collection = $this->db->selectCollection($this->calendarObjectTableName);
            $collection->deleteMany([ 'calendarid' => $mongoId ]);

            $collection = $this->db->selectCollection($this->calendarChangesTableName);
            $collection->deleteMany([ 'calendarid' => $mongoId ]);

            $collection = $this->db->selectCollection($this->calendarInstancesTableName);
            $collection->deleteMany([ 'calendarid' => $mongoId ]);

            $collection = $this->db->selectCollection($this->calendarTableName);
            $collection->deleteMany([ '_id' => $mongoId ]);
        } else {
            $collection = $this->db->selectCollection($this->calendarInstancesTableName);
            $collection->deleteMany([ '_id' => $mongoInstanceId ]);
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

        $collection = $this->db->selectCollection($this->calendarObjectTableName);
        $query = [ 'calendarid' => $calendarId ];
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
        foreach ($collection->find($query, [ 'projection' => $projection ]) as $row) {
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

        $collection = $this->db->selectCollection($this->calendarObjectTableName);
        $query = [ 'calendarid' => $calendarId, 'uri' => [ '$in' => $uris ] ];
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
        foreach ($collection->find($query, [ 'projection' => $projection ]) as $row) {
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

        $collection = $this->db->selectCollection($this->calendarObjectTableName);
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
        $collection->insertOne($obj);
        $this->addChange($calendarId, $objectUri, 1);

        return '"' . $extraData['etag'] . '"';
    }

    function updateCalendarObject($calendarId, $objectUri, $calendarData) {
        $this->_assertIsArray($calendarId);

        $calendarId = $calendarId[0];

        $extraData = $this->getDenormalizedData($calendarData);
        $collection = $this->db->selectCollection($this->calendarObjectTableName);

        $query = [ 'calendarid' => $calendarId, 'uri' => $objectUri ];
        $obj = [ '$set' => [
            'calendardata' => $calendarData,
            'lastmodified' => time(),
            'etag' => $extraData['etag'],
            'size' => $extraData['size'],
            'componenttype' => $extraData['componentType'],
            'firstoccurence' => $extraData['firstOccurence'],
            'lastoccurence' => $extraData['lastOccurence'],
            'uid' => $extraData['uid'],
        ] ];

        $collection->updateMany($query, $obj);
        $this->addChange($calendarId, $objectUri, 2);

        return '"' . $extraData['etag'] . '"';
    }

    function deleteCalendarObject($calendarId, $objectUri) {
        $this->_assertIsArray($calendarId);

        $calendarId = $calendarId[0];

        $collection = $this->db->selectCollection($this->calendarObjectTableName);
        $query = [ 'calendarid' => $calendarId, 'uri' => $objectUri ];
        $collection->deleteMany($query);
        $this->addChange($calendarId, $objectUri, 3);
    }

    function calendarQuery($calendarId, array $filters) {
        $this->_assertIsArray($calendarId);

        $calendarId = $calendarId[0];

        $componentType = null;
        $requirePostFilter = true;
        $timeRange = null;

        // if no filters were specified, we don't need to filter after a query
        if (empty($filters['prop-filters']) && empty($filters['comp-filters'])) {
            $requirePostFilter = false;
        }

        // Figuring out if there's a component filter
        if (!empty($filters['comp-filters']) && is_array($filters['comp-filters']) && count($filters['comp-filters']) > 0 && !$filters['comp-filters'][0]['is-not-defined']) {
            $componentType = $filters['comp-filters'][0]['name'];

            // Checking if we need post-filters
            if (empty($filters['prop-filters']) && empty($filters['comp-filters'][0]['comp-filters']) && empty($filters['comp-filters'][0]['time-range']) && empty($filters['comp-filters'][0]['prop-filters'])) {
                $requirePostFilter = false;
            }
            // There was a time-range filter
            if ($componentType == 'VEVENT' && isset($filters['comp-filters'][0]['time-range']) && is_array($filters['comp-filters'][0]['time-range'])) {
                $timeRange = $filters['comp-filters'][0]['time-range'];

                // If start time OR the end time is not specified, we can do a
                // 100% accurate mysql query.
                if (empty($filters['prop-filters']) && empty($filters['comp-filters'][0]['comp-filters']) && empty($filters['comp-filters'][0]['prop-filters']) && (empty($timeRange['start']) || empty($timeRange['end']))) {
                    $requirePostFilter = false;
                }
            }
        }

        if ($requirePostFilter) {
            $projection = [ 'uri' => 1 , 'calendardata' => 1 ];
        } else {
            $projection = [ 'uri' => 1 ];
        }
        $collection = $this->db->selectCollection($this->calendarObjectTableName);
        $query = [ 'calendarid' => $calendarId ];

        if ($componentType) {
            $query['componenttype'] = $componentType;
        }

        if ($timeRange && $timeRange['start']) {
            $query['lastoccurence'] = [ '$gte' =>  $timeRange['start']->getTimeStamp() ];
        }
        if ($timeRange && $timeRange['end']) {
            $query['firstoccurence'] = [ '$lt' => $timeRange['end']->getTimeStamp() ];
        }

        $result = [];
        foreach ($collection->find($query, [ 'projection' => $projection ]) as $row) {
            if ($requirePostFilter) {
                // Ensure calendardata is properly passed to avoid sequential DB reads
                $object = [
                    'calendarid' => $calendarId,
                    'uri' => $row['uri'],
                    'calendardata' => $row['calendardata']
                ];
                if (!$this->validateFilterForObject($object, $filters)) {
                    continue;
                }
            }
            $result[] = $row['uri'];

        }

        return $result;
    }

    function getCalendarObjectByUID($principalUri, $uid) {
        $calendarUris = $this->getCalendarInstancesByPrincipalUri($principalUri);
        if (empty($calendarUris)) return null;

        $collection = $this->db->selectCollection($this->calendarObjectTableName);
        $query = ['calendarid' => ['$in' => array_keys($calendarUris)] , 'uid' => $uid ];
        $projection = [
            'uri' => 1,
            'calendarid' => 1
        ];
        $objrow = $collection->findOne($query, [ 'projection' => $projection ]);
        if (!$objrow) return null;

        return $calendarUris[(string) $objrow['calendarid']] . '/' . $objrow['uri'];
    }

    function getDuplicateCalendarObjectsByURI($principalUri, $uri) {
        $calendarUris = $this->getCalendarInstancesByPrincipalUri($principalUri);

        if (empty($calendarUris)) return null;

        // find the uid of the event having the provided URI
        $collection = $this->db->selectCollection($this->calendarObjectTableName);

        $query = ['calendarid' => ['$in' => array_keys($calendarUris)] , 'uri' => $uri];
        $projection = ['uid' => 1];

        $objrow = $collection->findOne($query, ['projection' => $projection]);

        if (!$objrow) return [];

        // find the events having the found uid.
        $collection = $this->db->selectCollection($this->calendarObjectTableName);
        $query = ['calendarid' => ['$in' => array_keys($calendarUris)] , 'uid' => $objrow['uid']];
        $projection = [
            'uri' => 1,
            'calendarid' => 1
        ];
        $objrows = $collection->find($query, ['projection' => $projection]);
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
        $collection = $this->db->selectCollection($this->calendarTableName);
        $mongoCalendarId = new \MongoDB\BSON\ObjectId($calendarId);
        $projection = [ 'synctoken' => 1 ];
        $query = [ '_id' => $mongoCalendarId ];
        $row = $collection->findOne($query, [ 'projection' => $projection ]);
        if (!$row || is_null($row['synctoken'])) return null;

        $currentToken = $row['synctoken'];

        $result = [
            'syncToken' => $currentToken,
            'added'     => [],
            'modified'  => [],
            'deleted'   => [],
        ];

        if ($syncToken) {

            $projection = [
                'uri' => 1,
                'operation' => 1
            ];
            $collection = $this->db->selectCollection($this->calendarChangesTableName);

            $query = [ 'synctoken' => [ '$gte' => (int) $syncToken, '$lt' => (int) $currentToken ],
                       'calendarid' => $mongoCalendarId ];

            $options = [
                'projection' => $projection,
                'sort' => [ 'synctoken' => 1 ]
            ];

            if ($limit > 0) $options['limit'] = $limit;

            $res = $collection->find($query, $options);

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
            $collection = $this->db->selectCollection($this->calendarObjectTableName);
            $query = [ 'calendarid' => $calendarId ];
            $projection = [ 'uri' => 1 ];

            $added = [];
            foreach ($collection->find($query, $projection) as $row) {
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

        // Making fields a comma-delimited list
        $collection = $this->db->selectCollection($this->calendarSubscriptionsTableName);

        $query = [ 'principaluri' => $principalUri ];
        $projection = array_fill_keys($fields, 1);
        $options = [
            'projection' => $projection,
            'sort' => ['calendarorder' => 1]
        ];

        $res = $collection->find($query, $options);

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

        $collection = $this->db->selectCollection($this->calendarSubscriptionsTableName);
        $insertResult = $collection->insertOne($obj);

        $this->eventEmitter->emit('esn:subscriptionCreated', [$this->getCalendarPath($principalUri, $uri)]);

        return (string) $insertResult->getInsertedId();
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

            $collection = $this->db->selectCollection($this->calendarSubscriptionsTableName);
            $query = [ '_id' => new \MongoDB\BSON\ObjectId($subscriptionId) ];
            $projection = [
                'uri' => 1,
                'principaluri' => 1
            ];
            $collection->updateMany($query, [ '$set' => $newValues ]);

            $row = $collection->findOne($query, [ 'projection' => $projection ]);

            $this->eventEmitter->emit('esn:subscriptionUpdated', [$this->getCalendarPath($row['principaluri'], $row['uri'])]);

            return true;
        });
    }

    function deleteSubscription($subscriptionId) {
        $collection = $this->db->selectCollection($this->calendarSubscriptionsTableName);
        $query = [ '_id' => new \MongoDB\BSON\ObjectId($subscriptionId) ];
        $projection = [
            'uri' => 1,
            'principaluri' => 1,
            'source' => 1
        ];
        $row = $collection->findOne($query, [ 'projection' => $projection ]);
        $collection->deleteMany($query);

        $this->eventEmitter->emit('esn:subscriptionDeleted', [$this->getCalendarPath($row['principaluri'], $row['uri']), '/' . $row['source']]);

    }

    function getSubscribers($source) {
        $collection = $this->db->selectCollection($this->calendarSubscriptionsTableName);
        $projection = [
            '_id' => 1,
            'principaluri' => 1,
            'uri' => 1
        ];
        $query = [ 'source' => $source ];
        $res = $collection->find($query, [ 'projection' => $projection ]);

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
        $collection = $this->db->selectCollection($this->schedulingObjectTableName);
        $query = [ 'principaluri' => $principalUri, 'uri' => $objectUri ];
        $projection = [
            'uri' => 1,
            'calendardata' => 1,
            'lastmodified' => 1,
            'etag' => 1,
            'size' => 1
        ];
        $row = $collection->findOne($query, [ 'projection' => $projection ]);
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
        $collection = $this->db->selectCollection($this->schedulingObjectTableName);
        $query = [ 'principaluri' => $principalUri ];
        $projection = [
            'uri' => 1,
            'calendardata' => 1,
            'lastmodified' => 1,
            'etag' => 1,
            'size' => 1
        ];

        $result = [];
        foreach($collection->find($query, [ 'projection' => $projection ]) as $row) {
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
        $collection = $this->db->selectCollection($this->schedulingObjectTableName);
        $query = [ 'principaluri' => $principalUri, 'uri' => $objectUri ];
        $collection->deleteMany($query);
    }

    function createSchedulingObject($principalUri, $objectUri, $objectData) {
        $collection = $this->db->selectCollection($this->schedulingObjectTableName);
        $obj = [
            'principaluri' => $principalUri,
            'calendardata' => $objectData,
            'uri' => $objectUri,
            'lastmodified' => time(),
            'etag' => md5($objectData),
            'size' => strlen($objectData),
            'dateCreated' => new \MongoDB\BSON\UTCDateTime(time() * 1000)
        ];
        $collection->insertOne($obj);
    }

    function updateInvites($calendarId, array $sharees) {
        $this->_assertIsArray($calendarId);

        $calendarInstance = [];

        $currentInvites = $this->getInvites($calendarId);
        list($calendarId, $instanceId) = $calendarId;
        $mongoCalendarId = new \MongoDB\BSON\ObjectId($calendarId);
        $mongoInstanceId = new \MongoDB\BSON\ObjectId($instanceId);

        $collection = $this->db->selectCollection($this->calendarInstancesTableName);
        $existingInstance = $collection->findOne([ '_id' => $mongoInstanceId ], ['projection' => [ '_id' => 0 ]]);

        foreach($sharees as $sharee) {
            if ($sharee->access === \Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS) {
                // TODO access === 2 || access === 3
                $uri = $collection->findone([ 'calendarid' => $mongoCalendarId, 'share_href' => $sharee->href ], [ 'projection' => [ 'uri' => 1 ]] );
                $collection->deleteMany([ 'calendarid' => $mongoCalendarId, 'share_href' => $sharee->href ]);

                $calendarInstances[] = [
                    'uri' => $uri['uri'],
                    'type' => 'delete',
                    'sharee' => $sharee
                ];

                continue;
            }

            if (is_null($sharee->principal)) {
                $sharee->inviteStatus = \Sabre\DAV\Sharing\Plugin::INVITE_INVALID;
            } else {
                $sharee->inviteStatus = \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED;
            }

            foreach($currentInvites as $oldSharee) {
                if ($oldSharee->href === $sharee->href) {
                    $sharee->properties = array_merge($oldSharee->properties, $sharee->properties);
                    $collection->updateMany([ 'calendarid' => $mongoCalendarId, 'share_href' => $sharee->href ], [ '$set' => [
                        'access' => $sharee->access,
                        'share_displayname' => isset($sharee->properties['{DAV:}displayname']) ? $sharee->properties['{DAV:}displayname'] : null,
                        'share_invitestatus' => $sharee->inviteStatus ?: $oldSharee->inviteStatus
                    ] ]);

                    $query = [ 'calendarid' => $mongoCalendarId, 'share_href' => $sharee->href ];
                    $uri = $collection->findone($query, [ 'projection' => [ 'uri' => 1 ] ]);

                    $calendarInstances[] = [
                        'uri' => $uri['uri'],
                        'type' => 'update',
                        'sharee' => $sharee
                    ];

                    continue 2;
                }
            }

            $existingInstance['calendarid'] = $mongoCalendarId;
            $existingInstance['principaluri'] = $sharee->principal;
            $existingInstance['access'] = $sharee->access;
            $existingInstance['uri'] = \Sabre\DAV\UUIDUtil::getUUID();
            $existingInstance['share_href'] = $sharee->href;
            $existingInstance['share_displayname'] = isset($sharee->properties['{DAV:}displayname']) ? $sharee->properties['{DAV:}displayname'] : null;
            $existingInstance['share_invitestatus'] = $sharee->inviteStatus ?: \Sabre\DAV\Sharing\Plugin::INVITE_NORESPONSE;
            $collection->insertOne($existingInstance);

            $calendarInstances[] = [
                'uri' => $existingInstance['uri'],
                'type' => 'create',
                'sharee' => $sharee
            ];
        }

        $this->eventEmitter->emit('esn:updateSharees', [$calendarInstances]);
    }

    function getInvites($calendarId) {
        $this->_assertIsArray($calendarId);

        $calendarId = $calendarId[0];
        $mongoCalendarId = new \MongoDB\BSON\ObjectId($calendarId);

        $collection = $this->db->selectCollection($this->calendarInstancesTableName);
        $projection = [
            'principaluri' => 1,
            'access' => 1,
            'share_href' => 1,
            'share_invitestatus' => 1,
            'share_displayname' => 1
        ];
        $query = [ 'calendarid' => $mongoCalendarId ];
        $res = $collection->find($query, [ 'projection' => $projection ]);
        $result = [];
        foreach ($res as $row) {
            if ($row['share_invitestatus'] === \Sabre\DAV\Sharing\Plugin::INVITE_INVALID) {
                continue;
            }

            $result[] = new \Sabre\DAV\Xml\Element\Sharee([
                'href' => isset($row['share_href']) ? $row['share_href'] : \Sabre\HTTP\encodePath($row['principaluri']),
                'access' => (int) $row['access'],
                'inviteStatus' => (int) $row['share_invitestatus'],
                'properties' => !empty($row['share_displayname']) ? [ '{DAV:}displayname' => $row['share_displayname'] ] : [],
                'principal' => $row['principaluri']
            ]);
        }

        return $result;
    }

    function prepareRequestForCalendarPublicRight($calendarId) {
        $this->_assertIsArray($calendarId);

        $mongoCalendarId = new \MongoDB\BSON\ObjectId($calendarId[0]);

        return [$this->db->selectCollection($this->calendarInstancesTableName), ['calendarid' => $mongoCalendarId]];
    }

    function saveCalendarPublicRight($calendarId, $privilege, $calendarInfo) {
        list($collection, $query) = $this->prepareRequestForCalendarPublicRight($calendarId);

        $collection->updateMany($query, ['$set' => ['public_right' => $privilege]]);

        if (!in_array($privilege, ['{DAV:}read', '{DAV:}write'])) {
            $this->eventEmitter->emit('esn:updatePublicRight', [$this->getCalendarPath($calendarInfo['principaluri'], $calendarInfo['uri']), false]);
            $this->deleteSubscribers($calendarInfo['principaluri'], $calendarInfo['uri']);
        } else {
            $this->eventEmitter->emit('esn:updatePublicRight', [$this->getCalendarPath($calendarInfo['principaluri'], $calendarInfo['uri'])]);
        }
    }

    function saveCalendarInviteStatus($calendarId, $status) {
        $this->_assertIsArray($calendarId);

        $mongoCalendarId = new \MongoDB\BSON\ObjectId($calendarId[1]);

        $collection =$this->db->selectCollection($this->calendarInstancesTableName);
        $query = ['_id' => $mongoCalendarId];

        $collection->updateMany($query, ['$set' => ['share_invitestatus' => $status]]);
    }

    function getCalendarPublicRight($calendarId) {
        list($collection, $query) = $this->prepareRequestForCalendarPublicRight($calendarId);

        $mongoRes = $collection->findOne($query, [ 'projection' => [ 'public_right' => 1 ] ]);

        return isset($mongoRes['public_right']) ? $mongoRes['public_right'] : null;
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
        $vObject = VObject\Reader::read($calendarData);
        $componentType = null;
        $component = null;
        $firstOccurence = null;
        $lastOccurence = null;
        $uid = null;
        foreach($vObject->getComponents() as $component) {
            if ($component->name!=='VTIMEZONE') {
                $componentType = $component->name;
                $uid = (string) $component->UID;
                break;
            }
        }
        if (!$componentType) {
            throw new \Sabre\DAV\Exception\BadRequest('Calendar objects must have a VJOURNAL, VEVENT or VTODO component');
        }
        if ($componentType === 'VEVENT') {
            $firstOccurence = $component->DTSTART->getDateTime()->getTimeStamp();
            // Finding the last occurence is a bit harder
            if (!isset($component->RRULE)) {
                if (isset($component->DTEND)) {
                    $lastOccurence = $component->DTEND->getDateTime()->getTimeStamp();
                } elseif (isset($component->DURATION)) {
                    $endDate = clone $component->DTSTART->getDateTime();
                    $endDate->add(VObject\DateTimeParser::parse($component->DURATION->getValue()));
                    $lastOccurence = $endDate->getTimeStamp();
                } elseif (!$component->DTSTART->hasTime()) {
                    $endDate = clone $component->DTSTART->getDateTime();
                    $endDate->modify('+1 day');
                    $lastOccurence = $endDate->getTimeStamp();
                } else {
                    $lastOccurence = $firstOccurence;
                }
            } else {
                $it = new VObject\Recur\EventIterator($vObject, (string) $component->UID);
                $maxDate = new \DateTime(self::MAX_DATE);
                if ($it->isInfinite()) {
                    $lastOccurence = $maxDate->getTimeStamp();
                } else {
                    $end = $it->getDtEnd();
                    while($it->valid() && $end < $maxDate) {
                        $end = $it->getDtEnd();
                        $it->next();

                    }
                    $lastOccurence = $end->getTimeStamp();
                }

            }
        }

        return [
            'etag' => md5($calendarData),
            'size' => strlen($calendarData),
            'componentType' => $componentType,
            'firstOccurence' => $firstOccurence,
            'lastOccurence'  => $lastOccurence,
            'uid' => $uid,
        ];

    }

    protected function addChange($calendarId, $objectUri, $operation) {
        $calcollection = $this->db->selectCollection($this->calendarTableName);
        $mongoCalendarId = new \MongoDB\BSON\ObjectId($calendarId);
        $query = [ '_id' => $mongoCalendarId ];
        $res = $calcollection->findOne($query, [ 'projection' => [ 'synctoken' => 1 ] ] );

        $changecollection = $this->db->selectCollection($this->calendarChangesTableName);
        $obj = [
            'uri' => $objectUri,
            'synctoken' => $res['synctoken'],
            'calendarid' => $mongoCalendarId,
            'operation' => $operation
        ];
        $changecollection->insertOne($obj);

        $update = [ '$inc' => [ 'synctoken' => 1 ] ];
        $calcollection->updateOne($query, $update);
    }

    private function ensureIndex() {
        // create a unique compound index on 'principaluri' and 'uri' for calendar instance collection
        // Avoid calendar instances duplication
        $calendarInstanceCollection = $this->db->selectCollection($this->calendarInstancesTableName);
        $calendarInstanceCollection->createIndex(
            array('principaluri' => 1, 'uri' => 1),
            array('unique' => true)
        );

        if (isset($this->schedulingObjectTTLInDays) && $this->schedulingObjectTTLInDays !== 0) {
            // Create a TTL index that expires after a period of time on 'dateCreated' in the 'schedulingobjects' collection.
            $schedulingObjectCollection = $this->db->selectCollection($this->schedulingObjectTableName);
            $schedulingObjectCollection->createIndex(
                ['dateCreated' => 1], ['expireAfterSeconds' => $this->schedulingObjectTTLInDays * 86400]
            );
        }
    }

    private function getCalendarInstancesByPrincipalUri($principalUri) {
        $collection = $this->db->selectCollection($this->calendarInstancesTableName);
        $query = ['principaluri' => $principalUri];
        $projection = [
            'calendarid' => 1,
            'uri' => 1,
            'access' => 1
        ];
        $calendarInstances = $collection->find($query, ['projection' => $projection]);
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
