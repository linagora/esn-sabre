<?php

namespace ESN\CalDAV\Schedule;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

/**
 * @medium
 */
#[\AllowDynamicProperties]
class ITipPluginTest extends \PHPUnit\Framework\TestCase
{

    use \Sabre\VObject\PHPUnitAssertions;

    protected $iTipRequestData = [
        'method' => 'REPLY',
        'uid' => 'recur',
        'sequence' => '1',
        'sender' => 'a@linagora.com',
        'recipient' => 'b@linagora.com',
        'ical' => 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20120313T142342Z
UID:event1
DTEND;TZID=Europe/Berlin:20120227T000000
TRANSP:OPAQUE
SUMMARY:Monday 0h
DTSTART;TZID=Europe/Berlin:20120227T000000
DTSTAMP:20120313T142416Z
SEQUENCE:4
ORGANIZER;CN=B:mailto:b@linagora.com
ATTENDEE:mailto:b@linagora.com
ATTENDEE:mailto:a@linagora.com
END:VEVENT
END:VCALENDAR'
    ];

    function setUp(): void
    {
        $this->iTipPlugin = new ITipPlugin();
        $this->calDavPlugin = new CalDavPluginMock();
        $this->server = new \Sabre\DAV\Server([]);
        $this->server->addPlugin($this->iTipPlugin);
        $this->server->addPlugin($this->calDavPlugin);
    }

    function makeRequest($body)
    {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ITIP',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));

        $request->setBody(json_encode($body));

        return $request;
    }

    function testITipShouldReturn400IfUIDIsMissing()
    {
        $this->iTipRequestData['uid'] = null;
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertEquals(400, $response->getStatus());
    }
    
    function testITipShouldReturn400IfRecipientIsMissing()
    {
        $this->iTipRequestData['recipient'] = null;
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertEquals(400, $response->getStatus());
    }

    function testITipShouldReturn400IfSenderIsMissing()
    {
        $this->iTipRequestData['sender'] = null;
        $request = $this->makeRequest($this->iTipRequestData);
       
        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertEquals(400, $response->getStatus());
    }

    function testITipShouldReturn400IfIcalIsMissing()
    {
        
        $this->iTipRequestData['ical'] = null;
        $request = $this->makeRequest($this->iTipRequestData);
        
        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertEquals(400, $response->getStatus());
    }

    function testITipShouldReturn400IfRecipientIsNotConcernedByEvent()
    {
        $this->iTipRequestData['recipient'] = 'c@linagora.com';
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertEquals(400, $response->getStatus());
    }
    
    function testITipShouldReturn204IfRecipientIsConcernedByEvent()
    {
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertEquals(204, $response->getStatus());
    }
    
    function testITipShouldEmitITipIfMethodeNotCounter()
    {
        $iTipCalled = false;
        $scheduleCalled = false;
        $this->server->on('iTip', function() use (&$iTipCalled) {
            $iTipCalled = true;
        });
        $this->server->on('schedule', function() use (&$scheduleCalled) {
            $scheduleCalled = true;
        });
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->itip($request);

        $response = $this->server->httpResponse;

        $this->assertTrue($iTipCalled);
        $this->assertFalse($scheduleCalled);
        $this->assertEquals(204, $response->getStatus());
    }
    
    function testITipShouldEmitScheduleIfMethodeCounter()
    {
        $iTipCalled = false;
        $scheduleCalled = false;
        $this->server->on('iTip', function() use (&$iTipCalled) {
            $itipCalled = true;
        });
        $this->server->on('schedule', function() use (&$scheduleCalled) {
            $scheduleCalled = true;
        });
        $this->iTipRequestData['method'] = 'COUNTER';
        $request = $this->makeRequest($this->iTipRequestData);

        $this->iTipPlugin->iTip($request);

        $response = $this->server->httpResponse;

        $this->assertTrue($scheduleCalled);
        $this->assertFalse($iTipCalled);
        $this->assertEquals(204, $response->getStatus());
    }

}

#[\AllowDynamicProperties]
class CalDavPluginMock extends ServerPlugin
{
    function getPluginName()
    {
        return 'caldav-schedule';
    }

    function initialize(\Sabre\DAV\Server $server)
    {
        $this->server = $server;
    }

    function scheduleLocalDelivery($message)
    {
        return;
    }

}