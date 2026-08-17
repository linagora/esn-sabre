<?php

namespace ESN\CalDAV;

use ESN\Utils\Utils;
use Sabre\CalDAV\ICalendarObject;
use Sabre\CalDAV\Schedule\ISchedulingObject;
use Sabre\DAV\Exception;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * Maintains the server-owned Team Calendar routing metadata.
 */
class TeamCalendarMetadataPlugin extends ServerPlugin {
    const PROPERTY = 'X-OPENPAAS-TEAM-CALENDAR-ID';

    private $server, $movedObjectOldMessages = [];

    function initialize(Server $server) {
        $this->server = $server;
        $server->on('calendarObjectChange', [$this, 'calendarObjectChange'], Plugin::PRIORITY_BEFORE_SCHEDULING + 10);
        $server->on('beforeMove', [$this, 'beforeMove'], 55);
        $server->on('afterMove', [$this, 'afterMove'], 50);
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

    function beforeMove($sourcePath, $destinationPath) {
        list($destinationCalendarPath,) = Utils::splitEventPath('/' . ltrim($destinationPath, '/'));
        if (!$destinationCalendarPath || !$this->teamCalendarId($destinationCalendarPath)
            || !($source = $this->calendarObjectAt($sourcePath))) {
            return;
        }

        list($sourceCalendarPath,) = Utils::splitEventPath('/' . ltrim($sourcePath, '/'));
        $sourceTeamCalendarId = $sourceCalendarPath ? $this->teamCalendarId($sourceCalendarPath) : null;
        $this->movedObjectOldMessages[$destinationPath] = [
            'calendarData' => $this->trustedSourceData($this->calendarData($source), $sourceTeamCalendarId),
            'teamCalendarId' => $sourceTeamCalendarId,
        ];
    }

    function afterMove($_sourcePath, $destinationPath) {
        $source = $this->movedObjectOldMessages[$destinationPath] ?? null;
        unset($this->movedObjectOldMessages[$destinationPath]);
        list($calendarPath,) = Utils::splitEventPath('/' . ltrim($destinationPath, '/'));
        $normalized = $calendarPath ? $this->normalizeMovedObject($destinationPath, $calendarPath) : null;
        if ($source === null || $normalized === null) return;

        list($calendarData, $modified) = $normalized;
        if (!$modified && $source['teamCalendarId'] === $this->teamCalendarId($calendarPath)) return;

        $this->server->emit('calendarObjectUpdatedByServer',
            [$source['calendarData'], $calendarData, $calendarPath, [self::PROPERTY], true]);
    }

    private function normalizeMovedObject(string $destinationPath, string $calendarPath): ?array {
        if (!($object = $this->calendarObjectAt($destinationPath))) return null;
        $calendarData = $this->calendarData($object);
        $calendar = Reader::read($calendarData);
        if (!$calendar instanceof VCalendar) {
            $calendar->destroy();
            return null;
        }
        try {
            $modified = $this->normalize($calendar, $calendarPath);
            if ($modified) {
                $calendarData = $calendar->serialize();
                $object->put($calendarData);
            }
            return [$calendarData, $modified];
        } finally {
            $calendar->destroy();
        }
    }

    private function trustedSourceData(string $calendarData, ?string $sourceTeamCalendarId): string {
        if ($sourceTeamCalendarId !== null) return $calendarData;

        $calendar = Reader::read($calendarData);
        if (!$calendar instanceof VCalendar) {
            $calendar->destroy();
            return $calendarData;
        }
        try {
            foreach ($calendar->select('VEVENT') as $event) unset($event->{self::PROPERTY});
            return $calendar->serialize();
        } finally {
            $calendar->destroy();
        }
    }

    private function teamCalendarId(string $calendarPath): ?string {
        $calendarNode = $this->server->tree->getNodeForPath($calendarPath);
        $owner = ($calendarNode !== null && method_exists($calendarNode, 'getOwner')) ? $calendarNode->getOwner() : null;
        return Utils::isTeamCalendarFromPrincipal($owner) ? basename($owner) : null;
    }

    private function calendarObjectAt(string $path): ?ICalendarObject {
        try {
            $object = $this->server->tree->getNodeForPath($path);
        } catch (Exception) {
            return null;
        }
        return $object instanceof ICalendarObject && !$object instanceof ISchedulingObject ? $object : null;
    }

    private function calendarData(ICalendarObject $object): string {
        return is_resource($data = $object->get()) ? (string) stream_get_contents($data) : (string) $data;
    }
}
