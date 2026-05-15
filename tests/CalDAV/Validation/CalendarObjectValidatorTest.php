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

    function testValidRecurringEventPasses() {
        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'DTEND:20260515T100000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'SUMMARY:Valid recurrence',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));

        $this->assertTrue(true);
    }

    function testValidRecurringJCalPasses() {
        $vCalendar = $this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'DTEND:20260515T100000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'SUMMARY:Valid recurrence',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);

        $this->validator->validate(Reader::readJson(json_encode($vCalendar->jsonSerialize())));

        $this->assertTrue(true);
    }

    function testInvalidRRuleIsRejected() {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('invalid RRULE');

        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'DTEND:20260515T100000Z',
            'RRULE:FREQ=NOT_A_FREQUENCY',
            'SUMMARY:Invalid recurrence',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));
    }

    function testRRuleWithCountAndUntilIsRejected() {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('COUNT and UNTIL');

        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'DTEND:20260515T100000Z',
            'RRULE:FREQ=DAILY;COUNT=3;UNTIL=20260520T090000Z',
            'SUMMARY:Invalid recurrence',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));
    }

    function testMultipleRRuleIsRejected() {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('multiple RRULE');

        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'DTEND:20260515T100000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'RRULE:FREQ=WEEKLY;COUNT=2',
            'SUMMARY:Invalid recurrence',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));
    }

    function testInvalidRecurrenceIdIsRejected() {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('invalid RECURRENCE-ID');

        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'DTEND:20260515T100000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'SUMMARY:Valid master',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260516T090000Z',
            'DTEND:20260516T100000Z',
            'RECURRENCE-ID:2026-05-16T09:00:00Z',
            'SUMMARY:Invalid override',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));
    }

    function testDuplicateRecurrenceIdIsRejected() {
        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('duplicate RECURRENCE-ID');

        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'DTEND:20260515T100000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'SUMMARY:Valid master',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260516T090000Z',
            'DTEND:20260516T100000Z',
            'RECURRENCE-ID:20260516T090000Z',
            'SUMMARY:First override',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260516T110000Z',
            'DTEND:20260516T120000Z',
            'RECURRENCE-ID:20260516T090000Z',
            'SUMMARY:Duplicate override',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));
    }

    function testExceptionOnlyEventPassesForCompatibility() {
        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'DTEND:20260515T100000Z',
            'RECURRENCE-ID:20260515T090000Z',
            'SUMMARY:Exception only',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));

        $this->assertTrue(true);
    }

    private function readCalendar(array $lines) {
        return Reader::read(implode("\r\n", $lines) . "\r\n");
    }
}
