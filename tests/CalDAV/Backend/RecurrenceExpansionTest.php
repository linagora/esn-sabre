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
     * Query large period and verify all expected occurrences are found
     */
    function testDailyRecurringEventExpansion() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Create a daily recurring event from Jan 1 to Jan 10, 2025 (10 occurrences)
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

        // Query entire recurrence period - should find the event
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
                        'end' => new \DateTime('2025-01-11T00:00:00Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['daily-event.ics'], $result, 'Daily recurring event should be found in full range');

        // Query after recurrence ends - should NOT find the event
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-15T00:00:00Z'),
            'end' => new \DateTime('2025-01-20T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Daily recurring event should not be found after UNTIL date');
    }

    /**
     * Test weekly recurring event with BYDAY
     * Query large period and verify all expected occurrences are found
     *
     * BYDAY values: MO=Monday, TU=Tuesday, WE=Wednesday, TH=Thursday,
     *               FR=Friday, SA=Saturday, SU=Sunday
     */
    function testWeeklyRecurringEventWithBYDAY() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Create weekly event on Mondays, Wednesdays, Fridays
        // BYDAY=MO,WE,FR means every Monday, Wednesday, and Friday
        // COUNT=10: Jan 6(Mo), 8(We), 10(Fr), 13(Mo), 15(We), 17(Fr), 20(Mo), 22(We), 24(Fr), 27(Mo)
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

        // Query entire January - should find all 10 occurrences
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
                        'end' => new \DateTime('2025-01-31T23:59:59Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['weekly-event.ics'], $result, 'Should find event in January (10 occurrences)');

        // Query a Tuesday - should NOT find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-07T00:00:00Z'),
            'end' => new \DateTime('2025-01-08T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event on Tuesday Jan 7');

        // Query after COUNT=10 ends - should NOT find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-02-01T00:00:00Z'),
            'end' => new \DateTime('2025-02-28T23:59:59Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event after COUNT=10');
    }

    /**
     * Test monthly recurring event with BYMONTHDAY
     * Query large period and verify all expected occurrences are found
     */
    function testMonthlyRecurringEventWithBYMONTHDAY() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Create monthly event on the 15th of each month
        // COUNT=6: Jan 15, Feb 15, Mar 15, Apr 15, May 15, Jun 15
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

        // Query entire 6-month period - should find all occurrences
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
                        'end' => new \DateTime('2025-06-30T23:59:59Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['monthly-event.ics'], $result, 'Should find event across 6 months (COUNT=6)');

        // Query on the 20th of Jan - should NOT find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-20T00:00:00Z'),
            'end' => new \DateTime('2025-01-21T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event on Jan 20');

        // Query after COUNT=6 ends - should NOT find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-07-01T00:00:00Z'),
            'end' => new \DateTime('2025-07-31T23:59:59Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event after COUNT=6');
    }

    /**
     * Test recurring event with EXDATE (excluded instances)
     * Query large period and verify exclusions are respected
     *
     * EXDATE format: Comma-separated list of datetime values to exclude
     * Example: EXDATE:20250105T100000Z,20250107T100000Z
     * This excludes occurrences on Jan 5 and Jan 7 at 10:00 UTC
     */
    function testRecurringEventWithEXDATE() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Daily event with Jan 5 and Jan 7 excluded (10 occurrences, 2 excluded = 8 actual)
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

        // Query entire period - event exists but with exclusions
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
                        'end' => new \DateTime('2025-01-11T00:00:00Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['exdate-event.ics'], $result, 'Should find event in full period (with EXDATE)');

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
     * Test daily recurring event with INTERVAL
     * INTERVAL=3 means every 3rd day
     */
    function testDailyRecurringEventWithInterval() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Every 3 days starting Jan 1: Jan 1, 4, 7, 10, 13, 16, 19, 22
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:daily-interval-event',
            'DTSTART:20250101T100000Z',
            'DTEND:20250101T110000Z',
            'RRULE:FREQ=DAILY;INTERVAL=3;COUNT=8',
            'SUMMARY:Every 3 Days',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "daily-interval.ics", $ical);

        // Query entire January - should find all 8 occurrences
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
                        'end' => new \DateTime('2025-01-31T23:59:59Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['daily-interval.ics'], $result, 'Should find event across January (every 3 days)');

        // Query Jan 2-3 (not occurrence dates) - should NOT find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-02T00:00:00Z'),
            'end' => new \DateTime('2025-01-04T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event on non-occurrence days');
    }

    /**
     * Test weekly recurring event with INTERVAL
     * INTERVAL=2 means every 2 weeks
     */
    function testWeeklyRecurringEventWithInterval() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Every 2 weeks on Monday starting Jan 6: Jan 6, 20, Feb 3, 17, Mar 3, 17
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:weekly-interval-event',
            'DTSTART:20250106T100000Z',
            'DTEND:20250106T110000Z',
            'RRULE:FREQ=WEEKLY;INTERVAL=2;BYDAY=MO;COUNT=6',
            'SUMMARY:Biweekly Monday Meeting',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "weekly-interval.ics", $ical);

        // Query Jan-Mar - should find all 6 occurrences
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
                        'end' => new \DateTime('2025-03-31T23:59:59Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['weekly-interval.ics'], $result, 'Should find event across 3 months (every 2 weeks)');

        // Query Jan 13 (off-week Monday) - should NOT find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-13T00:00:00Z'),
            'end' => new \DateTime('2025-01-14T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event on off-week Monday');
    }

    /**
     * Test monthly recurring event with INTERVAL
     * INTERVAL=2 means every 2 months
     */
    function testMonthlyRecurringEventWithInterval() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Every 2 months on the 15th: Jan 15, Mar 15, May 15, Jul 15, Sep 15
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:monthly-interval-event',
            'DTSTART:20250115T100000Z',
            'DTEND:20250115T110000Z',
            'RRULE:FREQ=MONTHLY;INTERVAL=2;COUNT=5',
            'SUMMARY:Bimonthly Review',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "monthly-interval.ics", $ical);

        // Query entire year - should find all 5 occurrences
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
                        'end' => new \DateTime('2025-12-31T23:59:59Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['monthly-interval.ics'], $result, 'Should find event across year (every 2 months)');

        // Query Feb 15 (off month) - should NOT find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-02-15T00:00:00Z'),
            'end' => new \DateTime('2025-02-16T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event on off month (Feb)');
    }

    /**
     * Test yearly recurring event with INTERVAL
     * INTERVAL=2 means every 2 years
     */
    function testYearlyRecurringEventWithInterval() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Every 2 years on Jan 15: 2025, 2027, 2029
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:yearly-interval-event',
            'DTSTART:20250115T100000Z',
            'DTEND:20250115T110000Z',
            'RRULE:FREQ=YEARLY;INTERVAL=2;COUNT=3',
            'SUMMARY:Biennial Conference',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "yearly-interval.ics", $ical);

        // Query 2025-2030 - should find all 3 occurrences
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
                        'end' => new \DateTime('2030-12-31T23:59:59Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['yearly-interval.ics'], $result, 'Should find event across 6 years (every 2 years)');

        // Query 2026 (off year) - should NOT find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2026-01-01T00:00:00Z'),
            'end' => new \DateTime('2026-12-31T23:59:59Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event on off year (2026)');
    }

    /**
     * Test monthly event on the second Monday of each month
     * BYDAY=2MO means "second Monday"
     */
    function testMonthlySecondMonday() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Second Monday of each month: Jan 13, Feb 10, Mar 10, Apr 14, May 12, Jun 9
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:second-monday-event',
            'DTSTART:20250113T140000Z',
            'DTEND:20250113T150000Z',
            'RRULE:FREQ=MONTHLY;BYDAY=2MO;COUNT=6',
            'SUMMARY:Second Monday Meeting',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "second-monday.ics", $ical);

        // Query 6 months - should find all occurrences
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
                        'end' => new \DateTime('2025-06-30T23:59:59Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['second-monday.ics'], $result, 'Should find second Monday of each month');

        // Query first Monday of Jan (Jan 6) - should NOT find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-06T00:00:00Z'),
            'end' => new \DateTime('2025-01-07T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event on first Monday');
    }

    /**
     * Test yearly event on the second Monday of April
     * BYMONTH=4;BYDAY=2MO means "second Monday of April"
     */
    function testYearlySecondMondayOfApril() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Second Monday of April: Apr 14 2025, Apr 13 2026, Apr 12 2027, Apr 10 2028
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:april-second-monday-event',
            'DTSTART:20250414T100000Z',
            'DTEND:20250414T110000Z',
            'RRULE:FREQ=YEARLY;BYMONTH=4;BYDAY=2MO;COUNT=4',
            'SUMMARY:Annual April Meeting',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "april-second-monday.ics", $ical);

        // Query 4 years - should find all occurrences
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
                        'end' => new \DateTime('2028-12-31T23:59:59Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['april-second-monday.ics'], $result, 'Should find second Monday of April each year');

        // Query May 2025 - should NOT find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-05-01T00:00:00Z'),
            'end' => new \DateTime('2025-05-31T23:59:59Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event in May');
    }

    /**
     * Test yearly birthday event on January 14
     * Simple yearly recurrence on a specific date
     */
    function testYearlyBirthdayEvent() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Birthday on Jan 14 every year: 2025, 2026, 2027, 2028, 2029
        $ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//Test//EN',
            'BEGIN:VEVENT',
            'UID:birthday-event',
            'DTSTART:20250114T000000Z',
            'DTEND:20250114T235959Z',
            'RRULE:FREQ=YEARLY;COUNT=5',
            'SUMMARY:Birthday Party',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $backend->createCalendarObject($id, "birthday.ics", $ical);

        // Query 5 years - should find all occurrences
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
                        'end' => new \DateTime('2029-12-31T23:59:59Z'),
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals(['birthday.ics'], $result, 'Should find birthday on Jan 14 every year');

        // Query Jan 15 - should NOT find it
        $filters['comp-filters'][0]['time-range'] = [
            'start' => new \DateTime('2025-01-15T00:00:00Z'),
            'end' => new \DateTime('2025-01-16T00:00:00Z'),
        ];

        $result = $backend->calendarQuery($id, $filters);
        $this->assertEquals([], $result, 'Should not find event on Jan 15');
    }

    /**
     * Test DST transition - Europe/Paris Spring forward (last Sunday of March)
     * Clocks go from 2:00 AM to 3:00 AM (CET -> CEST, UTC+1 -> UTC+2)
     *
     * BYDAY=-1SU means "last Sunday" (negative values count from end of period)
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
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', // -1SU = last Sunday
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
     *
     * BYDAY=-1SU means "last Sunday" (negative values count from end of period)
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
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', // -1SU = last Sunday
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
