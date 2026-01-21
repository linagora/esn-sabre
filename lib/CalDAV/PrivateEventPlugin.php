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
 * Sanitizes PRIVATE/CONFIDENTIAL events for delegated users.
 * Uses the denormalized 'classification' field to avoid unnecessary parsing.
 * Only parses ICS data when classification is PRIVATE or CONFIDENTIAL
 * AND the current user is not the calendar owner.
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
     * Sanitizes the response body for delegated users who are not the calendar owner.
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

        if (!($node instanceof \Sabre\CalDAV\ICalendarObject)) {
            return;
        }

        if (!$this->needsSanitization($node)) {
            return;
        }

        $currentUser = $this->getCurrentUser();
        if (!$currentUser) {
            return;
        }

        $calendarOwner = $node->getOwner();
        if (!$calendarOwner || $calendarOwner === $currentUser) {
            return;
        }

        // Get the raw calendar data
        $calendarData = $node->get();
        if (is_resource($calendarData)) {
            $calendarData = stream_get_contents($calendarData);
        }

        // Sanitize the data
        $sanitizedData = $this->sanitizeCalendarData($calendarData, $calendarOwner, $currentUser);

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
        if (!($node instanceof \Sabre\CalDAV\ICalendarObject)) {
            return;
        }

        if (!$this->needsSanitization($node)) {
            return;
        }

        $currentUser = $this->getCurrentUser();
        if (!$currentUser) {
            return;
        }

        $calendarOwner = $node->getOwner();
        if (!$calendarOwner || $calendarOwner === $currentUser) {
            return;
        }

        $calendarDataProp = '{' . self::NS_CALDAV . '}calendar-data';
        $calendarData = $propFind->get($calendarDataProp);
        if ($calendarData === null) {
            return;
        }

        $sanitizedData = $this->sanitizeCalendarData($calendarData, $calendarOwner, $currentUser);
        if ($sanitizedData !== $calendarData) {
            $propFind->set($calendarDataProp, $sanitizedData);
        }
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

        $class = strtoupper($objectData['classification']);
        return $class === 'PRIVATE' || $class === 'CONFIDENTIAL';
    }

    protected function getCurrentUser() {
        $authPlugin = $this->server->getPlugin('auth');
        return $authPlugin ? $authPlugin->getCurrentPrincipal() : null;
    }

    protected function sanitizeCalendarData($calendarData, $calendarOwner, $currentUser) {
        try {
            $vCalendar = VObject\Reader::read($calendarData);
        } catch (\Exception $e) {
            return $calendarData;
        }

        if (!isset($vCalendar->VEVENT)) {
            $vCalendar->destroy();
            return $calendarData;
        }

        $mockNode = new class($calendarOwner) {
            private $owner;
            public function __construct($owner) { $this->owner = $owner; }
            public function getOwner() { return $this->owner; }
        };

        $sanitizedCalendar = Utils::hidePrivateEventInfoForUser($vCalendar, $mockNode, $currentUser);
        $result = $sanitizedCalendar->serialize();

        $vCalendar->destroy();
        $sanitizedCalendar->destroy();

        return $result;
    }
}
