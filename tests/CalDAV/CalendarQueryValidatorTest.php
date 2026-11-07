<?php

namespace ESN\CalDAV;

use Sabre\VObject\Reader;

/**
 * VALARM time-range on a recurring event (linagora/sabre-vobject#170).
 */
class CalendarQueryValidatorTest extends \PHPUnit\Framework\TestCase {

    const MASTER = [
        'BEGIN:VEVENT',
        'UID:e1',
        'DTSTAMP:20260101T000000Z',
        'DTSTART:20260101T100000Z',
        'RRULE:FREQ=DAILY;COUNT=20',
        'BEGIN:VALARM',
        'TRIGGER:-PT30M',
        'ACTION:DISPLAY',
        'DESCRIPTION:x',
        'END:VALARM',
        'END:VEVENT',
    ];

    const OVERRIDE_WITHOUT_ALARM = [
        'BEGIN:VEVENT',
        'UID:e1',
        'DTSTAMP:20260101T000000Z',
        'RECURRENCE-ID:20260101T100000Z',
        'DTSTART:20260101T150000Z',
        'END:VEVENT',
    ];

    private function calendar(array ...$components) {
        return Reader::read(implode("\r\n", array_merge(
            ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//a//EN'],
            array_merge(...$components),
            ['END:VCALENDAR', '']
        )));
    }

    private function alarmFilter($start, $end) {
        return [
            'name' => 'VCALENDAR', 'is-not-defined' => false, 'time-range' => null, 'prop-filters' => [],
            'comp-filters' => [[
                'name' => 'VEVENT', 'is-not-defined' => false, 'time-range' => null, 'prop-filters' => [],
                'comp-filters' => [[
                    'name' => 'VALARM', 'is-not-defined' => false, 'prop-filters' => [], 'comp-filters' => [],
                    'time-range' => [
                        'start' => $start === null ? null : new \DateTime($start),
                        'end' => $end === null ? null : new \DateTime($end),
                    ],
                ]],
            ]],
        ];
    }

    private function validate($vcal, $start, $end) {
        return (new CalendarQueryValidator())->validate($vcal, $this->alarmFilter($start, $end));
    }

    function testValarmRecurringOverrideWithoutAlarm() {
        $vcal = $this->calendar(self::MASTER, self::OVERRIDE_WITHOUT_ALARM);

        $this->assertTrue($this->validate($vcal, '2026-01-01T00:00:00Z', '2026-01-05T18:00:00Z'));
    }

    function testValarmRecurringWithoutOverride() {
        $vcal = $this->calendar(self::MASTER);

        $this->assertTrue($this->validate($vcal, '2026-01-01T00:00:00Z', '2026-01-05T18:00:00Z'));
    }

    function testValarmRecurringOverrideWithoutAlarmInTheMiddle() {
        $override = self::OVERRIDE_WITHOUT_ALARM;
        $override[3] = 'RECURRENCE-ID:20260103T100000Z';
        $override[4] = 'DTSTART:20260103T150000Z';
        $vcal = $this->calendar(self::MASTER, $override);

        // Only the reminder of 4 January (09:30Z) is in the range
        $this->assertTrue($this->validate($vcal, '2026-01-03T00:00:00Z', '2026-01-04T10:00:00Z'));
        // The 3 January instance is the override, which has no reminder
        $this->assertFalse($this->validate($vcal, '2026-01-03T00:00:00Z', '2026-01-04T09:00:00Z'));
    }

    function testValarmRecurringRangeWithoutReminder() {
        $vcal = $this->calendar(self::MASTER, self::OVERRIDE_WITHOUT_ALARM);

        $this->assertFalse($this->validate($vcal, '2026-01-02T10:00:00Z', '2026-01-03T09:00:00Z'));
        $this->assertFalse($this->validate($vcal, '2026-03-01T00:00:00Z', '2026-04-01T00:00:00Z'));
    }

    function testValarmRecurringRangeEndingAtTheReminder() {
        $vcal = $this->calendar(self::MASTER, self::OVERRIDE_WITHOUT_ALARM);

        $this->assertFalse($this->validate($vcal, '2026-01-02T00:00:00Z', '2026-01-02T09:30:00Z'));
        $this->assertTrue($this->validate($vcal, '2026-01-02T09:30:00Z', '2026-01-02T09:31:00Z'));
    }

    function testValarmInfiniteSeriesBeforeTheRangeStops() {
        $master = self::MASTER;
        $master[4] = 'RRULE:FREQ=DAILY';
        $vcal = $this->calendar($master, self::OVERRIDE_WITHOUT_ALARM);

        // The series starts after the range: no instance is expanded past it
        $this->assertFalse($this->validate($vcal, '2025-01-01T00:00:00Z', '2025-02-01T00:00:00Z'));
        $this->assertTrue($this->validate($vcal, '2026-06-01T00:00:00Z', '2026-06-02T00:00:00Z'));
    }

    function testValarmWithALongLeadOnALaterInstance() {
        $master = self::MASTER;
        $master[6] = 'TRIGGER:-P3D';
        $vcal = $this->calendar($master, self::OVERRIDE_WITHOUT_ALARM);

        // The reminder of the 10 January instance fires on 7 January
        $this->assertTrue($this->validate($vcal, '2026-01-07T10:00:00Z', '2026-01-07T10:01:00Z'));
    }

    function testValarmAbsoluteTriggerOnTheMaster() {
        $master = self::MASTER;
        $master[6] = 'TRIGGER;VALUE=DATE-TIME:20251201T080000Z';
        $vcal = $this->calendar($master, self::OVERRIDE_WITHOUT_ALARM);

        $this->assertTrue($this->validate($vcal, '2025-12-01T00:00:00Z', '2025-12-02T00:00:00Z'));
        $this->assertFalse($this->validate($vcal, '2026-01-01T00:00:00Z', '2026-01-05T18:00:00Z'));
    }

    function testValarmOnTheOverrideOnly() {
        $master = array_merge(array_slice(self::MASTER, 0, 5), ['END:VEVENT']);
        $override = array_merge(array_slice(self::OVERRIDE_WITHOUT_ALARM, 0, 5), [
            'BEGIN:VALARM', 'TRIGGER:-PT1H', 'ACTION:DISPLAY', 'DESCRIPTION:x', 'END:VALARM',
            'END:VEVENT',
        ]);
        $override[3] = 'RECURRENCE-ID:20260110T100000Z';
        $override[4] = 'DTSTART:20260110T150000Z';
        $vcal = $this->calendar($override, $master);

        // The override is a child of a VEVENT without RRULE: matched by Sabre on its own
        $this->assertTrue($this->validate($vcal, '2026-01-10T14:00:00Z', '2026-01-10T14:01:00Z'));
        $this->assertFalse($this->validate($vcal, '2026-01-02T00:00:00Z', '2026-01-03T00:00:00Z'));
    }

    function testValarmWithoutRangeBoundsMatches() {
        $vcal = $this->calendar(self::MASTER, self::OVERRIDE_WITHOUT_ALARM);

        $this->assertTrue($this->validate($vcal, null, null));
    }

    function testStockValidatorStillFails() {
        // Documents the sabre/dav 4.7.1 behaviour this class works around: remove
        // the override once sabre/dav is fixed.
        $vcal = $this->calendar(self::MASTER, self::OVERRIDE_WITHOUT_ALARM);
        $filter = $this->alarmFilter('2026-01-01T00:00:00Z', '2026-01-05T18:00:00Z');

        $this->assertFalse((new \Sabre\CalDAV\CalendarQueryValidator())->validate($vcal, $filter));
    }
}
