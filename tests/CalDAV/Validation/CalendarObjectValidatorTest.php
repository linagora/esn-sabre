<?php

namespace ESN\CalDAV\Validation;

use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\Reader;

class CalendarObjectValidatorTest extends TestCase {
    private $validator;

    protected function setUp(): void {
        $this->validator = new CalendarObjectValidator();
    }

    function testMatchingDateTimeRecurrenceIdPasses() {
        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RECURRENCE-ID:20260515T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));

        $this->assertTrue(true);
    }

    function testMatchingDateRecurrenceIdPasses() {
        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;VALUE=DATE:20260515',
            'RECURRENCE-ID;VALUE=DATE:20260515',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));

        $this->assertTrue(true);
    }

    function testDateRecurrenceIdWithDateTimeDtStartIsRejected() {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('RFC 5545 section 3.8.4.4');
        $this->expectExceptionMessage('RECURRENCE-ID value type (DATE) must match DTSTART value type (DATE-TIME)');

        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RECURRENCE-ID;VALUE=DATE:20260515',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));
    }

    function testDateTimeRecurrenceIdWithDateDtStartIsRejected() {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('RFC 5545 section 3.8.4.4');
        $this->expectExceptionMessage('RECURRENCE-ID value type (DATE-TIME) must match DTSTART value type (DATE)');

        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;VALUE=DATE:20260515',
            'RECURRENCE-ID:20260515T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));
    }

    function testEventWithoutRecurrenceIdPasses() {
        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));

        $this->assertTrue(true);
    }

    private function readCalendar(array $lines) {
        return Reader::read(implode("\r\n", $lines) . "\r\n");
    }
}
