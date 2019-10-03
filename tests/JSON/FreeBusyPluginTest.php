<?php

namespace ESN\JSON;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class FreeBusyPluginTest extends \ESN\DAV\ServerMock {

    use \Sabre\VObject\PHPUnitAssertions;

    protected $freebusyBulkData = [
        'start' => '20120101T000000Z',
        'end' => '20150101T000000Z',
        'users' => ['54b64eadf6d7d8e41d263e0f']
    ];

    protected $freebusyBulkWithFilterData = [
        'start' => '20120101T000000Z',
        'end' => '20150101T000000Z',
        'users' => ['54b64eadf6d7d8e41d263e0f'],
        'uids' => ['event1']
    ];

    protected $freebusyBulkWithDurationEvent = [
        'start' => '20180401T000000Z',
        'end' => '20180401T003000Z',
        'users' => ['54b64eadf6d7d8e41d263e0f'],
        'uids' => ['event1']
    ];

    protected $freebusyBulkWithRecurringEvent = [
        'start' => '20180501T010000Z',
        'end' => '20180501T013000Z',
        'users' => ['54b64eadf6d7d8e41d263e0f'],
        'uids' => ['event1']
    ];

    protected $durationEvent =
        'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20180313T142342Z
UID:28CCB90C-0F2F-48FC-B1D9-33A2BA3D9595
TRANSP:OPAQUE
SUMMARY:Event with duration
DTSTART:20180401T000000Z
DURATION:PT3H
DTSTAMP:20180313T142416Z
SEQUENCE:1
END:VEVENT
END:VCALENDAR
';

    function setUp() {
        parent::setUp();

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals/users'];
        $this->server->addPlugin($aclPlugin);

        $plugin = new FreeBusyPlugin('caldav-freebusy');
        $this->server->addPlugin($plugin);

        $this->caldavBackend->createCalendarObject($this->cal['id'], 'event3.ics', $this->durationEvent);
    }

    function testFreeBusy() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $request->setBody(json_encode($this->freebusyBulkData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);
        $this->assertCount(1, $jsonResponse->users);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $jsonResponse->users[0]->calendars);
        $this->assertCount(2, $jsonResponse->users[0]->calendars[0]->busy);
    }

    function testFreeBusyWithFilteredEvent() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $request->setBody(json_encode($this->freebusyBulkWithFilterData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);
        $this->assertCount(1, $jsonResponse->users);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $jsonResponse->users[0]->calendars);
        $this->assertCount(1, $jsonResponse->users[0]->calendars[0]->busy);
    }

    function testFreeBusyWithDurationEvent() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $request->setBody(json_encode($this->freebusyBulkWithDurationEvent));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);
        $this->assertCount(1, $jsonResponse->users);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $jsonResponse->users[0]->calendars);
        $this->assertCount(1, $jsonResponse->users[0]->calendars[0]->busy);
    }

    function testFreeBusyWithRecurringEvent() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/freebusy',
        ));

        $request->setBody(json_encode($this->freebusyBulkWithRecurringEvent));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);
        $this->assertCount(1, $jsonResponse->users);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $jsonResponse->users[0]->calendars);
        $this->assertCount(1, $jsonResponse->users[0]->calendars[0]->busy);
    }
}
