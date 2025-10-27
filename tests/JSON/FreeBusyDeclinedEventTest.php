<?php

namespace ESN\JSON;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

/**
 * Test for issue #172: Free/Busy should ignore rejected events
 *
 * @medium
 */
class FreeBusyDeclinedEventTest extends \ESN\DAV\ServerMock {

    use \Sabre\VObject\PHPUnitAssertions;

    protected $declinedEvent =
        'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20180313T142342Z
UID:declined-event-123
TRANSP:OPAQUE
SUMMARY:Declined Event
DTSTART:20251028T100000Z
DTEND:20251028T110000Z
DTSTAMP:20251027T120000Z
SEQUENCE:1
ORGANIZER;CN=Boss:mailto:boss@example.com
ATTENDEE;PARTSTAT=DECLINED;RSVP=FALSE;CN=Bob:mailto:robertocarlos@realmadrid.com
END:VEVENT
END:VCALENDAR
';

    protected $acceptedEvent =
        'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20180313T142342Z
UID:accepted-event-456
TRANSP:OPAQUE
SUMMARY:Accepted Event
DTSTART:20251028T140000Z
DTEND:20251028T150000Z
DTSTAMP:20251027T120000Z
SEQUENCE:1
ORGANIZER;CN=Boss:mailto:boss@example.com
ATTENDEE;PARTSTAT=ACCEPTED;RSVP=FALSE;CN=Bob:mailto:robertocarlos@realmadrid.com
END:VEVENT
END:VCALENDAR
';

    protected $freebusyQuery = [
        'start' => '20251028T000000Z',
        'end' => '20251028T230000Z',
        'users' => ['54b64eadf6d7d8e41d263e0f']
    ];

    function setUp() {
        parent::setUp();

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals/users'];
        $this->server->addPlugin($aclPlugin);

        $plugin = new FreeBusyPlugin('caldav-freebusy');
        $this->server->addPlugin($plugin);

        // Add a declined event and an accepted event
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'declined.ics', $this->declinedEvent);
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'accepted.ics', $this->acceptedEvent);
    }

    /**
     * Test that declined events are NOT included in free/busy response
     * Issue #172: When Bob rejects an event, he should be free during that time
     */
    function testDeclinedEventIsNotInFreeBusy() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $request->setBody(json_encode($this->freebusyQuery));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals(200, $response->status);
        $this->assertCount(1, $jsonResponse->users);

        $busyEvents = $jsonResponse->users[0]->calendars[0]->busy;

        // Should only have the accepted event, NOT the declined one
        $this->assertCount(1, $busyEvents, 'Expected only 1 busy event (the accepted one), declined event should be filtered out');

        // Verify the busy event is the accepted one, not the declined one
        $this->assertEquals('accepted-event-456', $busyEvents[0]->uid,
            'The busy event should be the accepted event');

        // Verify the declined event is NOT in the busy list
        $declinedEventFound = false;
        foreach ($busyEvents as $event) {
            if ($event->uid === 'declined-event-123') {
                $declinedEventFound = true;
                break;
            }
        }
        $this->assertFalse($declinedEventFound,
            'Declined event should NOT be in the busy list');
    }

    /**
     * Test that needs-action events are also NOT included in free/busy
     */
    function testNeedsActionEventIsNotInFreeBusy() {
        $needsActionEvent = 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20180313T142342Z
UID:needs-action-789
TRANSP:OPAQUE
SUMMARY:Needs Action Event
DTSTART:20251028T160000Z
DTEND:20251028T170000Z
DTSTAMP:20251027T120000Z
SEQUENCE:1
ORGANIZER;CN=Boss:mailto:boss@example.com
ATTENDEE;PARTSTAT=NEEDS-ACTION;RSVP=TRUE;CN=Bob:mailto:robertocarlos@realmadrid.com
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

        $request->setBody(json_encode($this->freebusyQuery));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals(200, $response->status);

        $busyEvents = $jsonResponse->users[0]->calendars[0]->busy;

        // Should still only have 1 busy event (the accepted one)
        $this->assertCount(1, $busyEvents,
            'Expected only 1 busy event (the accepted one), needs-action and declined events should be filtered out');
    }
}
