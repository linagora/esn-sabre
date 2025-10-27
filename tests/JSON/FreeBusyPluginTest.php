<?php

namespace ESN\JSON;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class FreeBusyPluginTest extends \ESN\DAV\ServerMock {

    use \Sabre\VObject\PHPUnitAssertions;

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

    function setUp() {
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

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $request->setBody(json_encode($this->freebusyBulkWithDurationEvent));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);
        $this->assertCount(1, $jsonResponse->users);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $jsonResponse->users[0]->calendars);
        $this->assertCount(1, $jsonResponse->users[0]->calendars[0]->busy);
    }

    function testFreeBusyWithRecurringEvent() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $request->setBody(json_encode($this->freebusyBulkWithRecurringEvent));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);
        $this->assertCount(1, $jsonResponse->users);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $jsonResponse->users[0]->calendars);
        $this->assertCount(1, $jsonResponse->users[0]->calendars[0]->busy);
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
     * Test for issue #172: Free/Busy should ignore DECLINED events
     */
    function testFreeBusyShouldIgnoreDeclinedEvent() {
        $declinedEvent = 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20180313T142342Z
UID:test-declined-event
TRANSP:OPAQUE
SUMMARY:Declined Meeting
DTSTART:20180401T110000Z
DTEND:20180401T120000Z
DTSTAMP:20180313T142416Z
SEQUENCE:1
ORGANIZER;CN=Boss:mailto:boss@example.com
ATTENDEE;PARTSTAT=DECLINED;RSVP=FALSE;CN=Roberto Carlos:mailto:robertocarlos@realmadrid.com
END:VEVENT
END:VCALENDAR
';

        $this->caldavBackend->createCalendarObject($this->cal['id'], 'declined.ics', $declinedEvent);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $freebusyData = [
            'start' => '20180401T100000Z',
            'end' => '20180401T130000Z',
            'users' => ['54b64eadf6d7d8e41d263e0f']
        ];

        $request->setBody(json_encode($freebusyData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);

        // The declined event should NOT appear in busy list
        $busyEvents = $jsonResponse->users[0]->calendars[0]->busy;
        foreach ($busyEvents as $event) {
            $this->assertNotEquals('test-declined-event', $event->uid,
                'Declined events should not appear in free/busy response');
        }
    }

    /**
     * Test for issue #172: Free/Busy should also ignore NEEDS-ACTION events
     */
    function testFreeBusyShouldIgnoreNeedsActionEvent() {
        $needsActionEvent = 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20180313T142342Z
UID:test-needs-action-event
TRANSP:OPAQUE
SUMMARY:Pending Meeting
DTSTART:20180401T140000Z
DTEND:20180401T150000Z
DTSTAMP:20180313T142416Z
SEQUENCE:1
ORGANIZER;CN=Boss:mailto:boss@example.com
ATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;CN=Roberto Carlos:mailto:robertocarlos@realmadrid.com
END:VEVENT
END:VCALENDAR
';

        $this->caldavBackend->createCalendarObject($this->cal['id'], 'needs-action.ics', $needsActionEvent);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $freebusyData = [
            'start' => '20180401T130000Z',
            'end' => '20180401T160000Z',
            'users' => ['54b64eadf6d7d8e41d263e0f']
        ];

        $request->setBody(json_encode($freebusyData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);

        // The needs-action event should NOT appear in busy list
        $busyEvents = $jsonResponse->users[0]->calendars[0]->busy;
        foreach ($busyEvents as $event) {
            $this->assertNotEquals('test-needs-action-event', $event->uid,
                'NEEDS-ACTION events should not appear in free/busy response');
        }
    }
}
