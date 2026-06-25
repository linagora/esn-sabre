<?php

namespace ESN\CalDAV;

use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component\VCalendar;

class OrganizerValidationPlugin extends ServerPlugin {

    protected $server;

    function initialize(Server $server) {
        $this->server = $server;
        $server->on('calendarObjectChange', [$this, 'calendarObjectChange'], Plugin::PRIORITY_BEFORE_SCHEDULING - 10);
    }

    function getPluginName() {
        return 'caldav-organizer-validation';
    }

    function calendarObjectChange(
        RequestInterface $request,
        ResponseInterface $response,
        VCalendar $vCal,
        $calendarPath,
        &$modified,
        $isNew
    ) {
        if ($request->getMethod() === 'ITIP') {
            return;
        }

        $vevents = $vCal->select('VEVENT');
        if (empty($vevents)) {
            return;
        }

        $organizerValues = [];
        foreach ($vevents as $vevent) {
            $hasOrganizer = isset($vevent->ORGANIZER);
            $hasAttendee = isset($vevent->ATTENDEE);

            if ($hasAttendee && !$hasOrganizer) {
                throw new Forbidden('A VEVENT with ATTENDEE properties must also have an ORGANIZER.');
            }

            if ($hasOrganizer) {
                $organizerValues[] = strtolower((string) $vevent->ORGANIZER);
            }
        }

        if (empty($organizerValues)) {
            return;
        }

        if (count(array_unique($organizerValues)) > 1) {
            throw new Forbidden('All VEVENT components must share the same ORGANIZER property.');
        }

        $organizerUri = reset($organizerValues);

        $aclPlugin = $this->server->getPlugin('acl');
        if (!$aclPlugin) {
            return;
        }

        $organizerPrincipal = $aclPlugin->getPrincipalByUri($organizerUri);
        $ownerPrincipal = $this->getCalendarOwner($calendarPath);
        $authPlugin = $this->server->getPlugin('auth');
        $currentPrincipal = $authPlugin ? $authPlugin->getCurrentPrincipal() : null;

        if ($organizerPrincipal !== null
            && ($organizerPrincipal === $ownerPrincipal || $organizerPrincipal === $currentPrincipal)
        ) {
            return;
        }

        throw new Forbidden('The ORGANIZER must be either the calendar owner or the authenticated user.');
    }

    private function getCalendarOwner($calendarPath) {
        try {
            $calendarNode = $this->server->tree->getNodeForPath($calendarPath);
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            return null;
        }

        if (!method_exists($calendarNode, 'getOwner')) {
            return null;
        }

        return $calendarNode->getOwner();
    }
}
