<?php

namespace ESN\CalDAV;

use ESN\Utils\Utils;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component\VCalendar;

/**
 * Maintains the server-owned Team Calendar routing metadata.
 */
class TeamCalendarMetadataPlugin extends ServerPlugin {
    const PROPERTY = 'X-OPENPAAS-TEAM-CALENDAR-ID';

    private $server;

    function initialize(Server $server) {
        $this->server = $server;
        $server->on('calendarObjectChange', [$this, 'calendarObjectChange'], Plugin::PRIORITY_BEFORE_SCHEDULING + 10);
    }

    function getPluginName() { return 'caldav-team-calendar-metadata'; }

    function calendarObjectChange(RequestInterface $_request, ResponseInterface $_response, VCalendar $calendar,
        $calendarPath, &$modified, $_isNew) {
        $modified = $this->normalize($calendar, $calendarPath) || $modified;
    }

    /** Normalizes metadata from the destination owner, never from client ICS. */
    function normalize(VCalendar $calendar, string $calendarPath): bool {
        $teamCalendarId = $this->teamCalendarId($calendarPath);
        if (!$teamCalendarId) return false;
        $modified = false;
        foreach ($calendar->select('VEVENT') as $event) {
            if (isset($event->{self::PROPERTY}) && $event->{self::PROPERTY}->getValue() === $teamCalendarId) {
                continue;
            }
            unset($event->{self::PROPERTY});
            $event->add(self::PROPERTY, $teamCalendarId);
            $modified = true;
        }
        return $modified;
    }

    private function teamCalendarId(string $calendarPath): ?string {
        $calendarNode = $this->server->tree->getNodeForPath($calendarPath);
        $owner = ($calendarNode !== null && method_exists($calendarNode, 'getOwner')) ? $calendarNode->getOwner() : null;
        return Utils::isTeamCalendarFromPrincipal($owner) ? basename($owner) : null;
    }
}
