<?php

namespace ESN\CalDAV\Backend;

use \Sabre\VObject;

class Mongo extends \Sabre\CalDAV\Backend\AbstractBackend implements
    \Sabre\CalDAV\Backend\SubscriptionSupport,
    \Sabre\CalDAV\Backend\SyncSupport,
    \Sabre\CalDAV\Backend\SchedulingSupport {

    protected $db;

    public $calendarTableName = 'calendars';
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

    function __construct(\MongoDB $db) {
        $this->db = $db;
    }

    function getCalendarsForUser($principalUri) {
        $fields = array_values($this->propertyMap);
        $fields[] = '_id';
        $fields[] = 'uri';
        $fields[] = 'synctoken';
        $fields[] = 'components';
        $fields[] = 'principaluri';
        $fields[] = 'transparent';

        $collection = $this->db->selectCollection($this->calendarTableName);
        $query = [ 'principaluri' => $principalUri ];

        $res = $collection->find($query, $fields);
        $res->sort(['calendarorder' => 1]);

        $calendars = [];
        foreach ($res as $row) {
            $components = $row['components'];

            $calendar = [
                'id' => (string)$row['_id'],
                'uri' => $row['uri'],
                'principaluri' => $row['principaluri'],
                '{' . \Sabre\CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' => 'http://sabre.io/ns/sync/' . ($row['synctoken']?$row['synctoken']:'0'),
                '{http://sabredav.org/ns}sync-token' => $row['synctoken']?$row['synctoken']:'0',
                '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new \Sabre\CalDAV\Property\SupportedCalendarComponentSet($components),
                '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' => new \Sabre\CalDAV\Property\ScheduleCalendarTransp($row['transparent']?'transparent':'opaque'),
            ];

            foreach($this->propertyMap as $xmlName=>$dbName) {
                $calendar[$xmlName] = $row[$dbName];
            }

            $calendars[] = $calendar;
        }

        return $calendars;
    }

    function createCalendar($principalUri, $calendarUri, array $properties) {
        $obj = [
            'principaluri' => $principalUri,
            'uri' => $calendarUri,
            'synctoken' => 1,
            'transparent' => 0,
        ];

        // Default value
        $sccs = '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set';
        if (!isset($properties[$sccs])) {
            $obj['components'] = ['VEVENT', 'VTODO'];
        } else {
            if (!($properties[$sccs] instanceof \Sabre\CalDAV\Property\SupportedCalendarComponentSet)) {
                throw new \Sabre\DAV\Exception('The ' . $sccs . ' property must be of type: \Sabre\CalDAV\Property\SupportedCalendarComponentSet');
            }
            $obj['components'] = $properties[$sccs]->getValue();
        }
        $transp = '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp';
        if (isset($properties[$transp])) {
            $obj['transparent'] = $properties[$transp]->getValue()==='transparent';
        }

        foreach($this->propertyMap as $xmlName=>$dbName) {
            if (isset($properties[$xmlName])) {
                $obj[$dbName] = $properties[$xmlName];
            } else {
                $obj[$dbName] = null;
            }
        }

        $collection = $this->db->selectCollection($this->calendarTableName);
        $collection->insert($obj);

        return (string)$obj['_id'];
    }

    function updateCalendar($calendarId, \Sabre\DAV\PropPatch $propPatch) {
        $supportedProperties = array_keys($this->propertyMap);
        $supportedProperties[] = '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp';

        $propPatch->handle($supportedProperties, function($mutations) use ($calendarId) {
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

            $collection = $this->db->selectCollection($this->calendarTableName);
            $query = [ '_id' => new \MongoId($calendarId) ];
            $collection->update($query, [ '$set' => $newValues ]);
            $this->addChange($calendarId, "", 2);

            return true;

        });

    }

    function deleteCalendar($calendarId) {
        $mongoId = new \MongoId($calendarId);
        $collection = $this->db->selectCollection($this->calendarObjectTableName);
        $collection->remove([ 'calendarid' => $mongoId ]);

        $collection = $this->db->selectCollection($this->calendarTableName);
        $collection->remove([ '_id' => $mongoId ]);

        $collection = $this->db->selectCollection($this->calendarChangesTableName);
        $collection->remove([ '_id' => $mongoId ]);
    }

    function getCalendarObjects($calendarId) {

        $query = [ 'calendarid' => $calendarId ];
        $fields = [ '_id', 'uri', 'lastmodified', 'etag', 'calendarid', 'size', 'componenttype' ];
        $collection = $this->db->selectCollection($this->calendarObjectTableName);

        $result = [];
        foreach ($collection->find($query, $fields) as $row) {
            $result[] = [
                'id'           => (string)$row['_id'],
                'uri'          => $row['uri'],
                'lastmodified' => $row['lastmodified'],
                'etag'         => '"' . $row['etag'] . '"',
                'calendarid'   => $row['calendarid'],
                'size'         => (int)$row['size'],
                'component'    => strtolower($row['componenttype']),
            ];
        }
        return $result;
    }

    function getCalendarObject($calendarId,$objectUri) {
        $query = [ 'calendarid' => $calendarId, 'uri' => $objectUri ];
        $fields = [ '_id', 'uri', 'lastmodified', 'etag', 'calendarid', 'size', 'calendardata', 'componenttype' ];
        $collection = $this->db->selectCollection($this->calendarObjectTableName);

        $row = $collection->findOne($query, $fields);
        if (!$row) return null;

        return [
            'id'            => (string)$row['_id'],
            'uri'           => $row['uri'],
            'lastmodified'  => $row['lastmodified'],
            'etag'          => '"' . $row['etag'] . '"',
            'calendarid'    => $row['calendarid'],
            'size'          => (int)$row['size'],
            'calendardata'  => $row['calendardata'],
            'component'     => strtolower($row['componenttype']),
         ];
    }

    function getMultipleCalendarObjects($calendarId, array $uris) {
        $query = [ 'calendarid' => $calendarId, 'uri' => [ '$in' => $uris ] ];
        $fields = [ '_id', 'uri', 'lastmodified', 'etag', 'calendarid', 'size', 'calendardata', 'componenttype' ];
        $collection = $this->db->selectCollection($this->calendarObjectTableName);

        $result = [];
        foreach ($collection->find($query, $fields) as $row) {
            $result[] = [
                'id'           => (string)$row['_id'],
                'uri'          => $row['uri'],
                'lastmodified' => $row['lastmodified'],
                'etag'         => '"' . $row['etag'] . '"',
                'calendarid'   => $row['calendarid'],
                'size'         => (int)$row['size'],
                'calendardata' => $row['calendardata'],
                'component'    => strtolower($row['componenttype']),
            ];
        }
        return $result;
    }

    function createCalendarObject($calendarId,$objectUri,$calendarData) {
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
        $collection->insert($obj);
        $this->addChange($calendarId, $objectUri, 1);

        return '"' . $extraData['etag'] . '"';
    }

    function updateCalendarObject($calendarId,$objectUri,$calendarData) {
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

        $collection->update($query, $obj);
        $this->addChange($calendarId, $objectUri, 2);

        return '"' . $extraData['etag'] . '"';
    }

    function deleteCalendarObject($calendarId,$objectUri) {
        $collection = $this->db->selectCollection($this->calendarObjectTableName);
        $query = [ 'calendarid' => $calendarId, 'uri' => $objectUri ];
        $collection->remove($query);
        $this->addChange($calendarId, $objectUri, 3);
    }

    function calendarQuery($calendarId, array $filters) {
        $componentType = null;
        $requirePostFilter = true;
        $timeRange = null;

        // if no filters were specified, we don't need to filter after a query
        if (!$filters['prop-filters'] && !$filters['comp-filters']) {
            $requirePostFilter = false;
        }

        // Figuring out if there's a component filter
        if (count($filters['comp-filters']) > 0 && !$filters['comp-filters'][0]['is-not-defined']) {
            $componentType = $filters['comp-filters'][0]['name'];

            // Checking if we need post-filters
            if (!$filters['prop-filters'] && !$filters['comp-filters'][0]['comp-filters'] && !$filters['comp-filters'][0]['time-range'] && !$filters['comp-filters'][0]['prop-filters']) {
                $requirePostFilter = false;
            }
            // There was a time-range filter
            if ($componentType == 'VEVENT' && isset($filters['comp-filters'][0]['time-range'])) {
                $timeRange = $filters['comp-filters'][0]['time-range'];

                // If start time OR the end time is not specified, we can do a
                // 100% accurate mysql query.
                if (!$filters['prop-filters'] && !$filters['comp-filters'][0]['comp-filters'] && !$filters['comp-filters'][0]['prop-filters'] && (!$timeRange['start'] || !$timeRange['end'])) {
                    $requirePostFilter = false;
                }
            }
        }

        if ($requirePostFilter) {
            $fields = ['uri', 'calendardata'];
        } else {
            $fields = ['uri'];
        }
        $collection = $this->db->selectCollection($this->calendarObjectTableName);
        $query = [ 'calendarid' => $calendarId ];

        if ($componentType) {
            $query['componenttype'] = $componentType;
        }

        if ($timeRange && $timeRange['start']) {
            $query['lastoccurence'] = [ '$gt' =>  $timeRange['start']->getTimeStamp() ];
        }
        if ($timeRange && $timeRange['end']) {
            $query['firstoccurence'] = [ '$lt' => $timeRange['end']->getTimeStamp() ];
        }

        $result = [];
        foreach ($collection->find($query, $fields) as $row) {
            if ($requirePostFilter) {
                if (!$this->validateFilterForObject($row, $filters)) {
                    continue;
                }
            }
            $result[] = $row['uri'];

        }

        return $result;
    }

    function getCalendarObjectByUID($principalUri, $uid) {
        $collection = $this->db->selectCollection($this->calendarTableName);
        $query = [ 'principaluri' => $principalUri ];
        $fields = ['_id', 'uri'];

        $calrow = $collection->findOne($query, $fields);
        if (!$calrow) return null;

        $collection = $this->db->selectCollection($this->calendarObjectTableName);
        $query = [ 'calendarid' => (string)$calrow['_id'], 'uid' => $uid ];
        $fields = ['uri'];

        $objrow = $collection->findOne($query, $fields);
        if (!$objrow) return null;

        return $calrow['uri'] . '/' . $objrow['uri'];
    }


    function getChangesForCalendar($calendarId, $syncToken, $syncLevel, $limit = null) {
        // Current synctoken
        $collection = $this->db->selectCollection($this->calendarTableName);
        $mongoCalendarId = new \MongoId($calendarId);
        $fields = ['synctoken'];
        $query = [ '_id' => $mongoCalendarId ];

        $row = $collection->findOne($query, $fields);
        if (!$row || is_null($row['synctoken'])) return null;

        $currentToken = $row['synctoken'];

        $result = [
            'syncToken' => $currentToken,
            'added'     => [],
            'modified'  => [],
            'deleted'   => [],
        ];

        if ($syncToken) {

            $fields = ['uri', 'operation'];
            $collection = $this->db->selectCollection($this->calendarChangesTableName);

            $query = [ 'synctoken' => [ '$gte' => (int)$syncToken, '$lt' => (int)$currentToken ],
                       'calendarid' => $mongoCalendarId ];

            $res = $collection->find($query, $fields);
            $res->sort([ 'synctoken' => 1 ]);
            if ($limit > 0) $res->limit((int)$limit);

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
            $fields = ['uri'];

            $added = [];
            foreach ($collection->find($query, $fields) as $row) {
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
        $res = $collection->find($query, $fields);
        $res->sort(['calendarorder' => 1]);

        $subscriptions = [];
        foreach ($res as $row) {
            $subscription = [
                'id'           => (string)$row['_id'],
                'uri'          => $row['uri'],
                'principaluri' => $row['principaluri'],
                'source'       => $row['source'],
                'lastmodified' => $row['lastmodified'],

                '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' => new \Sabre\CalDAV\Property\SupportedCalendarComponentSet(['VTODO', 'VEVENT']),
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
        $collection->insert($obj);

        return (string)$obj['_id'];
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
            $query = [ '_id' => new \MongoId($subscriptionId) ];
            $collection->update($query, [ '$set' => $newValues ]);

            return true;
        });
    }

    function deleteSubscription($subscriptionId) {
        $collection = $this->db->selectCollection($this->calendarSubscriptionsTableName);
        $collection->remove([ '_id' => new \MongoId($subscriptionId) ]);
    }

    function getSchedulingObject($principalUri, $objectUri) {
        $collection = $this->db->selectCollection($this->schedulingObjectTableName);
        $query = [ 'principaluri' => $principalUri, 'uri' => $objectUri ];
        $fields = ['uri', 'calendardata', 'lastmodified', 'etag', 'size'];
        $row = $collection->findOne($query, $fields);
        if (!$row) return null;

        return [
            'uri'          => $row['uri'],
            'calendardata' => $row['calendardata'],
            'lastmodified' => $row['lastmodified'],
            'etag'         => '"' . $row['etag'] . '"',
            'size'         => (int)$row['size'],
        ];
    }

    function getSchedulingObjects($principalUri) {
        $collection = $this->db->selectCollection($this->schedulingObjectTableName);
        $query = [ 'principaluri' => $principalUri ];
        $fields = ['uri', 'calendardata', 'lastmodified', 'etag', 'size'];

        $result = [];
        foreach($collection->find($query, $fields) as $row) {
            $result[] = [
                'calendardata' => $row['calendardata'],
                'uri'          => $row['uri'],
                'lastmodified' => $row['lastmodified'],
                'etag'         => '"' . $row['etag'] . '"',
                'size'         => (int)$row['size'],
            ];
        }

        return $result;
    }

    function deleteSchedulingObject($principalUri, $objectUri) {
        $collection = $this->db->selectCollection($this->schedulingObjectTableName);
        $query = [ 'principaluri' => $principalUri, 'uri' => $objectUri ];
        $collection->remove($query);
    }

    function createSchedulingObject($principalUri, $objectUri, $objectData) {
        $collection = $this->db->selectCollection($this->schedulingObjectTableName);
        $obj = [
            'principaluri' => $principalUri,
            'calendardata' => $objectData,
            'uri' => $objectUri,
            'lastmodified' => time(),
            'etag' => md5($objectData),
            'size' => strlen($objectData)
        ];
        $collection->insert($obj);
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
                $uid = (string)$component->UID;
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
                $it = new VObject\RecurrenceIterator($vObject, (string)$component->UID);
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
        $mongoCalendarId = new \MongoId($calendarId);
        $fields = ['synctoken'];
        $query = [ '_id' => $mongoCalendarId ];
        $res = $calcollection->findOne($query, $fields);

        $changecollection = $this->db->selectCollection($this->calendarChangesTableName);
        $obj = [
            'uri' => $objectUri,
            'synctoken' => $res['synctoken'],
            'calendarid' => $mongoCalendarId,
            'operation' => $operation
        ];
        $changecollection->insert($obj);

        $update = [ '$inc' => [ 'synctoken' => 1 ] ];
        $calcollection->update($query, $update);
    }
}
