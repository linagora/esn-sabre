<?php

namespace ESN\CalDAV\Schedule;

use Sabre\DAV\Server;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

class IMipCallbackPluginTest extends \PHPUnit\Framework\TestCase {
    private $plugin;
    private $server;
    private $schedulePlugin;

    function setUp(): void {
        $this->server = new Server();

        // Mock the schedule plugin
        $this->schedulePlugin = $this->getMockBuilder('ESN\CalDAV\Schedule\Plugin')
            ->disableOriginalConstructor()
            ->getMock();

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
            'sender' => 'mailto:sender@example.com',
            'recipient' => 'mailto:recipient@example.com',
            'method' => 'REQUEST',
            'uid' => 'event-123',
            'component' => 'VEVENT',
            'significantChange' => true,
            'message' => 'BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Example Corp//CalDAV Client//EN
METHOD:REQUEST
BEGIN:VEVENT
UID:event-123
DTSTART:20250101T120000Z
DTEND:20250101T130000Z
SUMMARY:Test Event
ORGANIZER:mailto:sender@example.com
ATTENDEE:mailto:recipient@example.com
END:VEVENT
END:VCALENDAR'
        ];

        $request = new Request('IMIPCALLBACK', '/');
        $request->setBody(json_encode($payload));
        // Auth header not needed when SABRE_ADMIN_LOGIN is not configured

        $response = new Response();
        $this->server->httpRequest = $request;
        $this->server->httpResponse = $response;

        $this->schedulePlugin->expects($this->once())
            ->method('deliverSync');

        $this->plugin->imipCallback($request);

        $this->assertEquals(204, $response->getStatus());
    }

    function testImipCallbackWithMissingSender() {
        $payload = [
            'recipient' => 'mailto:recipient@example.com',
            'method' => 'REQUEST',
            'message' => 'BEGIN:VCALENDAR\nVERSION:2.0\nEND:VCALENDAR'
        ];

        $request = new Request('IMIPCALLBACK', '/');
        $request->setBody(json_encode($payload));

        $response = new Response();
        $this->server->httpRequest = $request;
        $this->server->httpResponse = $response;

        $this->schedulePlugin->expects($this->never())
            ->method('deliverSync');

        $this->plugin->imipCallback($request);

        $this->assertEquals(400, $response->getStatus());
    }

    function testImipCallbackWithInvalidMessage() {
        $payload = [
            'sender' => 'mailto:sender@example.com',
            'recipient' => 'mailto:recipient@example.com',
            'method' => 'REQUEST',
            'message' => 'INVALID ICALENDAR DATA'
        ];

        $request = new Request('IMIPCALLBACK', '/');
        $request->setBody(json_encode($payload));

        $response = new Response();
        $this->server->httpRequest = $request;
        $this->server->httpResponse = $response;

        $this->schedulePlugin->expects($this->never())
            ->method('deliverSync');

        $this->plugin->imipCallback($request);

        $this->assertEquals(500, $response->getStatus());

        $body = json_decode($response->getBodyAsString(), true);
        $this->assertArrayHasKey('error', $body);
    }
}
