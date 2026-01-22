<?php

namespace ESN\CalDAV\Subscriptions;

use Sabre\CalDAV\Backend\SubscriptionSupport;
use Sabre\CalDAV\ICalendarObjectContainer;

/**
 * Subscription Node
 *
 * This node extends Sabre's Subscription to also expose calendar objects from the source calendar.
 * This allows REPORT queries on subscriptions to return events from the source calendar.
 */
#[\AllowDynamicProperties]
class Subscription extends \Sabre\CalDAV\Subscriptions\Subscription implements ICalendarObjectContainer {

    /**
     * Cached source calendar info
     *
     * @var array|null|false
     */
    protected $sourceCalendarInfo = null;

    /**
     * Returns an array with all the child nodes (calendar objects from the source calendar)
     *
     * @return \Sabre\DAV\INode[]
     */
    function getChildren() {
        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            return [];
        }

        $objs = $this->caldavBackend->getCalendarObjects($sourceCalendarInfo['id']);
        $children = [];

        foreach ($objs as $obj) {
            $children[] = new SubscriptionObject($this->caldavBackend, $sourceCalendarInfo, $obj);
        }

        return $children;
    }

    /**
     * Returns a single child node by name
     *
     * @param string $name
     * @return \Sabre\DAV\INode
     */
    function getChild($name) {
        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            throw new \Sabre\DAV\Exception\NotFound('Calendar object not found');
        }

        $obj = $this->caldavBackend->getCalendarObject($sourceCalendarInfo['id'], $name);
        if (!$obj) {
            throw new \Sabre\DAV\Exception\NotFound('Calendar object not found: ' . $name);
        }

        return new SubscriptionObject($this->caldavBackend, $sourceCalendarInfo, $obj);
    }

    /**
     * Checks if a child-node with the specified name exists
     *
     * @param string $name
     * @return bool
     */
    function childExists($name) {
        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            return false;
        }

        $obj = $this->caldavBackend->getCalendarObject($sourceCalendarInfo['id'], $name);
        return (bool)$obj;
    }

    /**
     * Returns calendar info for the source calendar that this subscription points to.
     *
     * @return array|null
     */
    protected function getSourceCalendarInfo() {
        if ($this->sourceCalendarInfo !== null) {
            return $this->sourceCalendarInfo ?: null;
        }

        $source = $this->subscriptionInfo['source'] ?? null;
        if (!$source) {
            $this->sourceCalendarInfo = false;
            return null;
        }

        // Parse the source URL to extract principalUri and calendar URI
        // Format: calendars/{principalId}/{calendarUri}
        $sourcePath = ltrim($source, '/');
        $parts = explode('/', $sourcePath);

        if (count($parts) < 3 || $parts[0] !== 'calendars') {
            $this->sourceCalendarInfo = false;
            return null;
        }

        $principalId = $parts[1];
        $calendarUri = $parts[2];
        $principalUri = 'principals/users/' . $principalId;

        // Get the source calendar
        $calendars = $this->caldavBackend->getCalendarsForUser($principalUri);
        foreach ($calendars as $calendar) {
            if ($calendar['uri'] === $calendarUri) {
                $this->sourceCalendarInfo = $calendar;
                return $this->sourceCalendarInfo;
            }
        }

        $this->sourceCalendarInfo = false;
        return null;
    }

    /**
     * Performs a calendar-query on the contents of the source calendar.
     *
     * @param array $filters
     * @return array
     */
    function calendarQuery(array $filters) {
        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            return [];
        }

        return $this->caldavBackend->calendarQuery($sourceCalendarInfo['id'], $filters);
    }
}
