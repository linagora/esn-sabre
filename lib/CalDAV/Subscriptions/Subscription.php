<?php

namespace ESN\CalDAV\Subscriptions;

use Sabre\CalDAV\Backend\SubscriptionSupport;
use Sabre\CalDAV\ICalendarObjectContainer;
use Sabre\DAV\Sync\ISyncCollection;

/**
 * Subscription Node
 *
 * This node extends Sabre's Subscription to also expose calendar objects from the source calendar.
 * This allows REPORT queries on subscriptions to return events from the source calendar,
 * and ISyncCollection exposes the changes of the source calendar to sync-collection.
 */
#[\AllowDynamicProperties]
class Subscription extends \Sabre\CalDAV\Subscriptions\Subscription implements ICalendarObjectContainer, \Sabre\CalDAV\ICalendar, ISyncCollection {

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

        $calendars = $this->caldavBackend->getCalendarsForUser($this->resolveSourcePrincipal($principalId));
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
     * Returns the principal owning the source calendar home: a resource, a team calendar, or else a user.
     *
     * @param string $principalId
     * @return string
     */
    private function resolveSourcePrincipal($principalId) {
        $principalBackend = method_exists($this->caldavBackend, 'getPrincipalBackend')
            ? $this->caldavBackend->getPrincipalBackend()
            : null;
        if ($principalBackend) {
            foreach (['principals/resources/', 'principals/team-calendars/'] as $principalPrefix) {
                if ($principalBackend->getPrincipalByPath($principalPrefix . $principalId)) {
                    return $principalPrefix . $principalId;
                }
            }
        }

        return 'principals/users/' . $principalId;
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
     * Returns the sync token of the source calendar.
     *
     * @return string|null
     */
    function getSyncToken() {
        if (!$this->caldavBackend instanceof \Sabre\CalDAV\Backend\SyncSupport) {
            return null;
        }

        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            return null;
        }

        return $sourceCalendarInfo['{DAV:}sync-token']
            ?? $sourceCalendarInfo['{http://sabredav.org/ns}sync-token']
            ?? null;
    }

    /**
     * Returns the changes for this subscription.
     *
     * Changes are the ones of the source calendar, since the children of this
     * node are the calendar objects of the source calendar.
     *
     * @param string $syncToken
     * @param int $syncLevel
     * @param int $limit
     * @return array|null
     */
    function getChanges($syncToken, $syncLevel, $limit = null) {
        if (!$this->caldavBackend instanceof \Sabre\CalDAV\Backend\SyncSupport) {
            return null;
        }

        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            return null;
        }

        return $this->caldavBackend->getChangesForCalendar(
            $sourceCalendarInfo['id'],
            $syncToken,
            $syncLevel,
            $limit
        );
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
     * Write access is determined by checking if the source calendar grants
     * write privileges via public right.
     *
     * @throws \Sabre\DAV\Exception\Forbidden
     */
    protected function checkWriteAccess() {
        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            throw new \Sabre\DAV\Exception\Forbidden('Source calendar not found');
        }

        // Check if the source calendar has public write right
        $publicRight = $this->caldavBackend->getCalendarPublicRight($sourceCalendarInfo['id']);
        if ($publicRight === '{DAV:}write') {
            return;
        }

        throw new \Sabre\DAV\Exception\Forbidden('You do not have write access to this subscription');
    }

    /**
     * Returns the public right of the source calendar, or null if unavailable.
     */
    public function getSourcePublicRight(): ?string {
        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            return null;
        }
        return $this->caldavBackend->getCalendarPublicRight($sourceCalendarInfo['id']);
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
        // The source calendar is usually already loaded to reach its objects
        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if ($sourceCalendarInfo && isset($sourceCalendarInfo['principaluri'])) {
            return $sourceCalendarInfo['principaluri'];
        }

        $source = $this->subscriptionInfo['source'] ?? null;
        if ($source && preg_match('#calendars/([^/]+)#', $source, $matches)) {
            return 'principals/users/' . $matches[1];
        }

        return $this->getOwner();
    }
}
