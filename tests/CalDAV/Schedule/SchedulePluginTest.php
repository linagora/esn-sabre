<?php

namespace ESN\CalDAV\Schedule;

use Sabre\DAV\Server;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Reader;

class SchedulePluginTest extends \PHPUnit\Framework\TestCase {
    private $plugin;

    function setUp(): void {
        $server = new Server();

        $this->plugin = new Plugin();
        $this->plugin->initialize($server);
    }

    function testDeliverShouldSetSequenceTo0WhenNotPresent() {
        $message = $this->newItipMessage('');

        $this->plugin->deliver($message);

        $this->assertEquals('0', $message->message->VEVENT->SEQUENCE->getValue());
    }

    function testDeliverShouldNotSetSequenceWhen0() {
        $message = $this->newItipMessage('0');

        $this->plugin->deliver($message);

        $this->assertEquals('0', $message->message->VEVENT->SEQUENCE->getValue());
    }

    function testDeliverShouldNotSetSequenceWhenPresent() {
        $message = $this->newItipMessage('1');

        $this->plugin->deliver($message);

        $this->assertEquals('1', $message->message->VEVENT->SEQUENCE->getValue());
    }

    function testDeliverAsyncPublishesToAMQP() {
        $amqpPublisher = $this->getMockBuilder('ESN\Publisher\AMQPPublisher')
            ->disableOriginalConstructor()
            ->getMock();

        $publishedMessage = null;
        $publishedTopic = null;

        $amqpPublisher->expects($this->once())
            ->method('publish')
            ->will($this->returnCallback(function($topic, $message) use (&$publishedTopic, &$publishedMessage) {
                $publishedTopic = $topic;
                $publishedMessage = $message;
            }));

        $server = new Server();
        $plugin = new Plugin($amqpPublisher, true);
        $plugin->initialize($server);

        $message = $this->newItipMessage('1');
        $plugin->deliver($message);

        $this->assertEquals(Plugin::ITIP_DELIVERY_TOPIC, $publishedTopic);
        $this->assertNotNull($publishedMessage);

        $decoded = json_decode($publishedMessage, true);
        $this->assertEquals('mailto:a@a.com', $decoded['sender']);
        $this->assertEquals('mailto:b@b.com', $decoded['recipient']);
        $this->assertEquals('REQUEST', $decoded['method']);
        $this->assertEquals('UID', $decoded['uid']);
        $this->assertEquals('VEVENT', $decoded['component']);
        $this->assertNotNull($decoded['message']);

        // Verify scheduleStatus is set to pending (1.0)
        $this->assertEquals('1.0', $message->scheduleStatus);
    }

    function testDeliverSyncDoesNotPublishToAMQP() {
        $amqpPublisher = $this->getMockBuilder('ESN\Publisher\AMQPPublisher')
            ->disableOriginalConstructor()
            ->getMock();

        $amqpPublisher->expects($this->never())
            ->method('publish');

        $server = new Server();
        $plugin = new Plugin($amqpPublisher, false);
        $plugin->initialize($server);

        $message = $this->newItipMessage('1');
        $plugin->deliver($message);
    }

    function testDeliverAsyncWithoutPublisherDoesNotPublish() {
        $server = new Server();
        $plugin = new Plugin(null, true);
        $plugin->initialize($server);

        $message = $this->newItipMessage('1');
        // This should not throw an error even though async=true but publisher is null
        $plugin->deliver($message);
    }

    private function newItipMessage($sequence) {
        $message = new Message();
        $ical = "BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20120313T142342Z
UID:event1
DTEND;TZID=Europe/Berlin:20120227T000000
TRANSP:OPAQUE
SUMMARY:Monday 0h
DTSTART;TZID=Europe/Berlin:20120227T000000
DTSTAMP:20120313T142416Z
SEQUENCE:$sequence
END:VEVENT
END:VCALENDAR
";

        $message->component = 'VEVENT';
        $message->uid = 'UID';
        $message->sequence = $sequence;
        $message->method = 'REQUEST';
        $message->sender = 'mailto:a@a.com';
        $message->recipient = 'mailto:b@b.com';
        $message->message = Reader::read($ical);

        return $message;
    }
}
