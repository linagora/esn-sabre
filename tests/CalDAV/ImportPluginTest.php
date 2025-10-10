<?php

namespace ESN\CalDAV;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Reader;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

/**
 * @medium
 */
class ImportPluginTest extends \ESN\DAV\ServerMock {

    protected $userTestId = '5aa1f6639751b711008b4567';
    protected $plugin;

    function setUp(): void {
        parent::setUp();

        $plugin = new ImportPlugin("import");
        $this->server->addPlugin($plugin);
    }

    function testReturnFalseOnImport() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'HTTP_ACCEPT'       => 'text/calendar',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json?import',
        ));

        $response = $this->request($request);

        $this->assertFalse($this->server->emit('schedule', [$this->newItipMessage('')]));
    }

    function testReturnTrueOnCreation() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'HTTP_ACCEPT'       => 'text/calendar',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json',
        ));

        $response = $this->request($request);

        $this->assertTrue($this->server->emit('schedule', [$this->newItipMessage('')]));
    }

    function testRemoveDuplicateObjects() {
        // when the node is deleted an 'afterUnbind' event will be emitted, so we're gonna play with that.
        $this->server->on('afterUnbind',    [$this, 'assertUnbindPath']);

        // create a request to import stuff using an already created node in the ServerMock class
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'HTTP_ACCEPT'       => 'text/calendar',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event1.ics?import',
        ));

        // make the request
        $response = $this->request($request);
    }

    // this shouldn't be anything else other than public visiblity or server->on method won't work
    function assertUnbindPath($path) {
        // this should be equal to the path of the node that was deleted
        $this->assertEquals($path, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event1.ics');
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
