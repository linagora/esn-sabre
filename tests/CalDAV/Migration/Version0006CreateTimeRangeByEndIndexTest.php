<?php

namespace ESN\CalDAV\Migration;

use ESN\CalDAV\Backend\DAO\CalendarObjectDAO;
use ESN\Utils\Migration\MigrationRunner;
use MongoDB\Client;
use MongoDB\Database;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class Version0006CreateTimeRangeByEndIndexTest extends TestCase
{
    private Database $database;
    private TestHandler $logs;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->database = (new Client(ESN_MONGO_SABREURI))->selectDatabase(ESN_MONGO_SABREDB . '_time_range_index');
        $this->database->drop();
        $this->logs = new TestHandler();
        $this->logger = new Logger('MigrationTest', [$this->logs]);
    }

    protected function tearDown(): void
    {
        $this->database->drop();
    }

    private function indexKeys(): array
    {
        return array_map(fn ($index) => array_keys($index->getKey()),
            iterator_to_array($this->database->calendarobjects->listIndexes(), false));
    }

    public function testTheRunnerBuildsTheIndexOfAVersion5Database(): void
    {
        (new CalendarObjectDAO($this->database))->ensureIndexes();
        $this->database->calendarobjects->insertOne(['calendarid' => 'c', 'componenttype' => 'VEVENT', 'uri' => 'e.ics',
            'firstoccurence' => 1, 'lastoccurence' => 2]);
        $this->database->db_version->insertOne(['_id' => 'schema', 'version' => 5]);

        (new MigrationRunner($this->database, $this->logger))->run();

        $this->assertSame(6, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
        $this->assertContains(['calendarid', 'componenttype', 'lastoccurence', 'firstoccurence'], $this->indexKeys());
        $this->assertContains(['calendarid', 'componenttype', 'firstoccurence', 'lastoccurence'], $this->indexKeys());
        $this->assertTrue($this->logs->hasInfoThatContains('Calendar object time-range index build completed'));
    }
}
