<?php

namespace ESN\CalDAV\Schedule;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * The previous revision of a COUNTER is mailed to its recipient as oldEvent. It must come from a
 * copy the sender holds, never from the ITIP request path, which the tree resolves without ACL.
 *
 * @medium
 */
class ItipCounterOldMessageTest extends \ESN\DAV\ServerMock {

    const ME = 'robertocarlos@realmadrid.com';
    const EXTERNAL_ORGANIZER = 'x@attacker.example';
    const UID = 'counter-old-message';

    private $publisher;

    function setUp(): void {
        parent::setUp();

        $this->publisher = new CounterRecordingPublisher();
        $this->server->addPlugin(new AMQPSchedulePlugin($this->publisher, $this->principalBackend));
        $this->server->addPlugin(new ITipPlugin());

        // The sender's own copy names the external recipient as ORGANIZER.
        $this->caldavBackend->createCalendarObject($this->cal['id'], self::UID . '.ics', $this->calendar(self::UID, 'Mine'));
        // Another user's event, which the sender cannot read.
        $this->caldavBackend->createCalendarObject($this->calUser2['id'], 'secret.ics', $this->calendar('secret', 'Secret of johndoe'));
    }

    function testCounterOnAnotherUsersEventPathDoesNotPublishThatEvent() {
        $response = $this->counterOn('/calendars/54b64eadf6d7d8e41d263e0e/calendar2/secret.ics');

        $this->assertSame(204, $response->status);
        $delivery = $this->publishedCounter();
        $this->assertStringNotContainsString('Secret of johndoe', $delivery['oldMessage'] ?? '');
        $this->assertStringContainsString('SUMMARY:Mine', $delivery['oldMessage']);
    }

    function testCounterOnTheCalendarHomeCarriesTheSendersCopy() {
        $response = $this->counterOn('/calendars/54b64eadf6d7d8e41d263e0f');

        $this->assertSame(204, $response->status);
        $this->assertStringContainsString('SUMMARY:Mine', $this->publishedCounter()['oldMessage']);
    }

    private function publishedCounter(): array {
        $deliveries = array_values(array_filter(
            $this->publisher->published,
            fn($message) => $message[0] === AMQPSchedulePlugin::TOPIC_LOCAL_DELIVERY
        ));
        $this->assertCount(1, $deliveries);
        $payload = json_decode($deliveries[0][1], true);
        $this->assertSame('COUNTER', $payload['method']);

        return $payload;
    }

    private function counterOn(string $path) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'ITIP',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => $path,
        ]);
        $request->setBody(json_encode([
            'method' => 'COUNTER', 'uid' => self::UID, 'sequence' => 1,
            'sender' => self::ME, 'recipient' => self::EXTERNAL_ORGANIZER,
            'ical' => $this->calendar(self::UID, 'Proposal', 'COUNTER', '20351005T110000Z'),
        ]));

        return $this->request($request);
    }

    private function calendar(string $uid, string $summary, ?string $method = null, string $start = '20351005T090000Z') {
        return implode("\r\n", array_merge(
            ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Linagora//Twake//EN'],
            $method ? ['METHOD:' . $method] : [],
            [
                'BEGIN:VEVENT',
                'UID:' . $uid,
                'DTSTAMP:20260101T000000Z',
                'DTSTART:' . $start,
                'DURATION:PT1H',
                'SUMMARY:' . $summary,
                'ORGANIZER:mailto:' . self::EXTERNAL_ORGANIZER,
                'ATTENDEE;PARTSTAT=ACCEPTED:mailto:' . self::ME,
                'END:VEVENT',
                'END:VCALENDAR',
                '',
            ]
        ));
    }
}

class CounterRecordingPublisher {
    public $published = [];

    function publish($topic, $message) {
        $this->published[] = [$topic, $message];
    }
}
