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
        $subscriptionOwner = $this->subscriptionInfo['principaluri'];

        foreach ($objs as $obj) {
            $children[] = new SubscriptionObject($this->caldavBackend, $sourceCalendarInfo, $obj, $subscriptionOwner);
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

        $subscriptionOwner = $this->subscriptionInfo['principaluri'];
        return new SubscriptionObject($this->caldavBackend, $sourceCalendarInfo, $obj, $subscriptionOwner);
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

        // Try both user and resource principal namespaces
        foreach (['principals/users/', 'principals/resources/'] as $prefix) {
            $calendars = $this->caldavBackend->getCalendarsForUser($prefix . $principalId);
            foreach ($calendars as $calendar) {
                if ($calendar['uri'] === $calendarUri) {
                    $this->sourceCalendarInfo = $calendar;
                    return $this->sourceCalendarInfo;
                }
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

    /**
     * Creates a new file in the subscription's source calendar.
     *
     * @param string $name Name of the file
     * @param resource|string $calendarData Initial payload
     * @return string|null ETag of the new file
     * @throws \Sabre\DAV\Exception\Forbidden
     */
    function createFile($name, $calendarData = null) {
        // Check write access before creating
        $this->checkWriteAccess();

        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            throw new \Sabre\DAV\Exception\Forbidden('Cannot create event: source calendar not found');
        }

        if (is_resource($calendarData)) {
            $calendarData = stream_get_contents($calendarData);
        }

        return $this->caldavBackend->createCalendarObject($sourceCalendarInfo['id'], $name, $calendarData);
    }

    /**
     * Checks if the subscription allows write access.
     *
     * Write access is granted if the source calendar has a public write right,
     * or if the subscriber has an individual write or admin access level.
     *
     * @throws \Sabre\DAV\Exception\Forbidden
     */
    protected function checkWriteAccess() {
        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            throw new \Sabre\DAV\Exception\Forbidden('Source calendar not found');
        }

        $publicRight = $this->caldavBackend->getCalendarPublicRight($sourceCalendarInfo['id']);
        if ($publicRight === '{DAV:}write') {
            return;
        }

        $subscriberPrincipal = $this->subscriptionInfo['principaluri'];
        $access = $this->caldavBackend->getUserCalendarAccess($sourceCalendarInfo['id'], $subscriberPrincipal);
        if ($access === \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE || $access === \ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION) {
            return;
        }

        throw new \Sabre\DAV\Exception\Forbidden('You do not have write access to this subscription');
    }

    /**
     * Returns the owner of the source calendar.
     *
     * For subscriptions, this returns the owner of the source calendar (not the subscriber).
     * This is important for permission checks like hiding private events.
     *
     * @return string|null The principal URI of the source calendar owner
     */
    function getSourceOwner() {
        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if ($sourceCalendarInfo && isset($sourceCalendarInfo['principaluri'])) {
            return $sourceCalendarInfo['principaluri'];
        }

        return $this->getOwner();
    }
}
