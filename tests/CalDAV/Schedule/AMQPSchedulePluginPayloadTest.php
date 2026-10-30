<?php

namespace ESN\CalDAV\Schedule;

/**
 * Sabre converts property values to UTF-8 but leaves parameter values alone,
 * so a Latin-1 CN reaches the iTIP message as is. The delivery published for
 * it must still be JSON, never the `false` json_encode() returns on it.
 */
class AMQPSchedulePluginPayloadTest extends \PHPUnit\Framework\TestCase {

    const EVENT_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/latin1.ics';

    private $publisher;
    private $plugin;

    function setUp(): void {
        $server = new \Sabre\DAV\Server();
        $server->httpRequest = new \Sabre\HTTP\Request('PUT', self::EVENT_PATH);

        $this->publisher = new RecordingAmqpPublisher();
        $this->plugin = new AMQPSchedulePlugin($this->publisher);
        $server->addPlugin($this->plugin);
    }

    function testDeliveryWithNonUtf8ParameterIsPublishedAsJson() {
        // Exercise outgoing delivery directly: MongoDB rejects non-UTF-8 calendar storage.
        $message = new \Sabre\VObject\ITip\Message();
        $message->method = 'REQUEST';
        $message->uid = 'latin1';
        $message->sender = 'mailto:robertocarlos@realmadrid.com';
        $message->recipient = 'mailto:alice@example.org';
        $message->hasChange = true;
        $message->message = \Sabre\VObject\Reader::read($this->eventWithAttendeeCn("Ren\xE9"));
        $this->assertFalse(json_encode($message->message->serialize()), 'the fixture must contain invalid UTF-8');

        $this->plugin->scheduleLocalDelivery($message);
        $this->assertEmpty($this->publishedOn(AMQPSchedulePlugin::TOPIC_LOCAL_DELIVERY));
        $this->plugin->afterWrite();

        $deliveries = $this->publishedOn(AMQPSchedulePlugin::TOPIC_LOCAL_DELIVERY);

        $this->assertNotEmpty($deliveries, 'the invitation should have been published');
        array_walk($deliveries, function ($payload) {
            $this->assertIsString($payload);
            $decoded = json_decode($payload, true);
            $this->assertIsArray($decoded, 'AMQP payload is not JSON: ' . json_last_error_msg());
            $this->assertSame('REQUEST', $decoded['method']);
            $this->assertStringContainsString('alice@example.org', $decoded['message']);
            $this->assertStringContainsString("CN=Ren\u{FFFD}", $decoded['message']);
        });
    }

    private function publishedOn($topic) {
        return array_column(
            array_filter($this->publisher->published, fn($message) => $message[0] === $topic),
            1
        );
    }

    private function eventWithAttendeeCn($cn) {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:latin1',
            'DTSTAMP:20260101T000000Z',
            'DTSTART:20351005T090000Z',
            'DTEND:20351005T100000Z',
            'SUMMARY:Daily',
            'ORGANIZER:mailto:robertocarlos@realmadrid.com',
            'ATTENDEE;CN=' . $cn . ';PARTSTAT=NEEDS-ACTION:mailto:alice@example.org',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);
    }
}

class RecordingAmqpPublisher {
    public $published = [];

    function publish($topic, $message) {
        $this->published[] = [$topic, $message];
    }
}
