<?php

namespace ESN\DAV\ChangeLog;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Database;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ChangeLogPrunerTest extends TestCase
{
    private const RETENTION_IN_DAYS = 90;

    private Database $database;
    private TestHandler $logs;
    private ChangeLogPruner $pruner;
    private ObjectId $ownerId;

    protected function setUp(): void
    {
        $this->database = (new Client(ESN_MONGO_SABREURI))->selectDatabase(ESN_MONGO_SABREDB . '_change_log_pruning');
        $this->database->drop();
        $this->logs = new TestHandler();
        $this->pruner = new ChangeLogPruner($this->database, new Logger('ChangeLogPrunerTest', [$this->logs]));
        $this->ownerId = new ObjectId();
    }

    protected function tearDown(): void
    {
        $this->database->drop();
    }

    public static function changeLogs(): array
    {
        return ['calendars' => [ChangeLog::calendars()], 'address books' => [ChangeLog::addressBooks()]];
    }

    #[DataProvider('changeLogs')]
    public function testExpiredChangesAreDeletedAfterRaisingTheWatermark(ChangeLog $changeLog): void
    {
        $this->givenOwner($changeLog, 4);
        $this->givenChanges($changeLog, [1 => 100, 2 => 91, 3 => 10]);

        $deleted = $this->pruner->prune($changeLog, self::RETENTION_IN_DAYS);

        $this->assertSame(2, $deleted);
        $this->assertSame([3], $this->keptSyncTokens($changeLog));
        $this->assertSame(3, $this->prunedSyncToken($changeLog));
        $this->assertTrue($this->logs->hasInfoThatContains('Change log pruning completed'));
    }

    #[DataProvider('changeLogs')]
    public function testChangeDatedAfterAHigherExpiredTokenIsDeletedToo(ChangeLog $changeLog): void
    {
        // A stalled writer logged token 1 after token 2 was logged: token order and date order differ
        $this->givenOwner($changeLog, 4);
        $this->givenChanges($changeLog, [1 => 10, 2 => 100, 3 => 10]);

        $this->pruner->prune($changeLog, self::RETENTION_IN_DAYS);

        $this->assertSame([3], $this->keptSyncTokens($changeLog));
        $this->assertSame(3, $this->prunedSyncToken($changeLog));
    }

    #[DataProvider('changeLogs')]
    public function testWatermarkIsNeverLowered(ChangeLog $changeLog): void
    {
        $this->givenOwner($changeLog, 12, 10);
        $this->givenChanges($changeLog, [2 => 100, 11 => 10]);

        $this->pruner->prune($changeLog, self::RETENTION_IN_DAYS);

        $this->assertSame([11], $this->keptSyncTokens($changeLog));
        $this->assertSame(10, $this->prunedSyncToken($changeLog));
    }

    #[DataProvider('changeLogs')]
    public function testOwnersWithoutExpiredChangesAreLeftUntouched(ChangeLog $changeLog): void
    {
        $this->givenOwner($changeLog, 3);
        $this->givenChanges($changeLog, [1 => 10, 2 => 10]);

        $this->assertSame(0, $this->pruner->prune($changeLog, self::RETENTION_IN_DAYS));

        $this->assertSame([1, 2], $this->keptSyncTokens($changeLog));
        $this->assertSame(0, $this->prunedSyncToken($changeLog));
    }

    #[DataProvider('changeLogs')]
    public function testUndatedChangesAreKept(ChangeLog $changeLog): void
    {
        $this->givenOwner($changeLog, 2);
        $this->database->selectCollection($changeLog->changesCollection)->insertOne([
            $changeLog->ownerField => $this->ownerId, 'uri' => 'legacy', 'synctoken' => 1, 'operation' => 1,
        ]);

        $this->assertSame(0, $this->pruner->prune($changeLog, self::RETENTION_IN_DAYS));
        $this->assertSame([1], $this->keptSyncTokens($changeLog));
    }

    #[DataProvider('changeLogs')]
    public function testNoRetentionDisablesPruning(ChangeLog $changeLog): void
    {
        $this->givenOwner($changeLog, 2);
        $this->givenChanges($changeLog, [1 => 1000]);

        $this->assertSame(0, $this->pruner->prune($changeLog, 0));

        $this->assertSame([1], $this->keptSyncTokens($changeLog));
        $this->assertTrue($this->logs->hasInfoThatContains('Change log pruning disabled'));
    }

    private function givenOwner(ChangeLog $changeLog, int $syncToken, ?int $prunedSyncToken = null): void
    {
        $this->database->selectCollection($changeLog->ownersCollection)->insertOne(array_filter([
            '_id' => $this->ownerId,
            'synctoken' => $syncToken,
            ChangeLog::PRUNED_SYNC_TOKEN => $prunedSyncToken,
        ], fn ($value) => $value !== null));
    }

    /**
     * @param array<int, int> $agesInDaysBySyncToken
     */
    private function givenChanges(ChangeLog $changeLog, array $agesInDaysBySyncToken): void
    {
        $this->database->selectCollection($changeLog->changesCollection)->insertMany(array_map(
            fn (int $syncToken, int $ageInDays) => [
                $changeLog->ownerField => $this->ownerId,
                'uri' => 'object' . $syncToken,
                'synctoken' => $syncToken,
                'operation' => 1,
                'date' => new UTCDateTime((time() - $ageInDays * 86400) * 1000),
            ],
            array_keys($agesInDaysBySyncToken),
            $agesInDaysBySyncToken
        ));
    }

    private function keptSyncTokens(ChangeLog $changeLog): array
    {
        $changes = $this->database->selectCollection($changeLog->changesCollection)->find(
            [$changeLog->ownerField => $this->ownerId],
            ['sort' => ['synctoken' => 1]]
        );
        return array_map(fn ($change) => $change['synctoken'], iterator_to_array($changes, false));
    }

    private function prunedSyncToken(ChangeLog $changeLog): int
    {
        $owner = $this->database->selectCollection($changeLog->ownersCollection)->findOne(['_id' => $this->ownerId]);
        return (int) ($owner[ChangeLog::PRUNED_SYNC_TOKEN] ?? 0);
    }
}
