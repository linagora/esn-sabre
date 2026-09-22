<?php

namespace ESN\CalDAV\Schedule;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * Pins down how many times an iTIP delivery parses calendar data.
 *
 * Delivering a message involves the incoming payload, the recipient's stored
 * copy, the object written back, and the real-time publisher reading it again.
 * Only the first two are distinct documents, so everything else is handed over
 * through \ESN\Utils\VObjectCache rather than parsed afresh.
 *
 * A delivery that aborts early does no work and would trivially satisfy any
 * budget -- and ITipPlugin answers 204 either way -- so every case here asserts
 * the message was actually delivered before looking at the counters.
 *
 * The JSON plugin is deliberately absent: it registers under the plugin name
 * 'caldav' as well, and whichever of the two is added last wins the key. With
 * the wrong one in place the calendar home resolution returns nothing and every
 * delivery fails with '5.2;Could not find local inbox'.
 *
 * @medium
 */
class ItipParseBudgetTest extends \ESN\DAV\ServerMock {

    const ME = 'robertocarlos@realmadrid.com';
    const ORGANIZER = 'johndoe@example.org';

    private $scheduleStatus;

    function setUp(): void {
        parent::setUp();

        $publisher = new NullItipPublisher();
        $this->server->addPlugin(new AMQPSchedulePlugin($publisher, $this->principalBackend));
        $this->server->addPlugin(new ITipPlugin());
        $this->server->addPlugin(new \ESN\Publisher\CalDAV\EventRealTimePlugin($publisher, $this->caldavBackend));
        $this->server->on('iTip', function ($message) {
            $this->scheduleStatus = $message->scheduleStatus;
        }, 200);
    }

    function testRequestForANewEventShouldParseOnlyTheIncomingMessage() {
        $this->deliver($this->requestFor('budget-new', 'Invitation'));

        // The incoming payload, and nothing else: the object written to the
        // recipient's calendar is the one the broker just built.
        $this->assertSame(['parses' => 0, 'hits' => 2], $this->vObjectCache->getStats());
    }

    function testRequestForAnExistingEventShouldNotRereadThePreviousRevision() {
        $this->deliver($this->requestFor('budget-upd', 'Invitation'));
        $this->vObjectCache->resetStats();

        $this->deliver($this->requestFor('budget-upd', 'Moved', 2));

        // The previous revision is parsed once by the delivery and copied for
        // the comparison, never read back from its serialized form.
        $this->assertSame(['parses' => 0, 'hits' => 2], $this->vObjectCache->getStats());
    }

    function testReplyShouldNotRereadThePreviousRevision() {
        $uid = 'budget-reply';
        $this->caldavBackend->createCalendarObject($this->cal['id'], $uid . '.ics', $this->organizedByMe($uid));

        $this->deliver($this->replyFor($uid));

        $this->assertSame(['parses' => 0, 'hits' => 2], $this->vObjectCache->getStats());
    }

    function testDeliveredObjectShouldBeTheOneHandedOver() {
        $this->deliver($this->requestFor('budget-content', 'Invitation'));

        $stored = $this->sabredb->calendarobjects->findOne(['uid' => 'budget-content']);

        $this->assertNotNull($stored, 'the invitation should have been stored');
        $this->assertStringContainsString('SUMMARY:Invitation', $stored['calendardata']);
        // Handing the object over must not let the denormalized columns drift
        // away from the bytes they describe.
        $expected = (new \ESN\CalDAV\Backend\Service\CalendarDataNormalizer())
            ->getDenormalizedData($stored['calendardata']);
        $this->assertSame($expected['uid'], $stored['uid']);
        $this->assertSame($expected['firstOccurence'], $stored['firstoccurence']);
        $this->assertSame($expected['size'], $stored['size']);
    }

    /**
     * Runs the request and refuses to let a silently skipped delivery pass for
     * a cheap one.
     */
    private function deliver($request) {
        $this->scheduleStatus = null;
        $response = $this->request($request);

        $this->assertSame(204, $response->status);
        $this->assertSame('1.2;Message delivered locally', $this->scheduleStatus);
    }

    private function requestFor($uid, $summary, $sequence = 1) {
        return $this->itipRequest($uid, 'REQUEST', $sequence, $this->calendar(
            $this->event($uid, $summary, $sequence, self::ORGANIZER, self::ME, 'NEEDS-ACTION'),
            'REQUEST'
        ));
    }

    private function replyFor($uid) {
        // The attendee accepts an event this user organises.
        return $this->itipRequest($uid, 'REPLY', 1, $this->calendar(
            $this->event($uid, 'Mine', 1, self::ME, self::ORGANIZER, 'ACCEPTED'),
            'REPLY'
        ));
    }

    private function organizedByMe($uid) {
        return $this->calendar($this->event($uid, 'Mine', 1, self::ME, self::ORGANIZER, 'NEEDS-ACTION'));
    }

    private function event($uid, $summary, $sequence, $organizer, $attendee, $partstat) {
        return [
            'UID:' . $uid,
            'DTSTAMP:20120313T142416Z',
            'DTSTART:20260227T090000Z',
            'DTEND:20260227T100000Z',
            'SUMMARY:' . $summary,
            'SEQUENCE:' . $sequence,
            'ORGANIZER;CN=Organizer:mailto:' . $organizer,
            'ATTENDEE;PARTSTAT=' . $partstat . ';CN=Attendee:mailto:' . $attendee,
        ];
    }

    private function calendar(array $event, $method = null) {
        $head = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Linagora//Twake//EN'];
        if ($method) {
            $head[] = 'METHOD:' . $method;
        }

        return implode("\r\n", array_merge(
            $head, ['BEGIN:VEVENT'], $event, ['END:VEVENT', 'END:VCALENDAR', '']
        ));
    }

    private function itipRequest($uid, $method, $sequence, $ical) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'ITIP',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f'
        ]);
        $request->setBody(json_encode([
            'method' => $method, 'uid' => $uid, 'sequence' => $sequence,
            'sender' => self::ORGANIZER, 'recipient' => self::ME, 'ical' => $ical
        ]));

        return $request;
    }
}

/**
 * The scheduling plugin only needs somewhere to hand its messages.
 */
class NullItipPublisher {
    function publish($topic, $message) {}
}
