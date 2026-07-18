<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/Sabre/HTTP/SapiMock.php';

use ESN\Utils\AuthTenant;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Sabre\VObject\Reader;

/**
 * @medium
 */
class OrganizerValidationPluginTest extends \PHPUnit\Framework\TestCase {

    private $server;
    private $caldavBackend;
    private $authBackend;
    private $teamCalendarId;

    const USER1_ID = '54b64eadf6d7d8e41d263e0f';
    const USER1_EMAIL = 'alice@example.org';
    const USER1_PRINCIPAL = 'principals/users/54b64eadf6d7d8e41d263e0f';

    const USER2_ID = '54b64eadf6d7d8e41d263e0e';
    const USER2_EMAIL = 'bob@example.org';
    const USER2_PRINCIPAL = 'principals/users/54b64eadf6d7d8e41d263e0e';

    const DOMAIN_ID = '54b64eadf6d7d8e41d263e7a';
    const TEAM_CALENDAR_ID = '64b64eadf6d7d8e41d263e0f';
    const TEAM_CALENDAR_PRINCIPAL = 'principals/team-calendars/64b64eadf6d7d8e41d263e0f';

    function setUp(): void {
        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $esndb = $mcesn->{ESN_MONGO_ESNDB};

        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $sabredb->drop();
        $esndb->drop();

        $domainId = new \MongoDB\BSON\ObjectId(self::DOMAIN_ID);
        $esndb->domains->insertOne(['_id' => $domainId, 'name' => 'example.org']);
        $esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER1_ID),
            'accounts' => [['type' => 'email', 'emails' => [self::USER1_EMAIL]]],
            'domains'  => [['domain_id' => $domainId]],
        ]);
        $esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER2_ID),
            'accounts' => [['type' => 'email', 'emails' => [self::USER2_EMAIL]]],
            'domains'  => [['domain_id' => $domainId]],
        ]);

        list($this->caldavBackend, $this->authBackend) = $this->initServer($esndb, $sabredb);

        $this->authBackend->setPrincipal(self::USER1_PRINCIPAL);

        $this->caldavBackend->createCalendar(self::USER1_PRINCIPAL, 'cal1', [
            'principaluri' => self::USER1_PRINCIPAL,
            'uri' => 'cal1',
            '{DAV:}displayname' => 'User 1 Calendar',
        ]);
        $this->caldavBackend->createCalendar(self::USER2_PRINCIPAL, 'cal2', [
            'principaluri' => self::USER2_PRINCIPAL,
            'uri' => 'cal2',
            '{DAV:}displayname' => 'User 2 Calendar',
        ]);

        $esndb->team_calendars->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::TEAM_CALENDAR_ID),
            'domainId' => $domainId,
            'name' => 'Team Calendar',
        ]);
        $this->teamCalendarId = $this->caldavBackend->createCalendar(self::TEAM_CALENDAR_PRINCIPAL, self::TEAM_CALENDAR_ID, [
            'principaluri' => self::TEAM_CALENDAR_PRINCIPAL,
            'uri' => self::TEAM_CALENDAR_ID,
            '{DAV:}displayname' => 'Team Calendar',
        ]);
    }

    private function initServer(\MongoDB\Database $esndb, \MongoDB\Database $sabredb): array {
        $authTenant = new AuthTenant(self::USER1_ID, self::DOMAIN_ID);
        $principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($esndb, $authTenant);
        $caldavBackend = new \ESN\CalDAV\Backend\Mongo($sabredb);
        $calendarRoot = new \ESN\CalDAV\CalendarRoot($principalBackend, $caldavBackend, $esndb);
        $calendarRoot->setAuthTenant($authTenant);

        $tree = [
            new \Sabre\DAV\SimpleCollection('principals', [
                new \Sabre\CalDAV\Principal\Collection($principalBackend, 'principals/users'),
                new \Sabre\CalDAV\Principal\Collection($principalBackend, 'principals/domains'),
            ]),
            $calendarRoot,
        ];

        $this->server = new \Sabre\DAV\Server($tree);
        $this->server->sapi = new \Sabre\HTTP\SapiMock();
        $this->server->debugExceptions = true;

        $this->server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
        $this->server->addPlugin(new \Sabre\CalDAV\SharingPlugin());
        $this->server->addPlugin(new \ESN\CalDAV\Plugin());

        $authBackend = new \Sabre\DAV\Auth\Backend\Mock();
        $this->server->addPlugin(new \Sabre\DAV\Auth\Plugin($authBackend));

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals', 'principals/users'];
        $this->server->addPlugin($aclPlugin);

        $this->server->addPlugin(new OrganizerValidationPlugin());

        return [$caldavBackend, $authBackend];
    }

    private function putIcs(string $userId, string $calUri, string $eventUri, string $ics): \Sabre\HTTP\ResponseInterface {
        $path = '/calendars/' . $userId . '/' . $calUri . '/' . $eventUri;
        $request = new \Sabre\HTTP\Request('PUT', $path, ['Content-Type' => 'text/calendar; charset=utf-8'], $ics);
        $this->server->httpRequest = $request;
        $this->server->httpResponse = new \Sabre\HTTP\Response();
        $this->server->exec();
        return $this->server->httpResponse;
    }

    private function emitCalendarObjectChange(string $calendarPath, string $ics): void {
        $vCal = Reader::read($ics);
        $modified = false;
        $request = new Request('PUT', '/' . $calendarPath . '/event.ics');
        $response = new Response();
        $this->server->emit('calendarObjectChange', [$request, $response, $vCal, $calendarPath, &$modified, true]);
    }

    function testAcceptsEventWithNoOrganizer() {
        $ics = $this->makeIcs('no-organizer', '');
        $response = $this->putIcs(self::USER1_ID, 'cal1', 'event.ics', $ics);
        $this->assertContains($response->getStatus(), [201, 204]);
    }

    function testAcceptsEventWithOrganizerMatchingCalendarOwner() {
        $ics = $this->makeIcs('organizer-owner', 'ORGANIZER:mailto:' . self::USER1_EMAIL);
        $response = $this->putIcs(self::USER1_ID, 'cal1', 'event.ics', $ics);
        $this->assertContains($response->getStatus(), [201, 204]);
    }

    function testAcceptsRecurringEventWhereAllVEventsShareSameOrganizer() {
        $ics = $this->makeRecurringIcs(
            'recurring-same-organizer',
            'ORGANIZER:mailto:alice@example.org',
            'ORGANIZER:mailto:alice@example.org'
        );
        $response = $this->putIcs(self::USER1_ID, 'cal1', 'event.ics', $ics);
        $this->assertContains($response->getStatus(), [201, 204]);
    }

    function testRejectsEventWithArbitraryOrganizer() {
        $ics = $this->makeIcs('arbitrary-organizer', 'ORGANIZER:mailto:attacker@evil.com');
        $response = $this->putIcs(self::USER1_ID, 'cal1', 'event.ics', $ics);
        $this->assertEquals(403, $response->getStatus());
    }

    function testRejectsAttendeeWithoutOrganizer() {
        $ics = $this->makeIcs('attendee-no-organizer', 'ATTENDEE:mailto:bob@example.org');
        $response = $this->putIcs(self::USER1_ID, 'cal1', 'event.ics', $ics);
        $this->assertEquals(403, $response->getStatus());
    }

    function testRejectsDifferentOrganizersAcrossVEvents() {
        $ics = $this->makeRecurringIcs(
            'diff-organizers',
            'ORGANIZER:mailto:alice@example.org',
            'ORGANIZER:mailto:bob@example.org'
        );
        $response = $this->putIcs(self::USER1_ID, 'cal1', 'event.ics', $ics);
        $this->assertEquals(403, $response->getStatus());
    }

    function testSkipsItipRequests() {
        $ics = $this->makeIcs('itip-event', 'ORGANIZER:mailto:attacker@evil.com');
        $vCal = Reader::read($ics);
        $calendarPath = 'calendars/' . self::USER1_ID . '/cal1';
        $modified = false;
        $request = new Request('ITIP', '/itip');
        $response = new Response();

        $this->server->emit('calendarObjectChange', [$request, $response, $vCal, $calendarPath, &$modified, true]);

        $this->assertTrue(true);
    }

    function testDelegationScenario_OrganizerIsCalendarOwner_ShouldAccept() {
        // Alice (auth user) writes into Bob's calendar with ORGANIZER=Bob.
        // The organizer matches the calendar owner, so it must be accepted.
        $ics = $this->makeIcs('delegation-owner-org', 'ORGANIZER:mailto:' . self::USER2_EMAIL);
        $calendarPath = 'calendars/' . self::USER2_ID . '/cal2';
        $this->emitCalendarObjectChange($calendarPath, $ics);
        $this->assertTrue(true);
    }

    function testDelegationScenario_OrganizerIsAuthUser_ShouldAccept() {
        // Alice (auth user) writes into Bob's calendar with ORGANIZER=Alice.
        // The organizer matches the connected user, so it must be accepted.
        $ics = $this->makeIcs('delegation-authuser-org', 'ORGANIZER:mailto:' . self::USER1_EMAIL);
        $calendarPath = 'calendars/' . self::USER2_ID . '/cal2';

        // Prime the auth plugin with the current principal by making a valid request first
        $this->putIcs(self::USER1_ID, 'cal1', 'prime.ics', $this->makeIcs('prime', ''));

        $this->emitCalendarObjectChange($calendarPath, $ics);
        $this->assertTrue(true);
    }

    function testRejectsOrganizerThatIsNeitherOwnerNorAuthUser() {
        // Alice writes into her own calendar with ORGANIZER=Bob. Must be rejected.
        $ics = $this->makeIcs('self-cal-wrong-org', 'ORGANIZER:mailto:' . self::USER2_EMAIL);
        $response = $this->putIcs(self::USER1_ID, 'cal1', 'event.ics', $ics);
        $this->assertEquals(403, $response->getStatus());
    }

    function testTeamCalendarAcceptsOrganizerMatchingWriteEnabledMember() {
        $this->shareTeamCalendarWith(self::USER2_EMAIL, self::USER2_PRINCIPAL, \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE);

        $ics = $this->makeIcs('team-write-member-org', 'ORGANIZER:mailto:' . self::USER2_EMAIL);
        $this->emitCalendarObjectChange($this->teamCalendarPath(), $ics);

        $this->assertTrue(true);
    }

    function testTeamCalendarAcceptsOrganizerMatchingManagerMember() {
        $this->shareTeamCalendarWith(self::USER2_EMAIL, self::USER2_PRINCIPAL, \ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION);

        $ics = $this->makeIcs('team-manager-member-org', 'ORGANIZER:mailto:' . self::USER2_EMAIL);
        $this->emitCalendarObjectChange($this->teamCalendarPath(), $ics);

        $this->assertTrue(true);
    }

    function testTeamCalendarRejectsOrganizerMatchingReadOnlyMember() {
        $this->shareTeamCalendarWith(self::USER2_EMAIL, self::USER2_PRINCIPAL, \Sabre\DAV\Sharing\Plugin::ACCESS_READ);

        $ics = $this->makeIcs('team-read-member-org', 'ORGANIZER:mailto:' . self::USER2_EMAIL);

        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);
        $this->emitCalendarObjectChange($this->teamCalendarPath(), $ics);
    }

    function testTeamCalendarRejectsOrganizerMatchingCurrentUserWhenNotMember() {
        $ics = $this->makeIcs('team-current-user-not-member-org', 'ORGANIZER:mailto:' . self::USER1_EMAIL);

        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);
        $this->emitCalendarObjectChange($this->teamCalendarPath(), $ics);
    }

    private function shareTeamCalendarWith(string $email, string $principal, int $access): void {
        $this->caldavBackend->updateInvites($this->teamCalendarId, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:' . $email,
                'principal' => $principal,
                'access' => $access,
                'properties' => [],
            ])
        ]);
    }

    private function teamCalendarPath(): string {
        return 'calendars/' . self::TEAM_CALENDAR_ID . '/' . self::TEAM_CALENDAR_ID;
    }

    private function makeRecurringIcs(string $uid, string $masterOrganizer, string $overrideOrganizer): string {
        return <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VEVENT
UID:{$uid}
DTSTART:20260701T100000Z
DTEND:20260701T110000Z
SUMMARY:Master
{$masterOrganizer}
RRULE:FREQ=DAILY;COUNT=3
END:VEVENT
BEGIN:VEVENT
UID:{$uid}
RECURRENCE-ID:20260702T100000Z
DTSTART:20260702T120000Z
DTEND:20260702T130000Z
SUMMARY:Override
{$overrideOrganizer}
END:VEVENT
END:VCALENDAR
ICS;
    }

    private function makeIcs(string $uid, string $extraLine): string {
        $body = $extraLine !== '' ? $extraLine . "\r\n" : '';
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//Test//EN\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTART:20260701T100000Z\r\nDTEND:20260701T110000Z\r\nSUMMARY:Test\r\n{$body}END:VEVENT\r\nEND:VCALENDAR\r\n";
    }
}
