<?php

namespace ESN\CalDAV\Schedule;

use Sabre\DAV\Server;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use PHPUnit\Framework\TestCase;

class IMipCallbackPluginTest extends TestCase {
    private $plugin;
    private $server;
    private $schedulePlugin;

    function setUp(): void {
        $this->server = new Server();

        // Mock the schedule plugin
        $this->schedulePlugin = $this->createMock(Plugin::class);
        $this->server->addPlugin($this->schedulePlugin);

        $this->plugin = new IMipCallbackPlugin();
        $this->plugin->initialize($this->server);
    }

    function testGetPluginName() {
        $this->assertEquals('IMipCallbackPlugin', $this->plugin->getPluginName());
    }

    function testGetHTTPMethods() {
        $methods = $this->plugin->getHTTPMethods('/');
        $this->assertContains('IMIPCALLBACK', $methods);
    }

    function testImipCallbackWithValidPayload() {
        $payload = [
            'sender' => 'mailto:organizer@example.com',
            'recipient' => 'mailto:attendee@example.com',
            'method' => 'REQUEST',
            'uid' => 'test-event-uid',
            'component' => 'VEVENT',
            'message' => $this->getValidICalendar()
        ];

        $request = new Request('IMIPCALLBACK', '/');
        $request->setBody(json_encode($payload));

        $this->schedulePlugin->expects($this->once())
            ->method('deliverSync')
            ->with($this->callback(function($iTipMessage) use ($payload) {
                return $iTipMessage->sender === $payload['sender'] &&
                       $iTipMessage->recipient === $payload['recipient'] &&
                       $iTipMessage->method === $payload['method'] &&
                       $iTipMessage->uid === $payload['uid'];
            }));

        $this->server->httpRequest = $request;
        $this->server->httpResponse = new Response();

        $this->plugin->imipCallback($request);

        $this->assertEquals(204, $this->server->httpResponse->getStatus());
    }

    function testImipCallbackWithInvalidJSON() {
        $request = new Request('IMIPCALLBACK', '/');
        $request->setBody('invalid json {');

        $this->schedulePlugin->expects($this->never())
            ->method('deliverSync');

        $this->server->httpRequest = $request;
        $this->server->httpResponse = new Response();

        $this->plugin->imipCallback($request);

        $this->assertEquals(400, $this->server->httpResponse->getStatus());
        $body = json_decode($this->server->httpResponse->getBodyAsString(), true);
        $this->assertStringContainsString('Invalid JSON', $body['error']);
    }

    function testImipCallbackWithMissingSender() {
        $payload = [
            'recipient' => 'mailto:attendee@example.com',
            'method' => 'REQUEST',
            'message' => $this->getValidICalendar()
        ];

        $request = new Request('IMIPCALLBACK', '/');
        $request->setBody(json_encode($payload));

        $this->schedulePlugin->expects($this->never())
            ->method('deliverSync');

        $this->server->httpRequest = $request;
        $this->server->httpResponse = new Response();

        $this->plugin->imipCallback($request);

        $this->assertEquals(400, $this->server->httpResponse->getStatus());
        $body = json_decode($this->server->httpResponse->getBodyAsString(), true);
        $this->assertStringContainsString('Missing required fields', $body['error']);
    }

    function testImipCallbackWithMissingRecipient() {
        $payload = [
            'sender' => 'mailto:organizer@example.com',
            'method' => 'REQUEST',
            'message' => $this->getValidICalendar()
        ];

        $request = new Request('IMIPCALLBACK', '/');
        $request->setBody(json_encode($payload));

        $this->schedulePlugin->expects($this->never())
            ->method('deliverSync');

        $this->server->httpRequest = $request;
        $this->server->httpResponse = new Response();

        $this->plugin->imipCallback($request);

        $this->assertEquals(400, $this->server->httpResponse->getStatus());
        $body = json_decode($this->server->httpResponse->getBodyAsString(), true);
        $this->assertStringContainsString('Missing required fields', $body['error']);
    }

    function testImipCallbackWithMissingMessage() {
        $payload = [
            'sender' => 'mailto:organizer@example.com',
            'recipient' => 'mailto:attendee@example.com',
            'method' => 'REQUEST'
        ];

        $request = new Request('IMIPCALLBACK', '/');
        $request->setBody(json_encode($payload));

        $this->schedulePlugin->expects($this->never())
            ->method('deliverSync');

        $this->server->httpRequest = $request;
        $this->server->httpResponse = new Response();

        $this->plugin->imipCallback($request);

        $this->assertEquals(400, $this->server->httpResponse->getStatus());
        $body = json_decode($this->server->httpResponse->getBodyAsString(), true);
        $this->assertStringContainsString('Missing required fields', $body['error']);
    }

    function testImipCallbackWithInvalidICalendar() {
        $payload = [
            'sender' => 'mailto:organizer@example.com',
            'recipient' => 'mailto:attendee@example.com',
            'method' => 'REQUEST',
            'message' => 'invalid icalendar data'
        ];

        $request = new Request('IMIPCALLBACK', '/');
        $request->setBody(json_encode($payload));

        $this->schedulePlugin->expects($this->never())
            ->method('deliverSync');

        $this->server->httpRequest = $request;
        $this->server->httpResponse = new Response();

        $this->plugin->imipCallback($request);

        $this->assertEquals(500, $this->server->httpResponse->getStatus());
        $body = json_decode($this->server->httpResponse->getBodyAsString(), true);
        $this->assertStringContainsString('Failed to process IMIP message', $body['error']);
    }

    function testImipCallbackWhenSchedulePluginNotFound() {
        // Create a server without the schedule plugin
        $serverWithoutPlugin = new Server();
        $pluginWithoutSchedule = new IMipCallbackPlugin();
        $pluginWithoutSchedule->initialize($serverWithoutPlugin);

        $payload = [
            'sender' => 'mailto:organizer@example.com',
            'recipient' => 'mailto:attendee@example.com',
            'method' => 'REQUEST',
            'message' => $this->getValidICalendar()
        ];

        $request = new Request('IMIPCALLBACK', '/');
        $request->setBody(json_encode($payload));

        $serverWithoutPlugin->httpRequest = $request;
        $serverWithoutPlugin->httpResponse = new Response();

        $pluginWithoutSchedule->imipCallback($request);

        $this->assertEquals(500, $serverWithoutPlugin->httpResponse->getStatus());
        $body = json_decode($serverWithoutPlugin->httpResponse->getBodyAsString(), true);
        $this->assertStringContainsString('Schedule Plugin not found', $body['error']);
    }

    function testImipCallbackWithOptionalFields() {
        $payload = [
            'sender' => 'mailto:organizer@example.com',
            'recipient' => 'mailto:attendee@example.com',
            'method' => 'REQUEST',
            'uid' => 'custom-uid',
            'component' => 'VEVENT',
            'significantChange' => true,
            'hasChange' => true,
            'message' => $this->getValidICalendar()
        ];

        $request = new Request('IMIPCALLBACK', '/');
        $request->setBody(json_encode($payload));

        $this->schedulePlugin->expects($this->once())
            ->method('deliverSync')
            ->with($this->callback(function($iTipMessage) {
                return $iTipMessage->uid === 'custom-uid' &&
                       $iTipMessage->component === 'VEVENT' &&
                       $iTipMessage->significantChange === true &&
                       $iTipMessage->hasChange === true;
            }));

        $this->server->httpRequest = $request;
        $this->server->httpResponse = new Response();

        $this->plugin->imipCallback($request);

        $this->assertEquals(204, $this->server->httpResponse->getStatus());
    }

    private function getValidICalendar() {
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:test-event-uid',
            'DTSTAMP:20250101T120000Z',
            'DTSTART:20250115T140000Z',
            'DTEND:20250115T150000Z',
            'SUMMARY:Test Event',
            'ORGANIZER:mailto:organizer@example.com',
            'ATTENDEE:mailto:attendee@example.com',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);
        return $ical;
    }
}
