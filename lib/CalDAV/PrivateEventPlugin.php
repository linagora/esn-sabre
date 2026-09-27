<?php

namespace ESN\CalDAV;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\PropFind;
use Sabre\DAV\INode;
use Sabre\VObject;
use ESN\Utils\Utils;

/**
 * Private Event Plugin
 *
 * Sanitizes PRIVATE/CONFIDENTIAL events for users not allowed to see their details:
 * delegates of a user calendar, public readers and subscribers.
 * Uses the denormalized 'classification' field to avoid unnecessary parsing.
 * Only parses ICS data when classification is PRIVATE or CONFIDENTIAL
 * AND the calendar hides private events from the current user (see Utils::hidesPrivateEventsFrom).
 *
 * Legacy data without classification field is treated as non-private (no performance impact).
 */
class PrivateEventPlugin extends ServerPlugin {

    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    protected $server;

    function initialize(Server $server) {
        $this->server = $server;
        // Priority 500 = runs after CalDAV plugin has set calendar-data
        $server->on('propFind', [$this, 'propFind'], 500);
        // Priority 90 = runs before CorePlugin (100) to intercept GET on private events
        $server->on('method:GET', [$this, 'httpGet'], 90);
    }

    function getPluginName() {
        return 'private-event';
    }

    /**
     * Intercepts HTTP GET requests on private/confidential calendar objects.
     * Sanitizes the response body for users the calendar hides private events from.
     *
     * @param \Sabre\HTTP\RequestInterface $request
     * @param \Sabre\HTTP\ResponseInterface $response
     * @return bool|null Returns false to stop processing, null to continue
     */
    function httpGet(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) {
        $path = $request->getPath();

        try {
            $node = $this->server->tree->getNodeForPath($path);
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            return;
        }

        $currentUser = $this->getCurrentUser();
        $calendar = $this->findCalendarHidingPrivateEvents($node, $path, $currentUser);
        if (!$calendar) {
            return;
        }

        // Get the raw calendar data
        $calendarData = $node->get();
        if (is_resource($calendarData)) {
            $calendarData = stream_get_contents($calendarData);
        }

        // Sanitize the data
        $sanitizedData = $this->sanitizeCalendarData($calendarData, $calendar, $currentUser);

        // Set response headers (similar to CorePlugin::httpGet)
        $response->setHeader('Content-Type', 'text/calendar; charset=utf-8');
        $response->setHeader('Content-Length', strlen($sanitizedData));

        $etag = $node->getETag();
        if ($etag) {
            $response->setHeader('ETag', $etag);
        }

        $response->setStatus(200);
        $response->setBody($sanitizedData);

        // Return false to stop further processing (prevent CorePlugin from sending unsanitized data)
        return false;
    }

    function propFind(PropFind $propFind, INode $node) {
        $currentUser = $this->getCurrentUser();
        $calendar = $this->findCalendarHidingPrivateEvents($node, $propFind->getPath(), $currentUser);
        if (!$calendar) {
            return;
        }

        $calendarDataProp = '{' . self::NS_CALDAV . '}calendar-data';
        $calendarData = $propFind->get($calendarDataProp);
        if ($calendarData === null) {
            return;
        }

        $sanitizedData = $this->sanitizeCalendarData($calendarData, $calendar, $currentUser);
        if ($sanitizedData !== $calendarData) {
            $propFind->set($calendarDataProp, $sanitizedData);
        }
    }

    /**
     * Returns the calendar containing this private/confidential object when it hides
     * private events from the current user, null when the object can be served as is.
     *
     * @param INode $node
     * @param string $objectPath
     * @param string|null $currentUser
     * @return INode|null
     */
    protected function findCalendarHidingPrivateEvents(INode $node, $objectPath, $currentUser) {
        if (!$currentUser || !$this->needsSanitization($node)) {
            return null;
        }

        $calendar = $this->getCalendar($objectPath);
        if (!$calendar || !method_exists($calendar, 'getOwner') || !Utils::hidesPrivateEventsFrom($calendar, $currentUser)) {
            return null;
        }

        return $calendar;
    }

    protected function needsSanitization(INode $node) {
        if (!($node instanceof \Sabre\CalDAV\CalendarObject)) {
            return false;
        }

        $reflection = new \ReflectionClass($node);
        $prop = $reflection->getProperty('objectData');
        $prop->setAccessible(true);
        $objectData = $prop->getValue($node);

        if (!isset($objectData['classification'])) {
            // Legacy data without classification: treat as non-private
            return false;
        }

        return Utils::isPrivateClassification($objectData['classification']);
    }

    protected function getCurrentUser() {
        $authPlugin = $this->server->getPlugin('auth');
        return $authPlugin ? $authPlugin->getCurrentPrincipal() : null;
    }

    /**
     * Get the calendar containing the object, as reached by the request: the calendar itself,
     * a shared instance of it, or a subscription to it.
     *
     * @param string $objectPath Path to the calendar object (e.g., calendars/bob/alice-calendar/event.ics)
     * @return INode|null
     */
    protected function getCalendar($objectPath) {
        // Get the parent calendar path by removing the last segment (the object URI)
        $pathParts = explode('/', $objectPath);
        array_pop($pathParts);
        $calendarPath = implode('/', $pathParts);

        try {
            return $this->server->tree->getNodeForPath($calendarPath);
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            return null;
        }
    }

    protected function sanitizeCalendarData($calendarData, $calendar, $currentUser) {
        try {
            $vCalendar = VObject\Reader::read($calendarData);
        } catch (\Exception $e) {
            return $calendarData;
        }

        if (!isset($vCalendar->VEVENT)) {
            $vCalendar->destroy();
            return $calendarData;
        }

        $sanitizedCalendar = Utils::hidePrivateEventInfoForUser($vCalendar, $calendar, $currentUser);
        $result = $sanitizedCalendar->serialize();

        $vCalendar->destroy();
        $sanitizedCalendar->destroy();

        return $result;
    }
}
