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

    function testValidUtcRecurringEventPasses() {
        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RRULE:FREQ=DAILY;UNTIL=20260517T090000Z',
            'EXDATE:20260516T090000Z',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260516T110000Z',
            'RECURRENCE-ID:20260516T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));

        $this->assertTrue(true);
    }

    function testValidTimezoneRecurringEventPasses() {
        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;TZID=Europe/Paris:20260515T090000',
            'RRULE:FREQ=DAILY;UNTIL=20260517T070000Z',
            'EXDATE;TZID=Europe/Paris:20260516T090000',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;TZID=Europe/Paris:20260516T110000',
            'RECURRENCE-ID;TZID=Europe/Paris:20260516T090000',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));

        $this->assertTrue(true);
    }

    function testValidAllDayRecurringEventPasses() {
        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;VALUE=DATE:20260515',
            'RRULE:FREQ=DAILY;UNTIL=20260517',
            'EXDATE;VALUE=DATE:20260516',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;VALUE=DATE:20260516',
            'RECURRENCE-ID;VALUE=DATE:20260516',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));

        $this->assertTrue(true);
    }

    function testValidRDateMasterWithOverridePasses() {
        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RDATE:20260516T090000Z',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260516T110000Z',
            'RECURRENCE-ID:20260516T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));

        $this->assertTrue(true);
    }

    function testEventWithoutRecurrencePasses() {
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

    function testRRuleWithBothCountAndUntilIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RRULE:FREQ=DAILY;COUNT=3;UNTIL=20260517T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'COUNT and UNTIL MUST NOT both be present in the same RRULE'
        ]);
    }

    function testInvalidUntilFormatIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RRULE:FREQ=DAILY;UNTIL=2026-05-17',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'UNTIL value (2026-05-17) must be a valid DATE or DATE-TIME value'
        ]);
    }

    function testInvalidUntilDateIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RRULE:FREQ=DAILY;UNTIL=20260230T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'UNTIL value (20260230T090000Z) is not a valid DATE-TIME value'
        ]);
    }

    function testDateUntilWithDateTimeDtStartIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RRULE:FREQ=DAILY;UNTIL=20260517',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'UNTIL value type (DATE) must match DTSTART value type (DATE-TIME)'
        ]);
    }

    function testDateTimeUntilWithDateDtStartIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;VALUE=DATE:20260515',
            'RRULE:FREQ=DAILY;UNTIL=20260517T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'UNTIL value type (DATE-TIME) must match DTSTART value type (DATE)'
        ]);
    }

    function testFloatingUntilWithTimezoneDtStartIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;TZID=Europe/Paris:20260515T090000',
            'RRULE:FREQ=DAILY;UNTIL=20260517T090000',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'UNTIL date-time form (FLOATING) must be UTC when DTSTART date-time form is TZID:Europe/Paris'
        ]);
    }

    function testUtcUntilWithFloatingDtStartIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000',
            'RRULE:FREQ=DAILY;UNTIL=20260517T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'UNTIL date-time form (UTC) must match DTSTART date-time form (FLOATING)'
        ]);
    }

    function testDateRecurrenceIdWithDateTimeDtStartIsRejected() {
        $this->assertValidationFails($this->recurringCalendarWithOverride([
            'RECURRENCE-ID;VALUE=DATE:20260516',
            'DTSTART:20260516T110000Z'
        ]), [
            'RECURRENCE-ID value type (DATE) must match DTSTART value type (DATE-TIME)'
        ]);
    }

    function testDateTimeRecurrenceIdWithDateDtStartIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;VALUE=DATE:20260515',
            'RRULE:FREQ=DAILY;COUNT=3',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'RECURRENCE-ID:20260516T090000Z',
            'DTSTART;VALUE=DATE:20260516',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'RECURRENCE-ID value type (DATE-TIME) must match DTSTART value type (DATE)'
        ]);
    }

    function testInvalidRecurrenceIdFormatIsRejected() {
        $this->assertValidationFails($this->recurringCalendarWithOverride([
            'RECURRENCE-ID:2026-05-16T09:00:00Z',
            'DTSTART:20260516T110000Z'
        ]), [
            'RECURRENCE-ID value (2026-05-16T09:00:00Z) is not a valid DATE-TIME value'
        ]);
    }

    function testRecurrenceIdUtcFormWithTimezoneMasterIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;TZID=Europe/Paris:20260515T090000',
            'RRULE:FREQ=DAILY;COUNT=3',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'RECURRENCE-ID:20260516T090000Z',
            'DTSTART;TZID=Europe/Paris:20260516T110000',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'RECURRENCE-ID date-time form (UTC) must match DTSTART date-time form (TZID:Europe/Paris)'
        ]);
    }

    function testRecurrenceIdWithoutMasterIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'RECURRENCE-ID:20260516T090000Z',
            'DTSTART:20260516T110000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'RECURRENCE-ID override for UID event-1 has no matching recurring master VEVENT (same UID with RRULE or RDATE)'
        ]);
    }

    function testRecurrenceIdWithNonRecurringMasterIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'RECURRENCE-ID:20260516T090000Z',
            'DTSTART:20260516T110000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'has no matching recurring master VEVENT (same UID with RRULE or RDATE)'
        ]);
    }

    function testDuplicateRecurrenceIdIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'RECURRENCE-ID:20260516T090000Z',
            'DTSTART:20260516T110000Z',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'RECURRENCE-ID:20260516T090000Z',
            'DTSTART:20260516T130000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'Duplicate RECURRENCE-ID',
            '2026-05-16T09:00:00Z'
        ]);
    }

    function testRRuleWithoutDtStartIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'VEVENT with RRULE MUST have a DTSTART property'
        ]);
    }

    function testExDateWithoutDtStartIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'EXDATE:20260516T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'VEVENT with EXDATE MUST have a DTSTART property'
        ]);
    }

    function testDateExDateWithDateTimeDtStartIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'EXDATE;VALUE=DATE:20260516',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'EXDATE value type (DATE) must match DTSTART value type (DATE-TIME)'
        ]);
    }

    function testInvalidExDateFormatIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'EXDATE:2026-05-16T09:00:00Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'EXDATE value (2026-05-16T09:00:00Z) is not a valid DATE-TIME value'
        ]);
    }

    function testUtcExDateWithTimezoneDtStartIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;TZID=Europe/Paris:20260515T090000',
            'RRULE:FREQ=DAILY;COUNT=3',
            'EXDATE:20260516T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], [
            'EXDATE date-time form (UTC) must match DTSTART date-time form (TZID:Europe/Paris)'
        ]);
    }

    private function recurringCalendarWithOverride(array $overrideLines) {
        return array_merge([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
        ], $overrideLines, [
            'END:VEVENT',
            'END:VCALENDAR'
        ]);
    }

    function testDurationTypedDtEndIsRejectedByEventDatesValidation() {
        $this->assertEventDatesValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260101T000000Z',
            'DTSTART:20260101T100000Z',
            'DTEND;VALUE=DURATION:PT1H',
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['DTEND value type (DURATION) must be DATE or DATE-TIME']);
    }

    function testPeriodTypedDtEndIsRejectedByEventDatesValidation() {
        $this->assertEventDatesValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'DTEND;VALUE=PERIOD:20260515T090000Z/20260515T100000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['DTEND value type (PERIOD) must be DATE or DATE-TIME']);
    }

    function testPeriodTypedDtStartIsRejectedByEventDatesValidation() {
        $this->assertEventDatesValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260101T000000Z',
            'DTSTART;VALUE=PERIOD:20260101T100000Z/PT1H',
            'DTEND:20260101T110000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['DTSTART value type (PERIOD) must be DATE or DATE-TIME']);
    }

    function testNonDateDtStartWithoutDtEndIsRejected() {
        $this->assertEventDatesValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260514T000000Z',
            'DTSTART;VALUE=TEXT:tomorrow',
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['DTSTART value type (TEXT) must be DATE or DATE-TIME']);
    }

    function testNonDateDtEndWithoutDtStartIsRejected() {
        $this->assertEventDatesValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260514T000000Z',
            'DTEND;VALUE=PERIOD:20260515T090000Z/20260515T100000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['DTEND value type (PERIOD) must be DATE or DATE-TIME']);
    }

    function testPeriodTypedDtStartWithRRuleIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;VALUE=PERIOD:20260515T090000Z/PT1H',
            'RRULE:FREQ=DAILY;COUNT=3',
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['DTSTART value type (PERIOD) must be DATE or DATE-TIME']);
    }

    function testPeriodTypedExDateIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'EXDATE;VALUE=PERIOD:20260516T090000Z/PT1H',
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['EXDATE value type (PERIOD) must be DATE or DATE-TIME']);
    }

    function testPeriodTypedMasterDtStartWithOverrideIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART;VALUE=PERIOD:20260515T090000Z/PT1H',
            'RDATE:20260516T090000Z',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260516T110000Z',
            'RECURRENCE-ID:20260516T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['DTSTART value type (PERIOD) must be DATE or DATE-TIME']);
    }

    function testTextTypedRecurrenceIdIsRejected() {
        $this->assertValidationFails($this->recurringCalendarWithOverride([
            'DTSTART:20260516T110000Z',
            'RECURRENCE-ID;VALUE=TEXT:tomorrow',
        ]), ['RECURRENCE-ID value type (TEXT) must be DATE or DATE-TIME']);
    }

    function testVEventPropertyIsNotTreatedAsAMaster() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'VEVENT:not-a-component',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260516T110000Z',
            'RECURRENCE-ID:20260516T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['has no matching recurring master VEVENT']);
    }

    private function assertEventDatesValidationFails(array $lines, array $expectedMessages): void {
        try {
            $this->validator->validateEventDates($this->readCalendar($lines));
            $this->fail('Expected a BadRequest validation failure');
        } catch (BadRequest $e) {
            foreach ($expectedMessages as $message) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    private function assertValidationFails(array $lines, array $expectedMessages): void {
        try {
            $this->validator->validate($this->readCalendar($lines));
            $this->fail('Expected a BadRequest validation failure');
        } catch (BadRequest $e) {
            foreach ($expectedMessages as $message) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    private function readCalendar(array $lines) {
        return Reader::read(implode("\r\n", $lines) . "\r\n");
    }

    static function invalidUidProvider(): array {
        return [
            'empty' => ['UID:'],
            'blank' => ['UID: '],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidUidProvider')]
    function testRecurringEventWithoutUsableUidIsRejected(string $uidLine) {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            $uidLine,
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RRULE:FREQ=DAILY;COUNT=2',
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['VEVENT MUST have a non-empty UID property']);
    }

    function testEventWithoutUidIsRejected() {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['VEVENT MUST have a non-empty UID property']);
    }

    static function unsupportedFrequencyProvider(): array {
        return [
            'unknown' => ['RRULE:FREQ=FOO'],
            'multi-valued' => ['RRULE:FREQ=DAILY,WEEKLY'],
            'missing' => ['RRULE:COUNT=2'],
            'minutely' => ['RRULE:FREQ=MINUTELY;COUNT=2'],
            'secondly' => ['RRULE:FREQ=SECONDLY;COUNT=2'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedFrequencyProvider')]
    function testRRuleWithUnsupportedFrequencyIsRejected(string $rruleLine) {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            $rruleLine,
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['RRULE FREQ must be one of']);
    }

    function testRRuleWithLowerCaseFrequencyIsAccepted() {
        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'RRULE:FREQ=weekly;COUNT=2',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));

        $this->addToAssertionCount(1);
    }

    static function rruleRejectedByVObjectProvider(): array {
        return [
            'zero COUNT' => ['RRULE:FREQ=DAILY;COUNT=0', 'COUNT'],
            'non numeric COUNT' => ['RRULE:FREQ=DAILY;COUNT=abc', 'COUNT'],
            'zero INTERVAL' => ['RRULE:FREQ=DAILY;INTERVAL=0', 'INTERVAL'],
            'unknown part' => ['RRULE:FREQ=DAILY;X-FOO=1', 'X-FOO'],
            'BYMONTH out of range' => ['RRULE:FREQ=YEARLY;BYMONTH=13', 'BYMONTH'],
            'invalid BYDAY' => ['RRULE:FREQ=WEEKLY;BYDAY=XX', 'BYDAY'],
            'invalid WKST' => ['RRULE:FREQ=WEEKLY;WKST=XX', 'WKST'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rruleRejectedByVObjectProvider')]
    function testRRuleThatCannotBeExpandedIsRejected(string $rruleLine, string $expectedMessage) {
        $this->assertValidationFails([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'DTEND:20260515T100000Z',
            $rruleLine,
            'END:VEVENT',
            'END:VCALENDAR'
        ], ['Invalid RRULE', $expectedMessage]);
    }

    function testRRuleWithValidPartsIsAccepted() {
        $this->validator->validate($this->readCalendar([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'DTEND:20260515T100000Z',
            'RRULE:FREQ=MONTHLY;INTERVAL=2;COUNT=5;BYDAY=1MO,-1FR;BYMONTH=5,6;WKST=MO',
            'END:VEVENT',
            'END:VCALENDAR'
        ]));

        $this->addToAssertionCount(1);
    }
}
