<?php

namespace ESN\CalDAV;

use Sabre\CalDAV\CalendarQueryValidator;
use Sabre\VObject;

/**
 * Tests for CalendarQueryValidator presentation layer recurrence expansion
 *
 * These tests validate the presentation layer's recurrence expansion logic
 * as requested by chibenwa: "it do not include recurrent event expansion,
 * done by sabre in the presentation layer"
 *
 * Unlike RecurrenceExpansionTest which tests backend calendarQuery(), these
 * tests validate the actual VObject EventIterator expansion used when returning
 * events to clients.
 *
 * @medium
 */
class CalendarQueryValidatorRecurrenceTest extends \PHPUnit_Framework_TestCase {

    /**
     * Test daily recurring event expansion via CalendarQueryValidator
     * Validates that EventIterator properly expands DAILY recurrence
     */
    function testDailyRecurrenceExpansion() {
        $validator = new CalendarQueryValidator();

        // Daily event from Jan 1-10, 2025
        $ical = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VEVENT
UID:daily-event
DTSTART:20250101T100000Z
DTEND:20250101T110000Z
RRULE:FREQ=DAILY;COUNT=10
SUMMARY:Daily Meeting
END:VEVENT
END:VCALENDAR
ICS;

        $vcal = VObject\Reader::read($ical);

        // Filter for Jan 5 (should match - occurrence #5)
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => new \DateTime('2025-01-05T00:00:00Z'),
                        'end' => new \DateTime('2025-01-06T00:00:00Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
        ];

        $this->assertTrue($validator->validate($vcal, $filters),
            'Daily recurring event should match on Jan 5 via EventIterator expansion');

        // Filter for Jan 15 (should NOT match - after COUNT=10)
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-15T00:00:00Z'),
            'end' => new \DateTime('2025-01-16T00:00:00Z'),
        ];

        $this->assertFalse($validator->validate($vcal, $filters),
            'Daily recurring event should not match after COUNT=10');
    }

    /**
     * Test weekly recurring event with BYDAY expansion
     * BYDAY=MO,WE,FR means every Monday, Wednesday, Friday
     */
    function testWeeklyRecurrenceWithBYDAY() {
        $validator = new CalendarQueryValidator();

        // Weekly on Mon/Wed/Fri starting Jan 6, 2025 (Monday)
        $ical = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VEVENT
UID:weekly-event
DTSTART:20250106T140000Z
DTEND:20250106T150000Z
RRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=10
SUMMARY:Team Standup
END:VEVENT
END:VCALENDAR
ICS;

        $vcal = VObject\Reader::read($ical);

        // Jan 6 is Monday - should match
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => new \DateTime('2025-01-06T00:00:00Z'),
                        'end' => new \DateTime('2025-01-07T00:00:00Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
        ];

        $this->assertTrue($validator->validate($vcal, $filters),
            'Should match on Monday Jan 6');

        // Jan 8 is Wednesday - should match
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-08T00:00:00Z'),
            'end' => new \DateTime('2025-01-09T00:00:00Z'),
        ];

        $this->assertTrue($validator->validate($vcal, $filters),
            'Should match on Wednesday Jan 8');

        // Jan 7 is Tuesday - should NOT match
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-07T00:00:00Z'),
            'end' => new \DateTime('2025-01-08T00:00:00Z'),
        ];

        $this->assertFalse($validator->validate($vcal, $filters),
            'Should not match on Tuesday Jan 7');
    }

    /**
     * Test monthly recurring event with BYMONTHDAY
     */
    function testMonthlyRecurrenceWithBYMONTHDAY() {
        $validator = new CalendarQueryValidator();

        // Monthly on 15th
        $ical = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VEVENT
UID:monthly-event
DTSTART:20250115T090000Z
DTEND:20250115T100000Z
RRULE:FREQ=MONTHLY;BYMONTHDAY=15;COUNT=6
SUMMARY:Monthly Review
END:VEVENT
END:VCALENDAR
ICS;

        $vcal = VObject\Reader::read($ical);

        // Jan 15 - should match
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => new \DateTime('2025-01-15T00:00:00Z'),
                        'end' => new \DateTime('2025-01-16T00:00:00Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
        ];

        $this->assertTrue($validator->validate($vcal, $filters),
            'Should match on Jan 15');

        // Feb 15 - should match
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-02-15T00:00:00Z'),
            'end' => new \DateTime('2025-02-16T00:00:00Z'),
        ];

        $this->assertTrue($validator->validate($vcal, $filters),
            'Should match on Feb 15');

        // Jan 20 - should NOT match
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-20T00:00:00Z'),
            'end' => new \DateTime('2025-01-21T00:00:00Z'),
        ];

        $this->assertFalse($validator->validate($vcal, $filters),
            'Should not match on Jan 20');
    }

    /**
     * Test INTERVAL with DAILY recurrence
     * INTERVAL=3 means every 3rd day
     */
    function testDailyIntervalExpansion() {
        $validator = new CalendarQueryValidator();

        // Every 3 days starting Jan 1
        $ical = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VEVENT
UID:daily-interval-event
DTSTART:20250101T100000Z
DTEND:20250101T110000Z
RRULE:FREQ=DAILY;INTERVAL=3;COUNT=5
SUMMARY:Every 3 Days
END:VEVENT
END:VCALENDAR
ICS;

        $vcal = VObject\Reader::read($ical);

        // Jan 1 - should match (occurrence 1)
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => new \DateTime('2025-01-01T00:00:00Z'),
                        'end' => new \DateTime('2025-01-02T00:00:00Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
        ];

        $this->assertTrue($validator->validate($vcal, $filters),
            'Should match on Jan 1 (first occurrence)');

        // Jan 4 - should match (occurrence 2: Jan 1 + 3 days)
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-04T00:00:00Z'),
            'end' => new \DateTime('2025-01-05T00:00:00Z'),
        ];

        $this->assertTrue($validator->validate($vcal, $filters),
            'Should match on Jan 4 (INTERVAL=3)');

        // Jan 2 - should NOT match (not a valid occurrence)
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-02T00:00:00Z'),
            'end' => new \DateTime('2025-01-03T00:00:00Z'),
        ];

        $this->assertFalse($validator->validate($vcal, $filters),
            'Should not match on Jan 2 (between intervals)');
    }

    /**
     * Test monthly event on second Monday (BYDAY=2MO)
     * This tests positional BYDAY expansion
     */
    function testMonthlySecondMondayExpansion() {
        $validator = new CalendarQueryValidator();

        // Second Monday of each month
        $ical = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VEVENT
UID:second-monday-event
DTSTART:20250113T140000Z
DTEND:20250113T150000Z
RRULE:FREQ=MONTHLY;BYDAY=2MO;COUNT=4
SUMMARY:Second Monday Meeting
END:VEVENT
END:VCALENDAR
ICS;

        $vcal = VObject\Reader::read($ical);

        // Jan 13, 2025 is 2nd Monday - should match
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => new \DateTime('2025-01-13T00:00:00Z'),
                        'end' => new \DateTime('2025-01-14T00:00:00Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
        ];

        $this->assertTrue($validator->validate($vcal, $filters),
            'Should match on Jan 13 (2nd Monday of January)');

        // Jan 6 is 1st Monday - should NOT match
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-06T00:00:00Z'),
            'end' => new \DateTime('2025-01-07T00:00:00Z'),
        ];

        $this->assertFalse($validator->validate($vcal, $filters),
            'Should not match on Jan 6 (1st Monday, not 2nd)');
    }

    /**
     * Test yearly event on second Monday of April (BYMONTH=4;BYDAY=2MO)
     */
    function testYearlySecondMondayOfAprilExpansion() {
        $validator = new CalendarQueryValidator();

        // Second Monday of April each year
        $ical = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VEVENT
UID:april-monday-event
DTSTART:20250414T100000Z
DTEND:20250414T110000Z
RRULE:FREQ=YEARLY;BYMONTH=4;BYDAY=2MO;COUNT=3
SUMMARY:Annual April Meeting
END:VEVENT
END:VCALENDAR
ICS;

        $vcal = VObject\Reader::read($ical);

        // Apr 14, 2025 is 2nd Monday - should match
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => new \DateTime('2025-04-14T00:00:00Z'),
                        'end' => new \DateTime('2025-04-15T00:00:00Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
        ];

        $this->assertTrue($validator->validate($vcal, $filters),
            'Should match on Apr 14, 2025 (2nd Monday of April)');

        // May 12 is 2nd Monday of May - should NOT match (BYMONTH=4 only)
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-05-12T00:00:00Z'),
            'end' => new \DateTime('2025-05-13T00:00:00Z'),
        ];

        $this->assertFalse($validator->validate($vcal, $filters),
            'Should not match in May (BYMONTH=4 restricts to April only)');
    }

    /**
     * Test EXDATE exclusion in expansion
     * Validates that excluded dates are properly skipped by EventIterator
     */
    function testRecurrenceWithEXDATE() {
        $validator = new CalendarQueryValidator();

        // Daily with Jan 5 and Jan 7 excluded
        $ical = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VEVENT
UID:exdate-event
DTSTART:20250101T100000Z
DTEND:20250101T110000Z
RRULE:FREQ=DAILY;COUNT=10
EXDATE:20250105T100000Z,20250107T100000Z
SUMMARY:Daily with Exceptions
END:VEVENT
END:VCALENDAR
ICS;

        $vcal = VObject\Reader::read($ical);

        // Jan 3 - should match (not excluded)
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => new \DateTime('2025-01-03T00:00:00Z'),
                        'end' => new \DateTime('2025-01-04T00:00:00Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
        ];

        $this->assertTrue($validator->validate($vcal, $filters),
            'Should match on Jan 3 (not excluded)');

        // Jan 5 - should NOT match (excluded)
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-05T00:00:00Z'),
            'end' => new \DateTime('2025-01-06T00:00:00Z'),
        ];

        $this->assertFalse($validator->validate($vcal, $filters),
            'Should not match on Jan 5 (EXDATE)');
    }

    /**
     * Test DST timezone transition in expansion
     * Europe/Paris spring forward: 2:00 AM -> 3:00 AM on last Sunday of March
     */
    function testDSTTransitionExpansion() {
        $validator = new CalendarQueryValidator();

        // Weekly on Sundays at 2:30 AM Paris time
        // DST starts March 30, 2025
        $ical = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VTIMEZONE
TZID:Europe/Paris
BEGIN:DAYLIGHT
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
TZNAME:CEST
DTSTART:19700329T020000
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU
END:DAYLIGHT
BEGIN:STANDARD
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
TZNAME:CET
DTSTART:19701025T030000
RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU
END:STANDARD
END:VTIMEZONE
BEGIN:VEVENT
UID:dst-event
DTSTART;TZID=Europe/Paris:20250309T023000
DTEND;TZID=Europe/Paris:20250309T033000
RRULE:FREQ=WEEKLY;BYDAY=SU;COUNT=5
SUMMARY:Early Sunday Meeting
END:VEVENT
END:VCALENDAR
ICS;

        $vcal = VObject\Reader::read($ical);

        // March 23 (before DST) - 2:30 AM CET = 1:30 AM UTC
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => new \DateTime('2025-03-23T01:00:00Z'),
                        'end' => new \DateTime('2025-03-23T02:00:00Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
        ];

        $this->assertTrue($validator->validate($vcal, $filters),
            'Should match before DST transition');

        // March 30 (DST transition) - 2:30 doesn't exist, becomes 3:30 CEST = 1:30 UTC
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-03-30T01:00:00Z'),
            'end' => new \DateTime('2025-03-30T02:00:00Z'),
        ];

        $this->assertTrue($validator->validate($vcal, $filters),
            'Should match on DST transition day with adjusted time');
    }
}
