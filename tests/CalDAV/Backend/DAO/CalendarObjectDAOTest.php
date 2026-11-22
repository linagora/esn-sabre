<?php

namespace ESN\CalDAV\Backend\DAO;

/**
 * @medium
 */
class CalendarObjectDAOTest extends \PHPUnit\Framework\TestCase {

    private $db;

    protected function setUp(): void {
        $this->db = (new \MongoDB\Client(ESN_MONGO_SABREURI))->{ESN_MONGO_SABREDB};
        $this->db->drop();
    }

    /**
     * @return array[] the keys of the indexes of calendarobjects, in their field order
     */
    private function indexKeys() {
        return array_map(fn($index) => array_keys($index->getKey()),
            iterator_to_array($this->db->selectCollection('calendarobjects')->listIndexes(), false));
    }

    private function migrate() {
        (new \ESN\CalDAV\Migration\Version0006CreateTimeRangeByEndIndex())->up($this->db, new \Psr\Log\NullLogger());
    }

    function testEnsureIndexesLeavesTheTimeRangeByEndIndexToTheMigration() {
        (new CalendarObjectDAO($this->db))->ensureIndexes();

        $keys = $this->indexKeys();
        $this->assertContains(['calendarid', 'componenttype', 'firstoccurence', 'lastoccurence'], $keys);
        $this->assertNotContains(['calendarid', 'componenttype', 'lastoccurence', 'firstoccurence'], $keys);
        $this->assertContains(['calendarid', 'uri'], $keys);
        $this->assertContains(['uid'], $keys);
    }

    function testTheMigrationAddsTheTimeRangeByEndIndexAndKeepsTheOther() {
        (new CalendarObjectDAO($this->db))->ensureIndexes();

        $this->migrate();
        $this->migrate();
        (new CalendarObjectDAO($this->db))->ensureIndexes();

        $keys = $this->indexKeys();
        $this->assertContains(['calendarid', 'componenttype', 'lastoccurence', 'firstoccurence'], $keys);
        $this->assertContains(['calendarid', 'componenttype', 'firstoccurence', 'lastoccurence'], $keys);
    }

    /**
     * Five years of past events, then a week of current ones
     */
    private function insertFiveYearsAndAWeek($calendarId, $now, $day) {
        $documents = [];
        for ($i = 0; $i < 1800; $i++) {
            $start = $now - (1800 - $i) * $day;
            $documents[] = ['calendarid' => $calendarId, 'componenttype' => 'VEVENT', 'uri' => "past$i",
                'firstoccurence' => $start, 'lastoccurence' => $start + 3600];
        }
        for ($i = 0; $i < 7; $i++) {
            $start = $now + $i * $day;
            $documents[] = ['calendarid' => $calendarId, 'componenttype' => 'VEVENT', 'uri' => "week$i",
                'firstoccurence' => $start, 'lastoccurence' => $start + 3600];
        }
        $this->db->selectCollection('calendarobjects')->insertMany($documents);
    }

    private function explain(array $filter) {
        return $this->db->command(['explain' => ['find' => 'calendarobjects', 'filter' => $filter],
            'verbosity' => 'executionStats'], ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']])->toArray()[0];
    }

    function testATimeRangeQueryScansFromTheStartOfTheRange() {
        (new CalendarObjectDAO($this->db))->ensureIndexes();
        $this->migrate();
        $calendarId = new \MongoDB\BSON\ObjectId();
        $day = 86400;
        $now = 2000000000;
        $this->insertFiveYearsAndAWeek($calendarId, $now, $day);

        $explain = $this->explain([
            'calendarid' => $calendarId,
            'componenttype' => 'VEVENT',
            'lastoccurence' => ['$gt' => $now],
            'firstoccurence' => ['$lt' => $now + 7 * $day],
        ]);

        $this->assertSame(7, $explain['executionStats']['nReturned']);
        $this->assertLessThanOrEqual(10, $explain['executionStats']['totalKeysExamined']);
    }

    function testATimeRangeGivenAnEndOnlyScansUpToTheEndOfTheRange() {
        (new CalendarObjectDAO($this->db))->ensureIndexes();
        $this->migrate();
        $calendarId = new \MongoDB\BSON\ObjectId();
        $day = 86400;
        $now = 2000000000;
        // Three hundred events before the end of the range, then 1507 after it
        $this->insertFiveYearsAndAWeek($calendarId, $now - 1000 * $day, $day);

        $explain = $this->explain([
            'calendarid' => $calendarId,
            'componenttype' => 'VEVENT',
            'firstoccurence' => ['$lt' => $now - 2500 * $day],
        ]);

        $this->assertSame(300, $explain['executionStats']['nReturned']);
        $this->assertLessThanOrEqual(310, $explain['executionStats']['totalKeysExamined']);
    }
}
