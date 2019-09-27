<?php

namespace ESN\CalDAV\Schedule;

use Sabre\DAV\Server;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Reader;

class SchedulePluginTest extends \PHPUnit_Framework_TestCase {
    private $plugin;

    function setUp() {
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
