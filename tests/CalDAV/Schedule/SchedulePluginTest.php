<?php

namespace ESN\CalDAV\Schedule;

use ESN\Publisher\AMQPPublisher;
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
        $amqpPublisher = $this->createMock(AMQPPublisher::class);

        $publishedMessage = null;
        $publishedTopic = null;
        $publishedProperties = null;

        $amqpPublisher->expects($this->once())
            ->method('publishWithProperties')
            ->will($this->returnCallback(function($topic, $message, $properties) use (&$publishedTopic, &$publishedMessage, &$publishedProperties) {
                $publishedTopic = $topic;
                $publishedMessage = $message;
                $publishedProperties = $properties;
            }));

        $mockAuthPlugin = $this->createMock(\Sabre\DAV\Auth\Plugin::class);
        $mockAuthPlugin->method('getCurrentPrincipal')
            ->willReturn('principals/users/testuser');

        $server = $this->createMock(\Sabre\DAV\Server::class);
        $server->method('getPlugin')
            ->with('auth')
            ->willReturn($mockAuthPlugin);

        $mockHref = new \Sabre\DAV\Xml\Property\Href(['mailto:test@example.com']);
        $server->method('getProperties')
            ->willReturn([
                '{urn:ietf:params:xml:ns:caldav}calendar-user-address-set' => $mockHref
            ]);

        $plugin = new Plugin(null, $amqpPublisher, true);
        $plugin->initialize($server);

        $message = $this->newItipMessage('1');
        $plugin->deliver($message);

        // Verify the message was published
        $this->assertEquals('calendar:itip:deliver', $publishedTopic);
        $this->assertNotNull($publishedMessage);

        // Decode and verify message content
        $decoded = json_decode($publishedMessage, true);
        $this->assertEquals('mailto:a@a.com', $decoded['sender']);
        $this->assertEquals('mailto:b@b.com', $decoded['recipient']);
        $this->assertEquals('REQUEST', $decoded['method']);
        $this->assertEquals('VEVENT', $decoded['component']);
        $this->assertNotNull($decoded['message']);

        // Verify AMQP properties contain connectedUser header
        $this->assertArrayHasKey('application_headers', $publishedProperties);
        $headers = $publishedProperties['application_headers']->getNativeData();
        $this->assertArrayHasKey('connectedUser', $headers);
        $this->assertEquals('test@example.com', $headers['connectedUser']);

        // Verify scheduleStatus is set to pending (1.0)
        $this->assertEquals('1.0', $message->scheduleStatus);
    }

    function testDeliverSyncDoesNotPublishToAMQP() {
        $amqpPublisher = $this->createMock(AMQPPublisher::class);

        $amqpPublisher->expects($this->never())
            ->method('publishWithProperties');

        $server = new Server();
        $plugin = new Plugin(null, $amqpPublisher, false);
        $plugin->initialize($server);

        $message = $this->newItipMessage('1');
        $plugin->deliver($message);
        $this->assertNotEquals('1.0', $message->scheduleStatus);
    }

    function testDeliverAsyncWithoutPublisherDoesNotPublish() {
        $server = new Server();
        $plugin = new Plugin(null, null, true);
        $plugin->initialize($server);

        $message = $this->newItipMessage('1');
        $plugin->deliver($message);
        $this->assertNotEquals('1.0', $message->scheduleStatus);
    }

    function testDeliverAsyncFallsBackToSyncWhenNoAddresses() {
        $amqpPublisher = $this->createMock(AMQPPublisher::class);

        $amqpPublisher->expects($this->never())
            ->method('publishWithProperties');

        $mockAuthPlugin = $this->createMock(\Sabre\DAV\Auth\Plugin::class);
        $mockAuthPlugin->method('getCurrentPrincipal')
            ->willReturn('principals/users/testuser');

        $server = $this->createMock(\Sabre\DAV\Server::class);
        $server->method('getPlugin')
            ->with('auth')
            ->willReturn($mockAuthPlugin);

        // Return empty addresses
        $server->method('getProperties')
            ->willReturn([]);

        $plugin = new Plugin(null, $amqpPublisher, true);
        $plugin->initialize($server);

        $message = $this->newItipMessage('1');
        $plugin->deliver($message);

        // Should fall back to sync delivery
        $this->assertNotEquals('1.0', $message->scheduleStatus);
    }

    function testDeliverSyncCalledDirectly() {
        $server = new Server();
        $plugin = new Plugin();
        $plugin->initialize($server);

        $message = $this->newItipMessage('1');

        // deliverSync should process synchronously without checking async flags
        $plugin->deliverSync($message);

        // Sequence should still be normalized
        $this->assertEquals('1', $message->message->VEVENT->SEQUENCE->getValue());
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
