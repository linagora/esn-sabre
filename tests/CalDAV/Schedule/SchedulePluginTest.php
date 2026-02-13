<?php

namespace ESN\CalDAV\Schedule;

use Sabre\DAV\Server;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Reader;

#[\AllowDynamicProperties]
class SchedulePluginTest extends \PHPUnit\Framework\TestCase {
    private $plugin;
    private $publiclyCreatedCheckMethod;

    function setUp(): void {
        $server = new Server();

        $this->plugin = new Plugin();
        $this->plugin->initialize($server);

        $reflection = new \ReflectionClass($this->plugin);
        $this->publiclyCreatedCheckMethod = $reflection->getMethod('isPubliclyCreatedAndNotAcceptedByChairOrganizer');
        $this->publiclyCreatedCheckMethod->setAccessible(true);
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

    function testShouldDetectPubliclyCreatedWhenChairOrganizerNeedsAction() {
        $vcalendar = Reader::read($this->newCalendarWithOrganizerChairPartstat('NEEDS-ACTION', true));

        $shouldSkip = $this->publiclyCreatedCheckMethod->invoke($this->plugin, $vcalendar);

        $this->assertTrue($shouldSkip);
    }

    function testShouldNotSkipWhenChairOrganizerAcceptedEvenIfPubliclyCreated() {
        $vcalendar = Reader::read($this->newCalendarWithOrganizerChairPartstat('ACCEPTED', true));

        $shouldSkip = $this->publiclyCreatedCheckMethod->invoke($this->plugin, $vcalendar);

        $this->assertFalse($shouldSkip);
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

    private function newCalendarWithOrganizerChairPartstat($partstat, $publiclyCreated) {
        $publiclyCreatedValue = $publiclyCreated ? 'true' : 'false';

        return "BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:test-publicly-created
DTSTART:20260227T000000Z
DTEND:20260227T003000Z
SUMMARY:Test event
X-PUBLICLY-CREATED:$publiclyCreatedValue
ORGANIZER:mailto:alice@example.org
ATTENDEE;PARTSTAT=$partstat;ROLE=CHAIR:mailto:alice@example.org
ATTENDEE;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT:mailto:bob@example.org
END:VEVENT
END:VCALENDAR
";
    }
}
