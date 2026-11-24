<?php

namespace ESN\DAV\Migration;

use ESN\Utils\Migration\MigrationRunner;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Database;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Version0004BackfillChangeDatesTest extends TestCase
{
    private Database $database;
    private TestHandler $logs;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->database = (new Client(ESN_MONGO_SABREURI))->selectDatabase(ESN_MONGO_SABREDB . '_change_dates');
        $this->database->drop();
        $this->logs = new TestHandler();
        $this->logger = new Logger('MigrationTest', [$this->logs]);
    }

    protected function tearDown(): void
    {
        $this->database->drop();
    }

    public static function changeCollections(): array
    {
        return array_map(fn (string $collection) => [$collection], Version0004BackfillChangeDates::CHANGE_COLLECTIONS);
    }

    #[DataProvider('changeCollections')]
    public function testLegacyChangesAreDatedFromTheirId(string $collection): void
    {
        $legacyId = new ObjectId(sprintf('%08x', 1700000000) . '0000000000000001');
        $dated = new UTCDateTime(1600000000000);
        $this->database->selectCollection($collection)->insertMany([
            ['_id' => $legacyId, 'uri' => 'legacy', 'synctoken' => 1],
            ['_id' => new ObjectId(), 'uri' => 'dated', 'synctoken' => 2, 'date' => $dated],
        ]);

        (new MigrationRunner($this->database, $this->logger))->run();

        $date = fn (string $uri) => $this->database->selectCollection($collection)->findOne(['uri' => $uri])['date'];
        $this->assertEquals(new UTCDateTime(1700000000000), $date('legacy'));
        $this->assertEquals($dated, $date('dated'));
        $this->assertSame(5, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
        $this->assertTrue($this->logs->hasInfoThatContains('Change log date backfill completed'));
        $this->assertFalse($this->logs->hasErrorRecords());
    }
}
