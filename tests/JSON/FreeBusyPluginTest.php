<?php

namespace ESN\JSON;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class FreeBusyPluginTest extends \ESN\DAV\ServerMock {

    use \Sabre\VObject\PHPUnitAssertions;

    const USER1_ID = '54b64eadf6d7d8e41d263e0f';
    const USER1_EMAIL = 'robertocarlos@realmadrid.com';

    protected $freebusyBulkData = [
        'start' => '20120101T000000Z',
        'end' => '20150101T000000Z',
        'users' => ['54b64eadf6d7d8e41d263e0f']
    ];

    protected $freebusyBulkWithFilterData = [
        'start' => '20120101T000000Z',
        'end' => '20150101T000000Z',
        'users' => ['54b64eadf6d7d8e41d263e0f'],
        'uids' => ['event1']
    ];

    protected $freebusyBulkWithDurationEvent = [
        'start' => '20180401T000000Z',
        'end' => '20180401T003000Z',
        'users' => ['54b64eadf6d7d8e41d263e0f'],
        'uids' => ['event1']
    ];

    protected $freebusyBulkWithRecurringEvent = [
        'start' => '20180501T010000Z',
        'end' => '20180501T013000Z',
        'users' => ['54b64eadf6d7d8e41d263e0f'],
        'uids' => ['event1']
    ];

    protected $freebusyBulkInvalidData = [
        'start' => '20180501T010000Z',
        'end' => '20180501T013000Z',
        'users' => ['invalid', 'something'],
        'uids' => ['event1']
    ];

    protected $durationEvent =
        'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20180313T142342Z
UID:28CCB90C-0F2F-48FC-B1D9-33A2BA3D9595
TRANSP:OPAQUE
SUMMARY:Event with duration
DTSTART:20180401T000000Z
DURATION:PT3H
DTSTAMP:20180313T142416Z
SEQUENCE:1
ORGANIZER;CN=John0 Doe0:mailto:robertocarlos@realmadrid.com
ATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=FALSE;CN=John0 Doe0:mailto:robertocarlos@realmadrid.com
END:VEVENT
END:VCALENDAR
';

    protected $acceptedDurationEvent =
        'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20180313T142342Z
UID:28CCB90C-0F2F-48FC-B1D9-33A2BA3D9595
TRANSP:OPAQUE
SUMMARY:Event with duration
DTSTART:20180401T000000Z
DURATION:PT3H
DTSTAMP:20180313T142416Z
SEQUENCE:1
ORGANIZER;CN=John0 Doe0:mailto:robertocarlos@realmadrid.com
ATTENDEE;PARTSTAT=ACCEPTED;RSVP=FALSE;CN=John0 Doe0:mailto:robertocarlos@realmadrid.com
END:VEVENT
END:VCALENDAR
';

    function setUp(): void {
        parent::setUp();

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals/users'];
        $this->server->addPlugin($aclPlugin);

        $plugin = new FreeBusyPlugin('caldav-freebusy');
        $this->server->addPlugin($plugin);

        $this->caldavBackend->createCalendarObject($this->cal['id'], 'event3.ics', $this->durationEvent);
    }

    function testFreeBusy() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $request->setBody(json_encode($this->freebusyBulkData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);
        $this->assertCount(1, $jsonResponse->users);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $jsonResponse->users[0]->calendars);
        $this->assertCount(2, $jsonResponse->users[0]->calendars[0]->busy);
    }

    function testFreeBusyWithFilteredEvent() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $request->setBody(json_encode($this->freebusyBulkWithFilterData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);
        $this->assertCount(1, $jsonResponse->users);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $jsonResponse->users[0]->calendars);
        $this->assertCount(1, $jsonResponse->users[0]->calendars[0]->busy);
    }

    function testFreeBusyWithDurationEvent() {
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'event4.ics', $this->acceptedDurationEvent);

        $jsonResponse = $this->requestFreeBusy($this->freebusyBulkWithDurationEvent);

        $this->assertCount(1, $jsonResponse->users);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $jsonResponse->users[0]->calendars);

        // Both the NEEDS-ACTION and the ACCEPTED copy hold their slot, and
        // DURATION:PT3H gives them an end three hours after their DTSTART.
        $durationPeriod = (object) [
            'uid' => '28CCB90C-0F2F-48FC-B1D9-33A2BA3D9595',
            'start' => '20180401T000000Z',
            'end' => '20180401T030000Z'
        ];

        $this->assertEquals([$durationPeriod, $durationPeriod], $jsonResponse->users[0]->calendars[0]->busy);
    }

    function testFreeBusyWithRecurringEvent() {
        // recur.ics starts on 20150227T010000 and repeats daily: the occurrence
        // the requested window covers is the one of 2018-05-01, not the master.
        $jsonResponse = $this->requestFreeBusy($this->freebusyBulkWithRecurringEvent);

        $this->assertCount(1, $jsonResponse->users);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $jsonResponse->users[0]->calendars);
        $this->assertEquals(
            [(object) ['uid' => 'recur', 'start' => '20180501T010000Z', 'end' => '20180501T020000Z']],
            $jsonResponse->users[0]->calendars[0]->busy
        );
    }

    function testFreeBusyShouldReturnOneBusyPeriodPerOccurrenceOfADailyEvent() {
        $jsonResponse = $this->requestFreeBusy([
            'start' => '20180501T000000Z',
            'end' => '20180504T000000Z',
            'users' => [self::USER1_ID]
        ]);

        $this->assertEquals(
            [
                (object) ['uid' => 'recur', 'start' => '20180501T010000Z', 'end' => '20180501T020000Z'],
                (object) ['uid' => 'recur', 'start' => '20180502T010000Z', 'end' => '20180502T020000Z'],
                (object) ['uid' => 'recur', 'start' => '20180503T010000Z', 'end' => '20180503T020000Z']
            ],
            $jsonResponse->users[0]->calendars[0]->busy
        );
    }

    function testFreeBusyShouldUseTheDatesOfARecurrenceIdOverride() {
        // recur.ics overrides its 20150228T010000 occurrence, moving it to 03:00.
        $jsonResponse = $this->requestFreeBusy([
            'start' => '20150228T000000Z',
            'end' => '20150301T000000Z',
            'users' => [self::USER1_ID]
        ]);

        $this->assertEquals(
            [(object) ['uid' => 'recur', 'start' => '20150228T030000Z', 'end' => '20150228T040000Z']],
            $jsonResponse->users[0]->calendars[0]->busy
        );
    }

    function testFreeBusyShouldSkipAnExcludedOccurrence() {
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'exdate.ics',
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-with-exdate
SUMMARY:Daily standup, cancelled on the second day
DTSTART:20180601T090000Z
DTEND:20180601T100000Z
RRULE:FREQ=DAILY;COUNT=3
EXDATE:20180602T090000Z
END:VEVENT
END:VCALENDAR
');

        $jsonResponse = $this->requestFreeBusy([
            'start' => '20180601T000000Z',
            'end' => '20180604T000000Z',
            'users' => [self::USER1_ID],
            'uids' => ['recur']
        ]);

        $this->assertEquals(
            [
                (object) ['uid' => 'event-with-exdate', 'start' => '20180601T090000Z', 'end' => '20180601T100000Z'],
                (object) ['uid' => 'event-with-exdate', 'start' => '20180603T090000Z', 'end' => '20180603T100000Z']
            ],
            $jsonResponse->users[0]->calendars[0]->busy
        );
    }

    function testFreeBusyShouldConvertDatesToUtc() {
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'paris.ics',
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VTIMEZONE
TZID:Europe/Paris
BEGIN:DAYLIGHT
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
TZNAME:CEST
DTSTART:19700329T020000
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU
END:DAYLIGHT
BEGIN:STANDARD
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
TZNAME:CET
DTSTART:19701025T030000
RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
UID:paris-event
SUMMARY:Lunch in Paris
DTSTART;TZID=Europe/Paris:20180701T140000
DTEND;TZID=Europe/Paris:20180701T150000
END:VEVENT
END:VCALENDAR
');

        $jsonResponse = $this->requestFreeBusy([
            'start' => '20180701T000000Z',
            'end' => '20180702T000000Z',
            'users' => [self::USER1_ID],
            'uids' => ['recur']
        ]);

        // Paris is UTC+2 in July.
        $this->assertEquals(
            [(object) ['uid' => 'paris-event', 'start' => '20180701T120000Z', 'end' => '20180701T130000Z']],
            $jsonResponse->users[0]->calendars[0]->busy
        );
    }

    function testFreeBusyShouldGiveAnEndToAnEventWithNeitherDtendNorDuration() {
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'no-end.ics',
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-without-end
SUMMARY:No DTEND, no DURATION
DTSTART:20180901T090000Z
END:VEVENT
END:VCALENDAR
');

        $jsonResponse = $this->requestFreeBusy([
            'start' => '20180901T000000Z',
            'end' => '20180902T000000Z',
            'users' => [self::USER1_ID],
            'uids' => ['recur']
        ]);

        // RFC 5545 3.6.1: a DATE-TIME DTSTART alone means a zero duration.
        $this->assertEquals(
            [(object) ['uid' => 'event-without-end', 'start' => '20180901T090000Z', 'end' => '20180901T090000Z']],
            $jsonResponse->users[0]->calendars[0]->busy
        );
    }

    function testFreeBusyShouldIgnoreATransparentEvent() {
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'transparent.ics',
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:transparent-event
SUMMARY:Does not block the calendar
TRANSP:TRANSPARENT
DTSTART:20181101T090000Z
DTEND:20181101T100000Z
END:VEVENT
END:VCALENDAR
');
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'opaque.ics',
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:opaque-event
SUMMARY:Blocks the calendar
TRANSP:OPAQUE
DTSTART:20181101T100000Z
DTEND:20181101T110000Z
END:VEVENT
END:VCALENDAR
');

        $jsonResponse = $this->requestFreeBusy([
            'start' => '20181101T080000Z',
            'end' => '20181101T120000Z',
            'users' => [self::USER1_ID]
        ]);

        $this->assertEquals(
            [(object) ['uid' => 'opaque-event', 'start' => '20181101T100000Z', 'end' => '20181101T110000Z']],
            $jsonResponse->users[0]->calendars[0]->busy
        );
    }

    function testFreeBusyShouldIgnoreACancelledEvent() {
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'cancelled.ics',
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:cancelled-event
SUMMARY:Called off
STATUS:CANCELLED
DTSTART:20181201T090000Z
DTEND:20181201T100000Z
END:VEVENT
END:VCALENDAR
');
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'confirmed.ics',
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:confirmed-event
SUMMARY:Still on
STATUS:CONFIRMED
DTSTART:20181201T100000Z
DTEND:20181201T110000Z
END:VEVENT
END:VCALENDAR
');

        $jsonResponse = $this->requestFreeBusy([
            'start' => '20181201T080000Z',
            'end' => '20181201T120000Z',
            'users' => [self::USER1_ID]
        ]);

        $this->assertEquals(
            [(object) ['uid' => 'confirmed-event', 'start' => '20181201T100000Z', 'end' => '20181201T110000Z']],
            $jsonResponse->users[0]->calendars[0]->busy
        );
    }

    function testFreeBusyShouldIgnoreOnlyTheCancelledOccurrenceOfARecurringEvent() {
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'cancelled-occurrence.ics',
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:partly-cancelled-event
SUMMARY:Daily standup
DTSTART:20190101T090000Z
DTEND:20190101T100000Z
RRULE:FREQ=DAILY;COUNT=3
END:VEVENT
BEGIN:VEVENT
UID:partly-cancelled-event
RECURRENCE-ID:20190102T090000Z
SUMMARY:Daily standup
STATUS:CANCELLED
DTSTART:20190102T090000Z
DTEND:20190102T100000Z
END:VEVENT
END:VCALENDAR
');

        $jsonResponse = $this->requestFreeBusy([
            'start' => '20190101T000000Z',
            'end' => '20190104T000000Z',
            'users' => [self::USER1_ID],
            'uids' => ['recur']
        ]);

        $this->assertEquals(
            [
                (object) ['uid' => 'partly-cancelled-event', 'start' => '20190101T090000Z', 'end' => '20190101T100000Z'],
                (object) ['uid' => 'partly-cancelled-event', 'start' => '20190103T090000Z', 'end' => '20190103T100000Z']
            ],
            $jsonResponse->users[0]->calendars[0]->busy
        );
    }

    function testFreeBusyShouldReturnAJsonArrayWhenAnEventIsFilteredOutWithoutUids() {
        // The declined event is created first, so dropping it leaves a hole in
        // the keys of the busy list unless they are reindexed.
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'declined-first.ics',
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:declined-first
SUMMARY:Declined
DTSTART:20181001T090000Z
DTEND:20181001T100000Z
ORGANIZER;CN=Boss:mailto:boss@example.com
ATTENDEE;PARTSTAT=DECLINED;CN=Roberto Carlos:mailto:robertocarlos@realmadrid.com
END:VEVENT
END:VCALENDAR
');
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'accepted-second.ics',
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:accepted-second
SUMMARY:Accepted
DTSTART:20181001T100000Z
DTEND:20181001T110000Z
ORGANIZER;CN=Boss:mailto:boss@example.com
ATTENDEE;PARTSTAT=ACCEPTED;CN=Roberto Carlos:mailto:robertocarlos@realmadrid.com
END:VEVENT
END:VCALENDAR
');

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));
        $request->setBody(json_encode([
            'start' => '20181001T080000Z',
            'end' => '20181001T120000Z',
            'users' => [self::USER1_ID]
        ]));
        $response = $this->request($request);

        $this->assertEquals(200, $response->status);
        $this->assertStringContainsString('"busy":[', $response->getBodyAsString());

        $busy = json_decode($response->getBodyAsString())->users[0]->calendars[0]->busy;

        $this->assertIsArray($busy);
        $this->assertEquals(
            [(object) ['uid' => 'accepted-second', 'start' => '20181001T100000Z', 'end' => '20181001T110000Z']],
            $busy
        );
    }

    function testFreeBusyWithInvalidData() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $request->setBody(json_encode($this->freebusyBulkInvalidData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);
        $this->assertCount(0, $jsonResponse->users);
    }

    /**
     * An event sitting in a calendar occupies it. Only an explicit refusal by
     * the principal being asked about frees the slot back up: issue #172 used
     * to drop NEEDS-ACTION events too, which let a proposed booking slot be
     * booked a second time.
     */
    function testFreeBusyShouldIgnoreADeclinedEvent() {
        $this->createEventWithAttendees('declined.ics', 'declined-event', '20190201T090000Z', '20190201T100000Z', [
            'ATTENDEE;PARTSTAT=DECLINED;CN=Roberto Carlos:mailto:' . self::USER1_EMAIL
        ]);

        $this->assertEquals([], $this->getBusyPeriods('20190201T080000Z', '20190201T110000Z'));
    }

    function testFreeBusyShouldKeepANeedsActionEvent() {
        $this->createEventWithAttendees('needs-action.ics', 'needs-action-event', '20190202T090000Z', '20190202T100000Z', [
            'ATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;CN=Roberto Carlos:mailto:' . self::USER1_EMAIL
        ]);

        $this->assertEquals(
            [(object) ['uid' => 'needs-action-event', 'start' => '20190202T090000Z', 'end' => '20190202T100000Z']],
            $this->getBusyPeriods('20190202T080000Z', '20190202T110000Z')
        );
    }

    function testFreeBusyShouldKeepAnAcceptedEvent() {
        $this->createEventWithAttendees('accepted.ics', 'accepted-event', '20190203T090000Z', '20190203T100000Z', [
            'ATTENDEE;PARTSTAT=ACCEPTED;CN=Roberto Carlos:mailto:' . self::USER1_EMAIL
        ]);

        $this->assertEquals(
            [(object) ['uid' => 'accepted-event', 'start' => '20190203T090000Z', 'end' => '20190203T100000Z']],
            $this->getBusyPeriods('20190203T080000Z', '20190203T110000Z')
        );
    }

    function testFreeBusyShouldKeepAnEventWhoseAttendeeHasNoPartstat() {
        $this->createEventWithAttendees('no-partstat.ics', 'no-partstat-event', '20190204T090000Z', '20190204T100000Z', [
            'ATTENDEE;CN=Roberto Carlos:mailto:' . self::USER1_EMAIL
        ]);

        $this->assertEquals(
            [(object) ['uid' => 'no-partstat-event', 'start' => '20190204T090000Z', 'end' => '20190204T100000Z']],
            $this->getBusyPeriods('20190204T080000Z', '20190204T110000Z')
        );
    }

    /**
     * What a booking in a team calendar looks like: the attendees are the owner
     * of the booking link and whoever booked it, never the address of the team
     * calendar the event lands in. The slot it takes must still show as busy to
     * the other members, otherwise it can be booked over and over.
     */
    function testFreeBusyShouldKeepAnEventThePrincipalIsNotAnAttendeeOf() {
        $this->createEventWithAttendees('other-attendees.ics', 'other-attendees-event', '20190205T090000Z', '20190205T100000Z', [
            'ATTENDEE;PARTSTAT=ACCEPTED;CN=Boss:mailto:boss@example.com',
            'ATTENDEE;PARTSTAT=DECLINED;CN=Someone Else:mailto:someone-else@example.com'
        ]);

        $this->assertEquals(
            [(object) ['uid' => 'other-attendees-event', 'start' => '20190205T090000Z', 'end' => '20190205T100000Z']],
            $this->getBusyPeriods('20190205T080000Z', '20190205T110000Z')
        );
    }

    function testFreeBusyShouldKeepAnEventWhenThePrincipalEmailIsUnknown() {
        $this->createEventWithAttendees('unknown-email.ics', 'unknown-email-event', '20190206T090000Z', '20190206T100000Z', [
            'ATTENDEE;PARTSTAT=DECLINED;CN=Roberto Carlos:mailto:' . self::USER1_EMAIL
        ]);

        $nodePath = 'calendars/' . self::USER1_ID;
        $params = (object) ['start' => '20190206T080000Z', 'end' => '20190206T110000Z'];

        // Not knowing whose calendar this is says nothing about a refusal, so
        // the event keeps its slot.
        $calendars = $this->server->getPlugin('caldav-freebusy')->getFreeBusyCalendars(
            $nodePath,
            $this->server->tree->getNodeForPath($nodePath),
            $params,
            ''
        );

        $this->assertEquals(
            [(object) ['uid' => 'unknown-email-event', 'start' => '20190206T090000Z', 'end' => '20190206T100000Z']],
            $calendars[0]->busy
        );
    }

    function testFreeBusyShouldIgnoreOnlyTheDeclinedOccurrenceOfARecurringEvent() {
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'declined-occurrence.ics',
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:partly-declined-event
SUMMARY:Daily standup
DTSTART:20190301T090000Z
DTEND:20190301T100000Z
RRULE:FREQ=DAILY;COUNT=3
ORGANIZER;CN=Boss:mailto:boss@example.com
ATTENDEE;PARTSTAT=ACCEPTED;CN=Roberto Carlos:mailto:robertocarlos@realmadrid.com
END:VEVENT
BEGIN:VEVENT
UID:partly-declined-event
RECURRENCE-ID:20190302T090000Z
SUMMARY:Daily standup
DTSTART:20190302T090000Z
DTEND:20190302T100000Z
ORGANIZER;CN=Boss:mailto:boss@example.com
ATTENDEE;PARTSTAT=DECLINED;CN=Roberto Carlos:mailto:robertocarlos@realmadrid.com
END:VEVENT
END:VCALENDAR
');

        $this->assertEquals(
            [
                (object) ['uid' => 'partly-declined-event', 'start' => '20190301T090000Z', 'end' => '20190301T100000Z'],
                (object) ['uid' => 'partly-declined-event', 'start' => '20190303T090000Z', 'end' => '20190303T100000Z']
            ],
            $this->getBusyPeriods('20190301T000000Z', '20190304T000000Z', ['recur'])
        );
    }

    /**
     * Creates an event in user1's first calendar, with the given ATTENDEE lines.
     */
    private function createEventWithAttendees($uri, $uid, $dtstart, $dtend, $attendees) {
        $this->caldavBackend->createCalendarObject($this->cal['id'], $uri, implode("\n", array_merge([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'SUMMARY:' . $uid,
            'DTSTART:' . $dtstart,
            'DTEND:' . $dtend,
            'ORGANIZER;CN=Boss:mailto:boss@example.com'
        ], $attendees, [
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ])));
    }

    /**
     * Returns the busy periods of user1's first calendar over the given window.
     */
    private function getBusyPeriods($start, $end, $uids = null) {
        $data = ['start' => $start, 'end' => $end, 'users' => [self::USER1_ID]];

        if (!is_null($uids)) {
            $data['uids'] = $uids;
        }

        return $this->requestFreeBusy($data)->users[0]->calendars[0]->busy;
    }

    /**
     * Posts a bulk free/busy request and returns the decoded response body.
     */
    private function requestFreeBusy($data) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $request->setBody(json_encode($data));
        $response = $this->request($request);

        $this->assertEquals(200, $response->status);

        return json_decode($response->getBodyAsString());
    }
}
