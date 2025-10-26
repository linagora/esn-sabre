<?php

namespace ESN\CalDAV\Backend;

require_once ESN_TEST_BASE . '/CalDAV/Backend/RecurrenceExpansionTestBase.php';

/**
 * @medium
 */
class MongoRecurrenceExpansionTest extends RecurrenceExpansionTestBase {

    protected $esndb;
    protected $sabredb;
    protected $backend;

    function setUp(): void {
        $mc = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mc->{ESN_MONGO_SABREDB};
        $this->sabredb->drop();

        $this->backend = new Mongo($this->sabredb);
    }

    function tearDown(): void {
        if ($this->sabredb) {
            $this->sabredb->drop();
        }
    }

    protected function getBackend() {
        return $this->backend;
    }

    protected function generateId() {
        // Generate MongoDB ObjectIds directly like MongoTest does
        return [(string) new \MongoDB\BSON\ObjectId(), (string) new \MongoDB\BSON\ObjectId()];
    }

    /**
     * Test that maxRecurrences setting limits the number of expanded instances
     * This validates the MAX_RECURRENCES environment variable functionality
     */
    function testMaxRecurrencesLimit() {
        $backend = $this->getBackend();
        $id = $this->generateId();

        // Save original maxRecurrences value
        $originalMaxRecurrences = \Sabre\VObject\Settings::$maxRecurrences;

        try {
            // Set a low limit for testing (50 instances)
            \Sabre\VObject\Settings::$maxRecurrences = 50;

            // Create a daily event with 40 occurrences (within limit)
            $ical = join("\r\n", [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//Test//Test//EN',
                'BEGIN:VEVENT',
                'UID:within-limit-event',
                'DTSTART:20250101T100000Z',
                'DTEND:20250101T110000Z',
                'RRULE:FREQ=DAILY;COUNT=40',
                'SUMMARY:Event Within Limit',
                'END:VEVENT',
                'END:VCALENDAR',
                ''
            ]);

            $backend->createCalendarObject($id, "within-limit.ics", $ical);

            // Query should succeed for events within the limit
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
                            'end' => new \DateTime('2025-02-28T23:59:59Z'),
                        ],
                    ],
                ],
                'prop-filters' => [],
                'is-not-defined' => false,
                'time-range' => null,
            ];

            $result = $backend->calendarQuery($id, $filters);
            $this->assertEquals(['within-limit.ics'], $result, 'Should find event with COUNT=40 when maxRecurrences=50');

            // Now test that exceeding the limit throws an exception
            $exceptionThrown = false;
            try {
                // Create a daily event with 100 occurrences (exceeds limit)
                $ical2 = join("\r\n", [
                    'BEGIN:VCALENDAR',
                    'VERSION:2.0',
                    'PRODID:-//Test//Test//EN',
                    'BEGIN:VEVENT',
                    'UID:exceeds-limit-event',
                    'DTSTART:20250101T100000Z',
                    'DTEND:20250101T110000Z',
                    'RRULE:FREQ=DAILY;COUNT=100',
                    'SUMMARY:Event Exceeding Limit',
                    'END:VEVENT',
                    'END:VCALENDAR',
                    ''
                ]);

                $backend->createCalendarObject($id, "exceeds-limit.ics", $ical2);

                // Try to query - should fail during query processing
                $backend->calendarQuery($id, $filters);
            } catch (\Sabre\VObject\Recur\MaxInstancesExceededException $e) {
                $exceptionThrown = true;
                $this->assertStringContainsString('50', $e->getMessage(), 'Exception should mention the limit of 50');
            }

            $this->assertTrue($exceptionThrown, 'MaxInstancesExceededException should be thrown when maxRecurrences is exceeded');

        } finally {
            // Restore original maxRecurrences value
            \Sabre\VObject\Settings::$maxRecurrences = $originalMaxRecurrences;
        }
    }
}
