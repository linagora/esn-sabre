<?php

namespace ESN\CalDAV;

use ESN\CalDAV\Schedule\CalendarObjectHelper;
use ESN\Utils\Utils;
use Sabre\CalDAV\ICalendarObject;
use Sabre\CalDAV\Schedule\ISchedulingObject;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

class TeamCalendarSchedulingRecipientPlugin extends ServerPlugin {
    const PLUGIN_NAME = 'caldav-team-calendar-scheduling-recipient';

    private $server;
    private $calendarBackend;
    private $moveContexts = [];

    public function __construct(Backend\Mongo $calendarBackend) {
        $this->calendarBackend = $calendarBackend;
    }

    public function initialize(Server $server) {
        $this->server = $server;
        $server->on('beforeMove', [$this, 'captureSchedulingRecipientBeforeMove'], 45);
        $server->on('afterMove', [$this, 'saveSchedulingRecipientAfterMove'], 40);
        $server->on('afterResponse', [$this, 'clearMovedSchedulingRecipients']);
        $server->on('exception', [$this, 'clearMovedSchedulingRecipients']);
    }

    public function getPluginName() {
        return self::PLUGIN_NAME;
    }

    public function findSchedulingRecipientObjectPath(string $homePath, string $uid, string $principalUri): ?string {
        $paths = $this->findTeamCalendarObjectPathsByUid($homePath, $uid, $principalUri);
        if (!$paths) return null;
        if (count($paths) !== 1) {
            throw new \Sabre\DAV\Exception('Multiple calendar objects match scheduling recipient');
        }
        return $paths[0];
    }

    public function findCalendarObjectPathsWithoutSchedulingRecipient(string $homePath, string $uid): array {
        return $this->findTeamCalendarObjectPathsByUid($homePath, $uid, null);
    }

    private function findTeamCalendarObjectPathsByUid(string $homePath, string $uid, ?string $recipient): array {
        $calendars = $this->calendarInstancesById($homePath);
        if (!$calendars) return [];

        // Reuse the /events UID query before loading owners/invites of unrelated calendars.
        $paths = [];
        foreach ($this->calendarBackend->findCalendarObjectsByUid($uid, array_keys($calendars),
            ['metadata.schedulingRecipientPrincipalUri' => $recipient]) as $object) {
            $calendar = $calendars[$object['calendarid']];
            if ($this->isWritableTeamCalendar($calendar)) {
                $paths[] = rtrim($homePath, '/') . '/' . $calendar->getName() . '/' . $object['uri'];
            }
        }
        return $paths;
    }

    private function calendarInstancesById(string $homePath): array {
        $calendars = [];
        foreach ($this->server->tree->getNodeForPath($homePath)->getChildren() as $calendar) {
            if ($calendar instanceof SharedCalendar) $calendars[$calendar->getCalendarId()] = $calendar;
        }
        return $calendars;
    }

    private function isWritableTeamCalendar($calendar): bool {
        if (!$calendar instanceof SharedCalendar) return false;
        if (!Utils::isTeamCalendarFromPrincipal($calendar->getOwner())) return false;
        return in_array($calendar->getShareAccess(), [\ESN\DAV\Sharing\Plugin::ACCESS_READWRITE,
            \ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION], true);
    }

    public function captureSchedulingRecipientBeforeMove($sourcePath, $destinationPath): void {
        unset($this->moveContexts[$destinationPath]);
        $source = $this->calendarObjectAt($sourcePath);
        if ($source === null) return;

        [$sourceCalendarPath, $sourceUri] = \Sabre\Uri\split($sourcePath);
        [$destinationCalendarPath, $destinationUri] = \Sabre\Uri\split($destinationPath);
        $sourceCalendar = $this->sharedCalendarAt($sourceCalendarPath);
        $destinationCalendar = $this->sharedCalendarAt($destinationCalendarPath);
        if ($sourceCalendar === null || $destinationCalendar === null) return;

        $recipient = $this->calendarBackend->getCalendarObjectSchedulingRecipient($sourceCalendar->getCalendarId(), $sourceUri);
        $recipient = $recipient ?? $this->inferSchedulingRecipient($source, $sourceCalendar, $destinationCalendar);
        if ($recipient === null) return;
        $this->moveContexts[$destinationPath] = [$destinationCalendar->getCalendarId(), $destinationUri, $recipient];
    }

    private function calendarObjectAt(string $path): ?ICalendarObject {
        $object = $this->server->tree->getNodeForPath($path);
        if (!$object instanceof ICalendarObject || $object instanceof ISchedulingObject) return null;
        return $object;
    }

    private function sharedCalendarAt(string $path): ?SharedCalendar {
        $calendar = $this->server->tree->getNodeForPath($path);
        return $calendar instanceof SharedCalendar ? $calendar : null;
    }

    private function inferSchedulingRecipient(ICalendarObject $source, SharedCalendar $sourceCalendar,
        SharedCalendar $destinationCalendar): ?string {
        $owner = $sourceCalendar->getOwner();
        if (!Utils::isUserPrincipal($owner)
            || !Utils::isTeamCalendarFromPrincipal($destinationCalendar->getOwner())) return null;

        $data = $source->get();
        $calendar = Reader::read(is_resource($data) ? stream_get_contents($data) : $data);
        try {
            return $calendar instanceof VCalendar && $this->isOwnerAttendee($calendar, $owner) ? $owner : null;
        } finally {
            $calendar->destroy();
        }
    }

    private function isOwnerAttendee(VCalendar $calendar, string $owner): bool {
        $addresses = $this->calendarUserAddresses($owner);
        if (!$addresses) return false;
        $hasOrganizer = $isAttendee = false;
        foreach ($calendar->select('VEVENT') as $event) {
            $organizer = isset($event->ORGANIZER) ? strtolower($event->ORGANIZER->getNormalizedValue()) : null;
            if ($organizer !== null && in_array($organizer, $addresses, true)) return false;
            $hasOrganizer = $hasOrganizer || $organizer !== null;
            $isAttendee = $isAttendee || CalendarObjectHelper::hasAttendeeInAddresses($event, $addresses);
        }
        return $hasOrganizer && $isAttendee;
    }

    private function calendarUserAddresses(string $principalUri): array {
        $property = '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set';
        try {
            $properties = $this->server->getProperties($principalUri, [$property]);
        } catch (\Sabre\DAV\Exception) { return []; }
        return isset($properties[$property]) ? array_map('strtolower', $properties[$property]->getHrefs()) : [];
    }

    public function saveSchedulingRecipientAfterMove($_sourcePath, $destinationPath): void {
        $context = $this->moveContexts[$destinationPath] ?? null;
        unset($this->moveContexts[$destinationPath]);
        if ($context !== null) {
            $this->calendarBackend->setCalendarObjectSchedulingRecipient(...$context);
        }
    }

    public function clearMovedSchedulingRecipients(): void {
        $this->moveContexts = [];
    }

}
