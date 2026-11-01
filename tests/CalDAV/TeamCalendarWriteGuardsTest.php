<?php

namespace ESN\CalDAV;

use ESN\DAV\Sharing\Plugin as SharingPlugin;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;
use Sabre\DAV\Xml\Element\Sharee;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Sabre\VObject\Reader;

/**
 * COPY, MOVE and PUT into a Team Calendar refuse a UID already used in the
 * Team Calendar. COPY and PUT also require the ORGANIZER to be a write-enabled
 * member of it; a MOVE may file an invitation from another organizer.
 */
class TeamCalendarWriteGuardsTest extends \PHPUnit\Framework\TestCase {
    const TEAM_CALENDAR_ID = 'team-physical';
    const EXISTING_UID = 'team-1';

    private $backend;
    private $server;

    function setUp(): void {
        $this->backend = $this->createStub(Backend\Mongo::class);
        $this->backend->method('hasCalendarObjectWithUid')
            ->willReturnCallback(fn($calendarId, $uid) => $calendarId === self::TEAM_CALENDAR_ID && $uid === self::EXISTING_UID);

        $acl = $this->createStub(\Sabre\DAVACL\Plugin::class);
        $acl->method('getPluginName')->willReturn('acl');
        $acl->method('getPrincipalByUri')->willReturnCallback(fn($uri) => [
            'mailto:writer@example.com' => 'principals/users/writer',
            'mailto:reader@example.com' => 'principals/users/reader'
        ][$uri] ?? null);

        $this->server = new Server([
            $this->calendar('personal', 'principals/users/writer', 'personal-physical', [
                'dup.ics' => $this->event(self::EXISTING_UID),
                'fresh.ics' => $this->event('fresh'),
                'by-reader.ics' => $this->event('by-reader', 'mailto:reader@example.com'),
                'by-writer.ics' => $this->event('by-writer', 'mailto:writer@example.com')
            ]),
            $this->calendar('team', 'principals/team-calendars/team', self::TEAM_CALENDAR_ID, [
                'team1.ics' => $this->event(self::EXISTING_UID)
            ]),
            $this->calendar('team-mirror', 'principals/team-calendars/team', self::TEAM_CALENDAR_ID, [])
        ]);
        $this->server->addPlugin($acl);
        $this->server->addPlugin(new Plugin());
        $this->server->addPlugin(new OrganizerValidationPlugin());
    }

    function testCopyOfAnEventWithAnExistingUidIntoATeamCalendarIsRefused() {
        $this->expectForbidden('The destination Team Calendar already contains an event with this UID.');

        $this->server->emit('beforeCopy', ['personal/dup.ics', 'team/new.ics']);
    }

    function testMoveOfAnEventWithAnExistingUidIntoATeamCalendarIsRefused() {
        $this->expectForbidden('The destination Team Calendar already contains an event with this UID.');

        $this->server->emit('beforeMove', ['personal/dup.ics', 'team/new.ics']);
    }

    function testCopyWithinATeamCalendarIsRefused() {
        $this->expectForbidden('The destination Team Calendar already contains an event with this UID.');

        $this->server->emit('beforeCopy', ['team/team1.ics', 'team-mirror/new.ics']);
    }

    function testMoveWithinATeamCalendarIsAccepted() {
        $this->assertTrue($this->server->emit('beforeMove', ['team/team1.ics', 'team-mirror/new.ics']));
    }

    function testCopyOfAnEventWithAnUnusedUidIntoATeamCalendarIsAccepted() {
        $this->assertTrue($this->server->emit('beforeCopy', ['personal/fresh.ics', 'team/new.ics']));
    }

    function testCopyIntoAPersonalCalendarIsAccepted() {
        $this->assertTrue($this->server->emit('beforeCopy', ['team/team1.ics', 'personal/new.ics']));
    }

    function testPutOfANewEventWithAnExistingUidIntoATeamCalendarIsRefused() {
        $this->expectForbidden('The destination Team Calendar already contains an event with this UID.');

        $this->emitCalendarObjectChange('PUT', 'team', true);
    }

    function testPutUpdatingAnEventOfATeamCalendarIsAccepted() {
        $this->assertTrue($this->emitCalendarObjectChange('PUT', 'team', false));
    }

    function testPutOfANewEventWithAnExistingUidIntoAPersonalCalendarIsAccepted() {
        $this->assertTrue($this->emitCalendarObjectChange('PUT', 'personal', true));
    }

    function testCopyOfAMeetingOrganizedByAReadOnlyMemberIntoATeamCalendarIsRefused() {
        $this->expectForbidden('The ORGANIZER must be a write-enabled team calendar member.');

        $this->server->emit('beforeCopy', ['personal/by-reader.ics', 'team/new.ics']);
    }

    function testMoveOfAnInvitationFromAnotherOrganizerIntoATeamCalendarIsAccepted() {
        $this->assertTrue($this->server->emit('beforeMove', ['personal/by-reader.ics', 'team/new.ics']));
    }

    function testCopyOfAMeetingOrganizedByAWriteEnabledMemberIntoATeamCalendarIsAccepted() {
        $this->assertTrue($this->server->emit('beforeCopy', ['personal/by-writer.ics', 'team/new.ics']));
    }

    function testCopyOfAMeetingOrganizedByAReadOnlyMemberIntoAPersonalCalendarIsAccepted() {
        $this->assertTrue($this->server->emit('beforeCopy', ['personal/by-reader.ics', 'personal/new.ics']));
    }

    private function expectForbidden(string $message): void {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage($message);
    }

    private function emitCalendarObjectChange(string $method, string $calendarPath, bool $isNew): bool {
        $vCal = Reader::read($this->event(self::EXISTING_UID));
        $modified = false;

        return $this->server->emit('calendarObjectChange',
            [new Request($method, '/' . $calendarPath . '/new.ics'), new Response(), $vCal, $calendarPath, &$modified, $isNew]);
    }

    private function calendar(string $name, string $owner, string $calendarId, array $objects): SharedCalendar {
        $calendar = $this->createStub(SharedCalendar::class);
        $calendar->method('getName')->willReturn($name);
        $calendar->method('getOwner')->willReturn($owner);
        $calendar->method('getCalendarId')->willReturn($calendarId);
        $calendar->method('getBackend')->willReturn($this->backend);
        $calendar->method('getInvites')->willReturn([
            new Sharee(['principal' => 'principals/users/writer', 'access' => SharingPlugin::ACCESS_READWRITE]),
            new Sharee(['principal' => 'principals/users/reader', 'access' => SharingPlugin::ACCESS_READ])
        ]);
        $calendar->method('getChild')->willReturnCallback(fn($objectName) => $this->calendarObject($objectName, $objects));

        return $calendar;
    }

    private function calendarObject(string $objectName, array $objects): \Sabre\CalDAV\ICalendarObject {
        if (!isset($objects[$objectName])) {
            throw new \Sabre\DAV\Exception\NotFound($objectName);
        }

        $object = $this->createStub(\Sabre\CalDAV\ICalendarObject::class);
        $object->method('getName')->willReturn($objectName);
        $object->method('get')->willReturn($objects[$objectName]);

        return $object;
    }

    private function event(string $uid, ?string $organizer = null): string {
        $meeting = $organizer ? "ORGANIZER:$organizer\r\nATTENDEE:mailto:other@example.com\r\n" : '';

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTART:20260101T100000Z\r\n"
            . "DTEND:20260101T110000Z\r\n{$meeting}END:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}
