<?php

namespace ESN\CalDAV\Schedule;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * Sabre converts property values to UTF-8 but leaves parameter values alone,
 * so a Latin-1 CN reaches the iTIP message as is. The delivery published for
 * it must still be JSON, never the `false` json_encode() returns on it.
 *
 * @medium
 */
class AMQPSchedulePluginPayloadTest extends \ESN\DAV\ServerMock {

    const EVENT_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/latin1.ics';

    private $publisher;

    function setUp(): void {
        parent::setUp();

        $this->publisher = new RecordingAmqpPublisher();
        $this->server->addPlugin(new AMQPSchedulePlugin($this->publisher, $this->principalBackend));
    }

    function testDeliveryWithNonUtf8ParameterIsPublishedAsJson() {
        $response = $this->request($this->putRequest($this->eventWithAttendeeCn("Ren\xE9")));
        $this->assertContains($response->status, [201, 204], 'PUT should succeed');

        $deliveries = $this->publishedOn(AMQPSchedulePlugin::TOPIC_LOCAL_DELIVERY);

        $this->assertNotEmpty($deliveries, 'the invitation should have been published');
        array_walk($deliveries, function ($payload) {
            $this->assertIsString($payload);
            $decoded = json_decode($payload, true);
            $this->assertIsArray($decoded, 'AMQP payload is not JSON: ' . json_last_error_msg());
            $this->assertSame('REQUEST', $decoded['method']);
            $this->assertStringContainsString('alice@example.org', $decoded['message']);
        });
    }

    private function publishedOn($topic) {
        return array_column(
            array_filter($this->publisher->published, fn($message) => $message[0] === $topic),
            1
        );
    }

    private function putRequest($body) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI'    => self::EVENT_PATH,
            'CONTENT_TYPE'   => 'text/calendar'
        ]);
        $request->setBody($body);

        return $request;
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
