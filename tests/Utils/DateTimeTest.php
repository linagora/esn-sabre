<?php

namespace ESN\Utils;

use Sabre\VObject;

class DateTimeTest extends \PHPUnit\Framework\TestCase {

    // The computeVEventDuration function tests

    function testComputeVEventDurationWithDtEnd() {
        $iCal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:test@example.com',
            'ATTENDEE:mailto:test@example.com',
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);

        $event = VObject\Reader::read($iCal)->VEVENT;

        $duration = DateTime::computeVEventDuration($event);

        $this->assertEquals(1800, $duration);
    }

    function testComputeVEventDurationWithWithoutDtEnd() {
        $iCal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
            'DTSTART:20201028T170000Z',
            'DURATION:PT45M',
            'SUMMARY:Test',
            'ORGANIZER:mailto:test@example.com',
            'ATTENDEE:mailto:test@example.com',
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);

        $event = VObject\Reader::read($iCal)->VEVENT;

        $duration = DateTime::computeVEventDuration($event);

        $this->assertEquals(2700, $duration);
    }

}
