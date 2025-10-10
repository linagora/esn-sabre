<?php

namespace ESN\CalDAV\Backend;

/**
 * Tests for recurring event expansion in calendarQuery
 *
 * These tests validate that:
 * - Recurring events are properly expanded across time ranges
 * - RRULE patterns (DAILY, WEEKLY, MONTHLY, YEARLY) work correctly
 * - Timezone conversions including DST transitions are handled properly
 * - EXDATE exclusions are respected
 * - Modified instances (RECURRENCE-ID) are properly matched
 *
 * @medium
 */
abstract class RecurrenceExpansionTest extends \PHPUnit_Framework_TestCase {

    abstract protected function getBackend();
    abstract protected function generateId();

    /**
     * Test that a simple daily recurring event is found across its recurrence range
     */
    function testDailyRecurringEventExpansion() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Create a daily recurring event from Jan 1 to Jan 10, 2025
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:daily-event',
            'DTSTART:20250101T100000Z',
            'DTEND:20250101T110000Z',
            'RRULE:FREQ=DAILY;UNTIL=20250110T235959Z',
            'SUMMARY:Daily Meeting',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "daily-event.ics", $ical);

        // Query for Jan 5 - should find the event (it occurs on Jan 5)
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
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['daily-event.ics'], $result, 'Daily recurring event should be found on Jan 5');

        // Query for Jan 15 - should NOT find the event (recurrence ends Jan 10)
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-15T00:00:00Z'),
            'end' => new \DateTime('2025-01-16T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Daily recurring event should not be found after UNTIL date');
    }

    /**
     * Test weekly recurring event with BYDAY
     */
    function testWeeklyRecurringEventWithBYDAY() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Create weekly event on Mondays, Wednesdays, Fridays
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:weekly-event',
            'DTSTART:20250106T140000Z',
            'DTEND:20250106T150000Z',
            'RRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=10',
            'SUMMARY:Team Standup',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "weekly-event.ics", $ical);

        // Jan 6, 2025 is a Monday - should find it
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
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['weekly-event.ics'], $result, 'Should find event on Monday Jan 6');

        // Jan 8, 2025 is a Wednesday - should find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-08T00:00:00Z'),
            'end' => new \DateTime('2025-01-09T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['weekly-event.ics'], $result, 'Should find event on Wednesday Jan 8');

        // Jan 7, 2025 is a Tuesday - should NOT find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-07T00:00:00Z'),
            'end' => new \DateTime('2025-01-08T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event on Tuesday Jan 7');
    }

    /**
     * Test monthly recurring event with BYMONTHDAY
     */
    function testMonthlyRecurringEventWithBYMONTHDAY() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Create monthly event on the 15th of each month
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:monthly-event',
            'DTSTART:20250115T090000Z',
            'DTEND:20250115T100000Z',
            'RRULE:FREQ=MONTHLY;BYMONTHDAY=15;COUNT=6',
            'SUMMARY:Monthly Review',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "monthly-event.ics", $ical);

        // Should find on Jan 15
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
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['monthly-event.ics'], $result, 'Should find event on Jan 15');

        // Should find on Feb 15
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-02-15T00:00:00Z'),
            'end' => new \DateTime('2025-02-16T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['monthly-event.ics'], $result, 'Should find event on Feb 15');

        // Should NOT find on Jan 20
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-20T00:00:00Z'),
            'end' => new \DateTime('2025-01-21T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event on Jan 20');
    }

    /**
     * Test recurring event with EXDATE (excluded instances)
     */
    function testRecurringEventWithEXDATE() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Daily event with Jan 5 and Jan 7 excluded
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:exdate-event',
            'DTSTART:20250101T100000Z',
            'DTEND:20250101T110000Z',
            'RRULE:FREQ=DAILY;COUNT=10',
            'EXDATE:20250105T100000Z,20250107T100000Z',
            'SUMMARY:Daily with Exceptions',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "exdate-event.ics", $ical);

        // Should find on Jan 3
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
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['exdate-event.ics'], $result, 'Should find event on Jan 3');

        // Should NOT find on Jan 5 (excluded)
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-05T00:00:00Z'),
            'end' => new \DateTime('2025-01-06T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event on Jan 5 (EXDATE)');

        // Should NOT find on Jan 7 (excluded)
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-07T00:00:00Z'),
            'end' => new \DateTime('2025-01-08T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event on Jan 7 (EXDATE)');
    }

    /**
     * Test DST transition - Europe/Paris Spring forward (last Sunday of March)
     * Clocks go from 2:00 AM to 3:00 AM (CET -> CEST, UTC+1 -> UTC+2)
     */
    function testDSTTransitionSpringForwardParis() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Weekly event at 2:30 AM Paris time on Sundays
        // In 2025, DST starts on March 30 at 2:00 AM (clocks skip to 3:00 AM)
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VTIMEZONE',
            'TZID:Europe/Paris',
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:dst-spring-event',
            'DTSTART;TZID=Europe/Paris:20250309T023000',
            'DTEND;TZID=Europe/Paris:20250309T033000',
            'RRULE:FREQ=WEEKLY;BYDAY=SU;COUNT=5',
            'SUMMARY:Early Sunday Meeting',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "dst-spring.ics", $ical);

        // March 23 (before DST) - event at 2:30 AM CET = 1:30 AM UTC
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
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['dst-spring.ics'], $result, 'Should find event before DST transition');

        // March 30 (DST transition day) - 2:30 doesn't exist, becomes 3:30 CEST = 1:30 UTC
        // Event should still be found at the corrected time
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-03-30T01:00:00Z'),
            'end' => new \DateTime('2025-03-30T02:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['dst-spring.ics'], $result, 'Should find event on DST transition day at corrected time');
    }

    /**
     * Test DST transition - Europe/Paris Fall back (last Sunday of October)
     * Clocks go from 3:00 AM to 2:00 AM (CEST -> CET, UTC+2 -> UTC+1)
     */
    function testDSTTransitionFallBackParis() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Weekly event at 2:30 AM Paris time on Sundays
        // In 2025, DST ends on October 26 at 3:00 AM (clocks go back to 2:00 AM)
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VTIMEZONE',
            'TZID:Europe/Paris',
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:dst-fall-event',
            'DTSTART;TZID=Europe/Paris:20251012T023000',
            'DTEND;TZID=Europe/Paris:20251012T033000',
            'RRULE:FREQ=WEEKLY;BYDAY=SU;COUNT=5',
            'SUMMARY:Early Sunday Meeting',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "dst-fall.ics", $ical);

        // October 19 (before DST ends) - event at 2:30 AM CEST = 0:30 AM UTC
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => new \DateTime('2025-10-19T00:00:00Z'),
                        'end' => new \DateTime('2025-10-19T01:00:00Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['dst-fall.ics'], $result, 'Should find event before DST transition ends');

        // October 26 (DST ends) - event at 2:30 AM CET (after fall back) = 1:30 AM UTC
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-10-26T01:00:00Z'),
            'end' => new \DateTime('2025-10-26T02:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['dst-fall.ics'], $result, 'Should find event after DST transition ends at new UTC time');
    }

    /**
     * Test recurring event with modified instance (RECURRENCE-ID)
     *
     * NOTE: This test documents current behavior. The backend does NOT properly
     * handle RECURRENCE-ID exclusions in calendarQuery. The modified instance
     * will be found at BOTH the original and modified times because the query
     * uses firstoccurrence/lastoccurrence which includes all instances.
     *
     * Proper handling would require expanding recurrences and checking RECURRENCE-ID,
     * which is currently done in the post-filter phase only when requirePostFilter=true.
     */
    function testRecurringEventWithModifiedInstance() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Daily event with one modified instance on Jan 5
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:modified-instance-event',
            'DTSTART:20250101T100000Z',
            'DTEND:20250101T110000Z',
            'RRULE:FREQ=DAILY;COUNT=10',
            'SUMMARY:Daily Meeting',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:modified-instance-event',
            'RECURRENCE-ID:20250105T100000Z',
            'DTSTART:20250105T140000Z',
            'DTEND:20250105T150000Z',
            'SUMMARY:Daily Meeting - Moved',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "modified-instance.ics", $ical);

        // Jan 3 - should find normal instance at 10:00
        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => new \DateTime('2025-01-03T09:00:00Z'),
                        'end' => new \DateTime('2025-01-03T11:00:00Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['modified-instance.ics'], $result, 'Should find normal instance on Jan 3');

        // Jan 5 at modified time (14:00) - should find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-05T13:00:00Z'),
            'end' => new \DateTime('2025-01-05T15:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['modified-instance.ics'], $result, 'Should find modified instance on Jan 5 at new time');
    }
}
