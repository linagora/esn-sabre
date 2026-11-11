<?php

namespace ESN\CalDAV;

use ESN\CalDAV\Schedule\AMQPSchedulePlugin;
use ESN\Publisher\CalDAV\EventRealTimePlugin;
use ESN\Publisher\Publisher;
use Sabre\HTTP\Request;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

class AttachmentUploadPublicationTest extends \ESN\DAV\ServerMock {
    private const URI = 'https://drive.example.test/public?sharecode=attachment#/';
    private const OBJECT_URI = 'attachment-publication.ics';
    private const BASE64 = 'YXR0YWNobWVudC1wdWJsaWNhdGlvbi10ZXN0';

    private $uploader;
    private array $published = [];

    function setUp(): void {
        parent::setUp();

        $this->uploader = $this->createMock(AttachmentUploadClient::class);
        $this->server->addPlugin(new BinaryAttachmentPlugin(BinaryAttachmentPlugin::MODE_OFFLOAD, null, $this->uploader));
        $publisher = $this->createStub(Publisher::class);
        $publisher->method('publish')->willReturnCallback(function ($topic, $message) {
            // Exercise real scheduling/realtime serialization, observing the AMQP boundary.
            $stored = $this->storedObject();
            $this->assertNotNull($stored, 'publication must follow successful persistence');
            $this->assertUriAttachment($stored['calendardata']);
            $this->published[] = ['topic' => $topic, 'data' => json_decode($message, true, 512, JSON_THROW_ON_ERROR)];
        });
        $this->server->addPlugin(new EventRealTimePlugin($publisher, $this->caldavBackend));
        $this->server->addPlugin(new AMQPSchedulePlugin($publisher, $this->principalBackend));
    }

    function testCreateReplacesBinaryBeforePersistenceAndBothAmqpPublishers() {
        $this->uploader->expects($this->once())->method('upload')->willReturnCallback(function () {
            $this->assertNull($this->storedObject(), 'the binary request must not be stored before upload');
            $this->assertSame([], $this->published);
            return self::URI;
        });

        $this->assertSame(201, $this->put($this->calendar())->status);

        $this->assertCleanPublication('calendar:event:alarm:created');
    }

    function testUpdateReplacesBinaryBeforePersistenceAndBothAmqpPublishers() {
        $previous = str_replace($this->binaryAttachment(), 'ATTACH:' . self::URI, $this->calendar());
        $this->caldavBackend->createCalendarObject($this->cal['id'], self::OBJECT_URI, $previous);
        $this->uploader->expects($this->once())->method('upload')->willReturnCallback(function () use ($previous) {
            $this->assertSame($previous, $this->storedObject()['calendardata']);
            $this->assertSame([], $this->published);
            return self::URI;
        });

        $this->assertSame(204, $this->put($this->calendar(2))->status);

        $this->assertCleanPublication('calendar:event:alarm:updated');
    }

    function testFailedUploadDoesNotPersistOrPublish() {
        $this->uploader->expects($this->once())->method('upload')->willThrowException(new AttachmentUploadException());

        $response = $this->put($this->calendar());
        $this->assertSame(503, $response->status);
        $this->assertSame('upload-failed', $response->getHeader('X-Twake-Attachment-Error'));

        $this->assertNull($this->storedObject());
        $this->assertSame([], $this->published);
    }

    function testLegacyBinaryIsStillIncludedInOldMessageOnUpdate() {
        // Historic events can predate upload mode: their previous revision is forwarded unchanged.
        $previous = $this->calendar();
        $this->caldavBackend->createCalendarObject($this->cal['id'], self::OBJECT_URI, $previous);
        $this->uploader->expects($this->once())->method('upload')->willReturn(self::URI);

        $this->assertSame(204, $this->put($this->calendar(2))->status);

        $deliveries = array_values(array_filter($this->published, fn ($message) => $message['topic'] === AMQPSchedulePlugin::TOPIC_LOCAL_DELIVERY));
        $this->assertNotEmpty($deliveries);
        foreach ($deliveries as $delivery) {
            $this->assertUriAttachment($delivery['data']['message']);
            $this->assertSame($previous, $delivery['data']['oldMessage']);
            $this->assertStringContainsString(self::BASE64, $delivery['data']['oldMessage']);
        }
        $alarms = array_values(array_filter($this->published, fn ($message) => $message['topic'] === 'calendar:event:alarm:updated'));
        $this->assertNotEmpty($alarms);
        $this->assertUriAttachment($alarms[0]['data']['rawEvent']);
        $this->assertStringContainsString(self::BASE64, json_encode($alarms[0]['data']['old_event']));
    }

    private function assertCleanPublication(string $alarmTopic) {
        $this->assertContains(AMQPSchedulePlugin::TOPIC_LOCAL_DELIVERY, array_column($this->published, 'topic'));
        $this->assertContains($alarmTopic, array_column($this->published, 'topic'));
        foreach ($this->published as $message) {
            $this->assertStringNotContainsString(self::BASE64, json_encode($message));
            if ($message['topic'] === AMQPSchedulePlugin::TOPIC_LOCAL_DELIVERY) {
                $this->assertUriAttachment($message['data']['message']);
            } elseif ($message['topic'] === $alarmTopic) {
                $this->assertUriAttachment($message['data']['rawEvent']);
            }
        }
    }

    private function assertUriAttachment(string $ics) {
        $this->assertStringContainsString(self::URI, str_replace("\r\n ", '', $ics));
        $this->assertStringNotContainsString('VALUE=BINARY', $ics);
        $this->assertStringNotContainsString('ENCODING=BASE64', $ics);
        $this->assertStringNotContainsString(self::BASE64, $ics);
    }

    private function storedObject() {
        return $this->sabredb->calendarobjects->findOne(['calendarid' => $this->cal['id'][0], 'uri' => self::OBJECT_URI]);
    }

    private function put(string $ics) {
        return $this->request(new Request('PUT', '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/' . self::OBJECT_URI,
            ['Content-Type' => 'text/calendar', 'Authorization' => 'Bearer user-token'], $ics));
    }

    private function binaryAttachment(): string {
        return 'ATTACH;VALUE=BINARY;ENCODING=BASE64;FILENAME=report.txt;FMTTYPE=text/plain:' . self::BASE64;
    }

    private function calendar(int $sequence = 1): string {
        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Attachment publication test//EN', 'BEGIN:VEVENT',
            'UID:attachment-publication', 'DTSTAMP:20261028T000000Z', 'DTSTART:20261029T090000Z', 'DTEND:20261029T100000Z',
            'SUMMARY:Attachment publication ' . $sequence, 'SEQUENCE:' . $sequence,
            'ORGANIZER:mailto:robertocarlos@realmadrid.com', 'ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:johndoe@example.org',
            $this->binaryAttachment(), 'END:VEVENT', 'END:VCALENDAR', ''
        ]);
    }
}
