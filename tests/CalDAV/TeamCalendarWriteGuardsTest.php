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
                'team1.ics' => $this->event(self::EXISTING_UID),
                'other.ics' => $this->event('other'),
                'task.ics' => $this->todo('task')
            ]),
            $this->calendar('team-mirror', 'principals/team-calendars/team', self::TEAM_CALENDAR_ID, []),
            $this->subscription('team-sub', 'team', [
                'team1.ics' => $this->event(self::EXISTING_UID),
                'other.ics' => $this->event('other')
            ])
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

        $this->emitCalendarObjectChange('PUT', 'team/new.ics', true);
    }

    function testPutUpdatingAnEventOfATeamCalendarIsAccepted() {
        $this->assertTrue($this->emitCalendarObjectChange('PUT', 'team/team1.ics', false));
    }

    function testPutUpdatingAnotherObjectToAnExistingUidOfATeamCalendarIsRefused() {
        $this->expectForbidden('The UID of a Team Calendar object cannot be changed.');

        $this->emitCalendarObjectChange('PUT', 'team/other.ics', false);
    }

    function testPutChangingTheUidOfATeamCalendarObjectToAnUnusedUidIsRefused() {
        // The object would keep its creation time, which orders the copies of the new UID
        $this->expectForbidden('The UID of a Team Calendar object cannot be changed.');

        $this->emitCalendarObjectChange('PUT', 'team/other.ics', false, $this->event('unused'));
    }

    function testPutThroughASubscriptionChangingTheUidOfATeamCalendarObjectIsRefused() {
        // A write through a subscription lands in its source Team Calendar
        $this->expectForbidden('The UID of a Team Calendar object cannot be changed.');

        $this->emitCalendarObjectChange('PUT', 'team-sub/other.ics', false, $this->event('unused'));
    }

    function testPutThroughASubscriptionOfANewEventWithAnExistingUidOfATeamCalendarIsRefused() {
        $this->expectForbidden('The destination Team Calendar already contains an event with this UID.');

        $this->emitCalendarObjectChange('PUT', 'team-sub/new.ics', true);
    }

    function testPutChangingTheUidOfATaskOfATeamCalendarIsRefused() {
        $this->expectForbidden('The UID of a Team Calendar object cannot be changed.');

        $this->emitCalendarObjectChange('PUT', 'team/task.ics', false, $this->todo('unused'));
    }

    function testPutUpdatingATaskOfATeamCalendarIsAccepted() {
        $this->assertTrue($this->emitCalendarObjectChange('PUT', 'team/task.ics', false, $this->todo('task')));
    }

    function testPutOfANewTaskWithAnExistingUidIntoATeamCalendarIsRefused() {
        $this->expectForbidden('The destination Team Calendar already contains an event with this UID.');

        $this->emitCalendarObjectChange('PUT', 'team/new.ics', true, $this->todo(self::EXISTING_UID));
    }

    function testPutOfANewTaskWithAnUnusedUidIntoATeamCalendarIsAccepted() {
        $this->assertTrue($this->emitCalendarObjectChange('PUT', 'team/new.ics', true, $this->todo('unused')));
    }

    function testPutChangingTheUidOfAPersonalCalendarObjectIsAccepted() {
        $this->assertTrue($this->emitCalendarObjectChange('PUT', 'personal/fresh.ics', false, $this->event('unused')));
    }

    function testPutOfANewEventWithAnExistingUidIntoAPersonalCalendarIsAccepted() {
        $this->assertTrue($this->emitCalendarObjectChange('PUT', 'personal/new.ics', true));
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

    public static function blankOrganizers(): array {
        return [
            'empty' => [''],
            'spaces' => ['   ']
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blankOrganizers')]
    function testPutOfAMeetingWithABlankOrganizerIntoATeamCalendarIsRefused(string $organizer) {
        $this->expectForbidden('A VEVENT with ATTENDEE properties must also have an ORGANIZER.');

        $this->emitCalendarObjectChange('PUT', 'team/team1.ics', false, $this->event(self::EXISTING_UID, $organizer));
    }

    function testPutOfAMeetingWithAFalsyOrganizerIntoATeamCalendarIsRefused() {
        $this->expectForbidden('The ORGANIZER must be a write-enabled team calendar member.');

        $this->emitCalendarObjectChange('PUT', 'team/team1.ics', false, $this->event(self::EXISTING_UID, '0'));
    }

    function testPutOfAnEventWithABlankOrganizerAndNoAttendeeIntoATeamCalendarIsAccepted() {
        $data = str_replace("DTEND:", "ORGANIZER:\r\nDTEND:", $this->event(self::EXISTING_UID));

        $this->assertTrue($this->emitCalendarObjectChange('PUT', 'team/team1.ics', false, $data));
    }

    public static function overrideOrganizersDifferingFromTheMaster(): array {
        return [
            'empty' => [''],
            'spaces' => ['   '],
            'trailing space' => ['mailto:writer@example.com '],
            'leading space' => [' mailto:writer@example.com']
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('overrideOrganizersDifferingFromTheMaster')]
    function testPutOfAMeetingWhoseOverrideHasABlankOrganizerIntoATeamCalendarIsRefused(string $organizer) {
        $data = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\nUID:series\r\nDTSTART:20260101T100000Z\r\nRRULE:FREQ=DAILY;COUNT=3\r\n"
            . "ORGANIZER:mailto:writer@example.com\r\nATTENDEE:mailto:other@example.com\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:series\r\nRECURRENCE-ID:20260102T100000Z\r\nDTSTART:20260102T110000Z\r\n"
            . "ORGANIZER:" . $organizer . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $this->expectForbidden('All VEVENT components must share the same ORGANIZER property.');

        $this->emitCalendarObjectChange('PUT', 'team/new.ics', true, $data);
    }

    function testPutOfAMeetingWhoseOverrideHasNoOrganizerIntoATeamCalendarIsAccepted() {
        $data = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\nUID:series\r\nDTSTART:20260101T100000Z\r\nRRULE:FREQ=DAILY;COUNT=3\r\n"
            . "ORGANIZER:mailto:writer@example.com\r\nATTENDEE:mailto:other@example.com\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:series\r\nRECURRENCE-ID:20260102T100000Z\r\nDTSTART:20260102T110000Z\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR\r\n";

        $this->assertTrue($this->emitCalendarObjectChange('PUT', 'team/new.ics', true, $data));
    }

    private function seriesWithOrganizers(string $master, string $override): string {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
            . "BEGIN:VEVENT\r\nUID:series\r\nDTSTART:20260101T100000Z\r\nRRULE:FREQ=DAILY;COUNT=3\r\n"
            . "ORGANIZER:" . $master . "\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:series\r\nRECURRENCE-ID:20260102T100000Z\r\nDTSTART:20260102T110000Z\r\n"
            . "ORGANIZER:" . $override . "\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('blankOrganizers')]
    function testPutOfAnEventWhoseEveryOrganizerIsTheSameBlankIntoATeamCalendarIsAccepted(string $organizer) {
        $this->assertTrue($this->emitCalendarObjectChange('PUT', 'team/new.ics', true,
            $this->seriesWithOrganizers($organizer, $organizer)));
    }

    function testPutOfAnEventMixingDifferentBlankOrganizersIntoATeamCalendarIsRefused() {
        // The iTIP broker compares them untrimmed.
        $this->expectForbidden('All VEVENT components must share the same ORGANIZER property.');

        $this->emitCalendarObjectChange('PUT', 'team/new.ics', true, $this->seriesWithOrganizers('', '   '));
    }

    function testOrganizersAcceptedForATeamCalendarAreAcceptedByTheITipBroker() {
        $data = $this->seriesWithOrganizers('mailto:writer@example.com', 'MAILTO:WRITER@example.com');

        $this->assertTrue($this->emitCalendarObjectChange('PUT', 'team/new.ics', true, $data));
        $this->assertIsArray((new \Sabre\VObject\ITip\Broker())->parseEvent(Reader::read($data), 'mailto:writer@example.com'));
    }

    private function expectForbidden(string $message): void {
        $this->expectException(Forbidden::class);
        $this->expectExceptionMessage($message);
    }

    private function emitCalendarObjectChange(string $method, string $objectPath, bool $isNew, ?string $data = null): bool {
        $vCal = Reader::read($data ?? $this->event(self::EXISTING_UID));
        $modified = false;
        list($calendarPath,) = \Sabre\Uri\split($objectPath);

        return $this->server->emit('calendarObjectChange',
            [new Request($method, '/' . $objectPath), new Response(), $vCal, $calendarPath, &$modified, $isNew]);
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

    private function subscription(string $name, string $sourcePath, array $objects): Subscriptions\Subscription {
        $subscription = $this->createStub(Subscriptions\Subscription::class);
        $subscription->method('getName')->willReturn($name);
        $subscription->method('getSourceCalendarPath')->willReturn($sourcePath);
        $subscription->method('getChild')->willReturnCallback(fn($objectName) => $this->calendarObject($objectName, $objects));

        return $subscription;
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
        $meeting = $organizer !== null ? "ORGANIZER:$organizer\r\nATTENDEE:mailto:other@example.com\r\n" : '';

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:$uid\r\nDTSTART:20260101T100000Z\r\n"
            . "DTEND:20260101T110000Z\r\n{$meeting}END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function todo(string $uid): string {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTODO\r\nUID:$uid\r\nDTSTAMP:20260101T100000Z\r\n"
            . "SUMMARY:Task\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";
    }
}
