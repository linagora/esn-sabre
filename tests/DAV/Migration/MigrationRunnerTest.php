<?php

namespace ESN\DAV\Migration;

use ESN\Utils\Migration\Migration;
use ESN\Utils\Migration\MigrationException;
use ESN\Utils\Migration\MigrationRunner;
use MongoDB\Client;
use MongoDB\Database;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MigrationRunnerTest extends TestCase
{
    private Database $database;
    private TestHandler $logs;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->database = (new Client(ESN_MONGO_SABREURI))->selectDatabase(ESN_MONGO_SABREDB . '_runner');
        $this->database->drop();
        $this->logs = new TestHandler();
        $this->logger = new Logger('MigrationTest', [$this->logs]);
    }

    protected function tearDown(): void
    {
        $this->database->drop();
    }

    public function testEmptyDatabaseIsMigratedAndRestartHasNothingToDo(): void
    {
        $runner = new MigrationRunner($this->database, $this->logger);
        $runner->run();
        $this->assertSame(1, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
        $this->assertTrue($this->logs->hasInfoThatContains('Database schema version saved'));
        $this->assertTrue($this->logs->hasInfoThatContains('Database migration runner completed'));

        // A completed migration must not run again, even if a newer contact would fail to parse.
        $this->database->cards->insertOne(['carddata' => 'invalid']);
        $runner->run();
        $this->assertTrue($this->logs->hasInfoThatContains('No pending database migrations'));
    }

    public function testMigrationsAreOrderedAndEachVersionIsCheckpointed(): void
    {
        $first = $this->createMock(Migration::class);
        $first->method('getName')->willReturn('first');
        $first->expects($this->once())->method('up')->willReturnCallback(function () {
            $this->assertNull($this->database->db_version->findOne(['_id' => 'schema']));
        });
        $second = $this->createMock(Migration::class);
        $second->method('getName')->willReturn('second');
        $second->expects($this->once())->method('up')->willReturnCallback(function () {
            $this->assertSame(1, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
        });

        $runner = new MigrationRunner($this->database, $this->logger, [2 => $second, 1 => $first]);
        $runner->run();
        $runner->run();
        $this->assertSame(2, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
    }

    public function testFailureKeepsLastSuccessfulVersionAndCanBeRetried(): void
    {
        $first = $this->createMock(Migration::class);
        $first->method('getName')->willReturn('first');
        $first->expects($this->once())->method('up');
        $attempts = 0;
        $second = $this->createMock(Migration::class);
        $second->method('getName')->willReturn('second');
        $second->expects($this->exactly(2))->method('up')->willReturnCallback(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new \RuntimeException('private payload or connection URI');
            }
        });
        $runner = new MigrationRunner($this->database, $this->logger, [1 => $first, 2 => $second]);

        try {
            $runner->run();
            $this->fail('Expected migration failure');
        } catch (\RuntimeException $error) {
            $this->assertSame(1, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
        }
        $this->assertTrue($this->logs->hasErrorThatContains('Database migration failed'));
        $errorRecord = array_values(array_filter($this->logs->getRecords(), fn ($record) => $record['level'] === Logger::ERROR))[0];
        $this->assertSame('second', $errorRecord['context']['migration']);
        $this->assertSame(1, $errorRecord['context']['current_version']);
        $this->assertStringNotContainsString('private payload', json_encode($this->logs->getRecords()));

        $runner->run();
        $this->assertSame(2, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
    }

    public static function invalidVersions(): array
    {
        return [['1'], [-1], [null], [1.5], [true], [2]];
    }

    #[DataProvider('invalidVersions')]
    public function testInvalidOrFutureVersionDoesNotRunMigrations($version): void
    {
        $this->database->db_version->insertOne(['_id' => 'schema', 'version' => $version]);
        $migration = $this->createMock(Migration::class);
        $migration->expects($this->never())->method('up');
        $this->expectException(MigrationException::class);
        (new MigrationRunner($this->database, $this->logger, [1 => $migration]))->run();
    }

    public function testVersionRecordWithoutVersionIsInvalid(): void
    {
        $this->database->db_version->insertOne(['_id' => 'schema']);
        $this->expectException(MigrationException::class);
        (new MigrationRunner($this->database, $this->logger))->run();
    }

    public function testMissingMigrationIsRejectedBeforeAnyMigrationRuns(): void
    {
        $migration = $this->createMock(Migration::class);
        $migration->expects($this->never())->method('up');
        try {
            (new MigrationRunner($this->database, $this->logger, [1 => $migration, 3 => $migration]))->run();
            $this->fail('Expected missing migration failure');
        } catch (MigrationException $error) {
            $this->assertStringContainsString('missing versions', $error->getMessage());
            $this->assertNull($this->database->db_version->findOne(['_id' => 'schema']));
            $this->assertTrue($this->logs->hasErrorThatContains('Database migration failed'));
        }
    }

    public function testFailedVersionWriteIsNotReportedAsSuccessAndCanBeRetried(): void
    {
        $this->database->createCollection('db_version', ['validator' => ['version' => ['$lte' => 0]]]);
        $runner = new MigrationRunner($this->database, $this->logger);
        try {
            $runner->run();
            $this->fail('Expected version write failure');
        } catch (\MongoDB\Driver\Exception\BulkWriteException $error) {
            $this->assertNull($this->database->db_version->findOne(['_id' => 'schema']));
            $this->assertFalse($this->logs->hasInfoThatContains('Database schema version saved'));
            $this->assertFalse($this->logs->hasInfoThatContains('Database migration runner completed'));
            $this->assertTrue($this->logs->hasErrorThatContains('Database migration failed'));
        }
        $this->database->command(['collMod' => 'db_version', 'validator' => new \stdClass()]);
        $runner->run();
        $this->assertSame(1, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
    }
}
