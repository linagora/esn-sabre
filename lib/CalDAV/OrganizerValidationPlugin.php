<?php

namespace ESN\CalDAV;

use ESN\DAV\Sharing\Plugin as SharingPlugin;
use ESN\Utils\Utils;
use Sabre\CalDAV\ICalendarObject;
use Sabre\CalDAV\Schedule\ISchedulingObject;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Rejects calendar objects whose ORGANIZER may not organize in a team calendar.
 *
 * A team calendar is shared between its members, so an object stored there is not tied to a
 * single owner the way a personal calendar object is. Members do organise on one another's
 * behalf, but an ORGANIZER from outside the calendar would let a member put an event in the
 * name of somebody who never agreed to it.
 *
 * Personal calendars are deliberately left alone: their scheduling identity is the calendar
 * owner, read from the principal backend rather than from the submitted object, so an
 * ORGANIZER the user is not entitled to reaches nobody anyway.
 */
class OrganizerValidationPlugin extends ServerPlugin {

    protected $server;

    function initialize(Server $server) {
        $this->server = $server;
        $server->on('calendarObjectChange', [$this, 'calendarObjectChange'], Plugin::PRIORITY_BEFORE_SCHEDULING - 10);
        $server->on('beforeMove', [$this, 'beforeMove'], 45);
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

        if ($request->getMethod() === 'PUT' && array_key_exists('import', $request->getQueryParameters())) {
            return;
        }

        if (!$this->isTeamCalendarPath($calendarPath)) {
            return;
        }

        $this->validateCalendarOrganizer($vCal, $calendarPath);
    }

    function beforeMove($sourcePath, $destinationPath) {
        list($calendarPath,) = Utils::splitEventPath('/' . ltrim($destinationPath, '/'));
        if (!$calendarPath || !$this->isTeamCalendarPath($calendarPath)) return;

        try {
            $source = $this->server->tree->getNodeForPath($sourcePath);
        } catch (\Sabre\DAV\Exception) {
            return;
        }
        if (!$source instanceof ICalendarObject || $source instanceof ISchedulingObject) return;

        $calendarData = $source->get();
        if (is_resource($calendarData)) $calendarData = stream_get_contents($calendarData);
        $calendar = Reader::read($calendarData);
        if (!$calendar instanceof VCalendar) {
            $calendar->destroy();
            return;
        }

        try {
            $this->validateCalendarOrganizer($calendar, $calendarPath);
        } finally {
            $calendar->destroy();
        }
    }

    private function validateCalendarOrganizer(VCalendar $calendar, $calendarPath): void {
        $vevents = $calendar->select('VEVENT');
        if (empty($vevents) || !($organizerUri = $this->extractOrganizerUri($vevents))) return;

        $this->validateOrganizerAuthorized($organizerUri, $calendarPath);
    }

    private function extractOrganizerUri(array $vevents): ?string {
        $organizerValues = $this->collectOrganizerValues($vevents);

        if (empty($organizerValues)) {
            return null;
        }

        if (count(array_unique($organizerValues)) > 1) {
            throw new Forbidden('All VEVENT components must share the same ORGANIZER property.');
        }

        return reset($organizerValues);
    }

    private function collectOrganizerValues(array $vevents): array {
        $organizerValues = [];
        foreach ($vevents as $vevent) {
            if (isset($vevent->ATTENDEE) && !isset($vevent->ORGANIZER)) {
                throw new Forbidden('A VEVENT with ATTENDEE properties must also have an ORGANIZER.');
            }
            if (isset($vevent->ORGANIZER)) {
                $organizerValues[] = strtolower((string) $vevent->ORGANIZER);
            }
        }
        return $organizerValues;
    }

    private function validateOrganizerAuthorized(string $organizerUri, $calendarPath): void {
        $aclPlugin = $this->server->getPlugin('acl');
        if (!$aclPlugin) {
            return;
        }

        $organizerPrincipal = $aclPlugin->getPrincipalByUri($organizerUri);

        if ($organizerPrincipal === null
            || !$this->isWriteEnabledCalendarSharee($organizerPrincipal, $calendarPath)) {
            throw new Forbidden('The ORGANIZER must be a write-enabled team calendar member.');
        }
    }

    private function isTeamCalendarPath($calendarPath): bool {
        $calendarNode = $this->getCalendarNode($calendarPath);
        $owner = ($calendarNode && method_exists($calendarNode, 'getOwner')) ? $calendarNode->getOwner() : null;

        return Utils::isTeamCalendarFromPrincipal($owner);
    }

    private function isWriteEnabledCalendarSharee(string $organizerPrincipal, $calendarPath): bool {
        $calendarNode = $this->getCalendarNode($calendarPath);
        if (!$calendarNode || !method_exists($calendarNode, 'getInvites')) {
            return false;
        }

        foreach ($calendarNode->getInvites() as $sharee) {
            if ($this->normalizePrincipal($sharee->principal ?? null) === $this->normalizePrincipal($organizerPrincipal)
                && $this->isWriteEnabledAccess((int) ($sharee->access ?? 0))) {
                return true;
            }
        }

        return false;
    }

    private function isWriteEnabledAccess(int $access): bool {
        return in_array($access, [SharingPlugin::ACCESS_READWRITE, SharingPlugin::ACCESS_ADMINISTRATION], true);
    }

    private function getCalendarNode($calendarPath) {
        try {
            return $this->server->tree->getNodeForPath($calendarPath);
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            return null;
        }
    }

    private function normalizePrincipal(?string $principal): ?string {
        return $principal === null ? null : trim($principal, '/');
    }
}
