<?php

namespace ESN\CalDAV\Backend\Service;

use ESN\CalDAV\Backend\DAO\CalendarDAO;
use ESN\CalDAV\Backend\DAO\CalendarInstanceDAO;
use ESN\CalDAV\Backend\DAO\CalendarObjectDAO;
use ESN\CalDAV\Backend\DAO\CalendarChangeDAO;
use Sabre\Event\EventEmitter;

/**
 * Calendar Service
 *
 * Handles calendar CRUD operations including:
 * - Creating, updating, deleting calendars
 * - Retrieving calendars for users
 * - Managing calendar instances
 * - Calendar object lookups by UID
 */
class CalendarService {
    const RESOURCE_CALENDAR_PUBLIC_PRIVILEGE = '{DAV:}read';

    private $calendarDAO;
    private $calendarInstanceDAO;
    private $calendarObjectDAO;
    private $calendarChangeDAO;
    private $eventEmitter;
    private $propertyMap;
    private $server;

    public function __construct(
        CalendarDAO $calendarDAO,
        CalendarInstanceDAO $calendarInstanceDAO,
        CalendarObjectDAO $calendarObjectDAO,
        CalendarChangeDAO $calendarChangeDAO,
        EventEmitter $eventEmitter,
        array $propertyMap,
        $server = null
    ) {
        $this->calendarDAO = $calendarDAO;
        $this->calendarInstanceDAO = $calendarInstanceDAO;
        $this->calendarObjectDAO = $calendarObjectDAO;
        $this->calendarChangeDAO = $calendarChangeDAO;
        $this->eventEmitter = $eventEmitter;
        $this->propertyMap = $propertyMap;
        $this->server = $server;
    }

    /**
     * Get all calendars for a user
     *
     * @param string $principalUri
     * @return array Array of calendar data
     */
    public function getCalendarsForUser($principalUri) {
        $instancesData = $this->fetchCalendarInstancesWithData($principalUri);
        $userCalendars = $this->formatCalendarsForUser($instancesData);
        return $this->sortCalendarsWithDefaultFirst($userCalendars, $principalUri);
    }

    /**
     * Create a new calendar
     *
     * @param string $principalUri
     * @param string $calendarUri
     * @param array $properties
     * @return array [calendarId, instanceId]
     */
    public function createCalendar($principalUri, $calendarUri, array $properties) {
        // Check if calendar already exists
        $existing = $this->checkIfCalendarInstanceExist($principalUri, $calendarUri);
        if ($existing) {
            return $existing;
        }

        $sccs = '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set';

        // Create calendar document
        $calendarObj = ['synctoken' => 1];

        if (!isset($properties[$sccs])) {
            $calendarObj['components'] = ['VEVENT', 'VTODO'];
        } else {
            if (!($properties[$sccs] instanceof \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet)) {
                throw new \Sabre\DAV\Exception('The ' . $sccs . ' property must be of type: \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet');
            }
            $calendarObj['components'] = $properties[$sccs]->getValue();
        }

        $calendarId = $this->calendarDAO->createCalendar($calendarObj);

        // Create calendar instance
        $instanceId = $this->createCalendarInstance($calendarId, $principalUri, $calendarUri, $properties);

        $this->eventEmitter->emit('esn:calendarCreated', [$this->getCalendarPath($principalUri, $calendarUri)]);

        return [$calendarId, $instanceId];
    }

    /**
     * Create a calendar instance
     *
     * @param string $calendarId Calendar ID
     * @param string $principalUri Principal URI
     * @param string $calendarUri Calendar URI
     * @param array $properties Calendar properties
     * @return string Instance ID
     */
    private function createCalendarInstance($calendarId, $principalUri, $calendarUri, array $properties) {
        $instanceObj = [
            'principaluri' => $principalUri,
            'uri' => $calendarUri,
            'transparent' => 0,
            'access' => 1,
            'share_invitestatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED,
            'calendarid' => new \MongoDB\BSON\ObjectId($calendarId)
        ];

        $transp = '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp';
        if (isset($properties[$transp])) {
            $instanceObj['transparent'] = $properties[$transp]->getValue() === 'transparent';
        }

        foreach($this->propertyMap as $xmlName => $dbName) {
            $instanceObj[$dbName] = isset($properties[$xmlName]) ? $properties[$xmlName] : null;
        }

        if ($this->isPrincipalResource($instanceObj['principaluri'])) {
            $instanceObj['public_right'] = self::RESOURCE_CALENDAR_PUBLIC_PRIVILEGE;
        }

        return $this->calendarInstanceDAO->createInstance($instanceObj);
    }

    /**
     * Update calendar properties
     *
     * @param array $calendarId [calendarId, instanceId]
     * @param \Sabre\DAV\PropPatch $propPatch
     */
    public function updateCalendar($calendarId, \Sabre\DAV\PropPatch $propPatch) {
        list($calendarId, $instanceId) = $calendarId;

        $supportedProperties = array_keys($this->propertyMap);
        $supportedProperties[] = '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp';

        $propPatch->handle($supportedProperties, function($mutations) use ($calendarId, $instanceId) {
            $newValues = [];
            foreach($mutations as $propertyName => $propertyValue) {
                switch($propertyName) {
                    case '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp':
                        $newValues['transparent'] = $propertyValue->getValue() === 'transparent';
                        break;
                    default:
                        $newValues[$this->propertyMap[$propertyName]] = $propertyValue;
                        break;
                }
            }

            $this->calendarInstanceDAO->updateInstanceById($instanceId, $newValues);
            $this->addChange($calendarId, "", 2);

            $projection = ['uri' => 1, 'principaluri' => 1];
            $row = $this->calendarInstanceDAO->findInstanceById($instanceId, $projection);

            $this->eventEmitter->emit('esn:calendarUpdated', [$this->getCalendarPath($row['principaluri'], $row['uri'])]);

            return true;
        });
    }

    /**
     * Delete a calendar
     *
     * @param array $calendarIdArray [calendarId, instanceId]
     * @param callable $deleteSubscribersCallback Callback to delete subscribers
     * @param callable $updateInvitesCallback Callback to update invites
     * @param callable $getInvitesCallback Callback to get invites
     */
    public function deleteCalendar($calendarIdArray, $deleteSubscribersCallback, $updateInvitesCallback, $getInvitesCallback) {
        list($calendarId, $instanceId) = $calendarIdArray;

        $row = $this->calendarInstanceDAO->findInstanceById($instanceId);

        if ((int) $row['access'] === \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER) {
            // Remove all sharees
            $currentInvites = $getInvitesCallback($calendarIdArray);

            foreach($currentInvites as $sharee) {
                $sharee->access = \Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS;
            }

            $updateInvitesCallback($calendarIdArray, $currentInvites);
            $deleteSubscribersCallback($row['principaluri'], $row['uri']);

            // Delete all calendar data
            $this->calendarObjectDAO->deleteAllObjectsByCalendarId($calendarId);
            $this->calendarChangeDAO->deleteChangesByCalendarId($calendarId);
            $this->calendarInstanceDAO->deleteInstancesByCalendarId($calendarId);
            $this->calendarDAO->deleteById($calendarId);
        } else {
            // Just delete the instance (shared calendar)
            $this->calendarInstanceDAO->deleteInstanceById($instanceId);
        }

        $this->eventEmitter->emit('esn:calendarDeleted', [$this->getCalendarPath($row['principaluri'], $row['uri'])]);
    }

    /**
     * Get calendar object by UID
     *
     * @param string $principalUri
     * @param string $uid
     * @return string|null Calendar object path or null
     */
    public function getCalendarObjectByUID($principalUri, $uid) {
        $calendarUris = $this->getCalendarInstancesByPrincipalUri($principalUri);
        if (empty($calendarUris)) return null;

        $projection = ['uri' => 1, 'calendarid' => 1];
        $objrow = $this->calendarObjectDAO->findByUid(array_keys($calendarUris), $uid, $projection);
        if (!$objrow) return null;

        return $calendarUris[(string) $objrow['calendarid']] . '/' . $objrow['uri'];
    }

    /**
     * Get duplicate calendar objects by URI
     *
     * @param string $principalUri
     * @param string $uri
     * @return array Array of calendar object paths
     */
    public function getDuplicateCalendarObjectsByURI($principalUri, $uri) {
        $calendarUris = $this->getCalendarInstancesByPrincipalUri($principalUri);
        if (empty($calendarUris)) return [];

        // Find the uid of the event having the provided URI
        $projection = ['uid' => 1];
        $objrow = $this->calendarObjectDAO->findByUri(array_keys($calendarUris), $uri, $projection);
        if (!$objrow) return [];

        // Find the events having the found uid
        $projection = ['uri' => 1, 'calendarid' => 1];
        $objrows = $this->calendarObjectDAO->findByUidMultiple(array_keys($calendarUris), $objrow['uid'], $projection);

        $result = [];
        foreach($objrows as $row) {
            $result[] = $calendarUris[(string) $row['calendarid']] . '/' . $row['uri'];
        }

        return $result;
    }

    /**
     * Delete all subscribers for a calendar
     *
     * @param string $principaluri
     * @param string $uri
     * @param callable $getSubscribersCallback
     * @param callable $deleteSubscriptionCallback
     */
    public function deleteSubscribers($principaluri, $uri, $getSubscribersCallback, $deleteSubscriptionCallback) {
        $principalUriExploded = explode('/', $principaluri);
        $source = 'calendars/' . $principalUriExploded[2] . '/' . $uri;

        $subscriptions = $getSubscribersCallback($source);
        foreach($subscriptions as $subscription) {
            $deleteSubscriptionCallback($subscription['_id']);
        }
    }

    /**
     * Check if calendar instance exists
     *
     * @param string $principalUri
     * @param string $calendarUri
     * @return array|false [calendarId, instanceId] or false
     */
    private function checkIfCalendarInstanceExist($principalUri, $calendarUri) {
        $calendar = $this->calendarInstanceDAO->findInstanceByPrincipalUriAndUri($principalUri, $calendarUri, 1);

        return isset($calendar['_id']) ? [(string) $calendar['calendarid'], (string) $calendar['_id']] : false;
    }

    /**
     * Fetch calendar instances with their associated calendar data
     *
     * @param string $principalUri
     * @return array ['instances' => array, 'calendars' => array]
     */
    private function fetchCalendarInstancesWithData($principalUri) {
        $fields = array_merge(
            array_values($this->propertyMap),
            ['calendarid', 'uri', 'synctoken', 'components', 'principaluri', 'transparent', 'access', 'share_invitestatus']
        );

        $res = $this->calendarInstanceDAO->findByPrincipalUri($principalUri, $fields, ['calendarorder' => 1]);

        $calendarInstances = [];
        $calendarIds = [];

        foreach ($res as $row) {
            $calendarId = (string) $row['calendarid'];
            $calendarIds[] = new \MongoDB\BSON\ObjectId($calendarId);

            // Avoid duplication: a calendarInstance is linked with only one calendarId
            $calendarInstances[$calendarId] = $row;
        }

        $projection = ['_id' => 1, 'synctoken' => 1, 'components' => 1];
        $calendarsResult = $this->calendarDAO->findByIds($calendarIds, $projection);

        $calendars = [];
        foreach ($calendarsResult as $row) {
            $calendars[(string) $row['_id']] = $row;
        }

        return ['instances' => $calendarInstances, 'calendars' => $calendars];
    }

    /**
     * Format calendar instances with their data for user consumption
     *
     * @param array $data ['instances' => array, 'calendars' => array]
     * @return array Array of formatted calendars
     */
    private function formatCalendarsForUser($data) {
        $userCalendars = [];

        foreach ($data['instances'] as $calendarInstance) {
            $currentCalendarId = (string) $calendarInstance['calendarid'];

            if (!isset($data['calendars'][$currentCalendarId])) {
                $this->logMissingCalendar($currentCalendarId, $calendarInstance);
                continue;
            }

            $calendar = $data['calendars'][$currentCalendarId];
            $userCalendars[] = $this->formatSingleCalendar($calendarInstance, $calendar);
        }

        return $userCalendars;
    }

    /**
     * Format a single calendar instance with its calendar data
     *
     * @param array $calendarInstance Instance data from calendarinstances collection
     * @param array $calendar Calendar data from calendars collection
     * @return array Formatted calendar array
     */
    private function formatSingleCalendar($calendarInstance, $calendar) {
        $components = (array) $calendar['components'];

        $userCalendar = [
            'id' => [(string) $calendarInstance['calendarid'], (string) $calendarInstance['_id']],
            'uri' => $calendarInstance['uri'],
            'principaluri' => $calendarInstance['principaluri'],
            '{' . \Sabre\CalDAV\Plugin::NS_CALENDARSERVER . '}getctag' =>
                'http://sabre.io/ns/sync/' . ($calendar['synctoken'] ?: '0'),
            '{http://sabredav.org/ns}sync-token' =>
                $calendar['synctoken'] ?: '0',
            '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}supported-calendar-component-set' =>
                new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet($components),
            '{' . \Sabre\CalDAV\Plugin::NS_CALDAV . '}schedule-calendar-transp' =>
                new \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp($calendarInstance['transparent'] ? 'transparent' : 'opaque'),
            'share-resource-uri' => '/ns/share/' . $calendarInstance['_id'],
            'share-invitestatus' => $calendarInstance['share_invitestatus']
        ];

        // Add share access info for shared calendars (access > 1 means not owner)
        if ($calendarInstance['access'] > 1) {
            $userCalendar['share-access'] = (int) $calendarInstance['access'];
            $userCalendar['read-only'] = (int) $calendarInstance['access'] === \Sabre\DAV\Sharing\Plugin::ACCESS_READ;
        }

        // Set default displayname if empty
        if (!$calendarInstance['displayname']) {
            $calendarInstance['displayname'] = '#default';
        }

        // Map properties from propertyMap
        foreach ($this->propertyMap as $xmlName => $dbName) {
            $userCalendar[$xmlName] = $calendarInstance[$dbName];
        }

        return $userCalendar;
    }

    /**
     * Sort calendars with default calendar first
     *
     * @param array $userCalendars
     * @param string $principalUri
     * @return array Sorted calendars
     */
    private function sortCalendarsWithDefaultFirst($userCalendars, $principalUri) {
        $principalId = $this->extractPrincipalId($principalUri);

        $defaultCalendar = null;
        $otherCalendars = [];

        foreach ($userCalendars as $calendar) {
            $calendarId = $this->extractCalendarId($calendar['uri']);

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

    /**
     * Get calendar instances by principal URI (for UID lookups)
     *
     * @param string $principalUri
     * @return array Associative array [calendarId => uri]
     */
    private function getCalendarInstancesByPrincipalUri($principalUri) {
        $projection = ['calendarid' => 1, 'uri' => 1, 'access' => 1];
        $calendarInstances = $this->calendarInstanceDAO->findInstancesByPrincipalUriWithAccess($principalUri, $projection);
        if (!$calendarInstances) return [];

        $calendarUris = [];
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

    /**
     * Add a change to calendar changes log
     *
     * @param string $calendarId
     * @param string $objectUri
     * @param int $operation 1=add, 2=modify, 3=delete
     */
    private function addChange($calendarId, $objectUri, $operation) {
        $res = $this->calendarDAO->getSyncToken($calendarId);

        if (!$res) {
            return;
        }

        $this->calendarChangeDAO->addChange($calendarId, $objectUri, $res['synctoken'], $operation);
        $this->calendarDAO->incrementSyncToken($calendarId);
    }

    /**
     * Check if principal is a resource
     *
     * @param string $principalUri
     * @return bool
     */
    private function isPrincipalResource($principalUri) {
        if (!$principalUri) {
            return false;
        }

        $uriExploded = explode('/', $principalUri);
        return $uriExploded[1] === 'resources';
    }

    /**
     * Get calendar path from principal URI and calendar URI
     *
     * @param string $principalUri
     * @param string $calendarUri
     * @return string Calendar path
     */
    private function getCalendarPath($principalUri, $calendarUri) {
        $uriExploded = explode('/', $principalUri);
        return '/calendars/' . $uriExploded[2] . '/' . $calendarUri;
    }

    /**
     * Extract principal ID from principal URI
     *
     * @param string $principalUri e.g., "principals/users/123"
     * @return string Principal ID e.g., "123"
     */
    private function extractPrincipalId($principalUri) {
        $parts = explode('/', $principalUri);
        return end($parts);
    }

    /**
     * Extract calendar ID from calendar URI
     *
     * @param string $calendarUri e.g., "calendars/123/456"
     * @return string Calendar ID e.g., "456"
     */
    private function extractCalendarId($calendarUri) {
        $parts = explode('/', $calendarUri);
        return end($parts);
    }

    /**
     * Log error when calendar is not found for instance
     *
     * @param string $calendarId
     * @param array $calendarInstance
     */
    private function logMissingCalendar($calendarId, $calendarInstance) {
        if ($this->server) {
            $this->server->getLogger()->error(
                'Calendar {calendar} not found for calendar instance {instance}',
                [
                    'calendar' => $calendarId,
                    'instance' => (string) $calendarInstance['_id']
                ]
            );
        }
    }
}
