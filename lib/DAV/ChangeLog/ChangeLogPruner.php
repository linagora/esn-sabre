<?php

namespace ESN\DAV\ChangeLog;

use MongoDB\BSON\UTCDateTime;
use MongoDB\Database;
use Psr\Log\LoggerInterface;

/**
 * Deletes the changes older than the retention, by sync token and never by date alone.
 *
 * Sync tokens are not ordered like dates: a writer reads the token, then logs its change, then increments, so a stalled
 * writer can date its change after a higher token. Pruning therefore removes, per collection, every change below
 * `1 + max(synctoken)` of its expired changes, and raises the `prunedsynctoken` watermark to that value BEFORE deleting.
 * A change logged late below the watermark is removed at the next run.
 */
class ChangeLogPruner
{
    private const SECONDS_PER_DAY = 86400;

    public function __construct(
        private readonly Database $database,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return int the number of changes deleted
     */
    public function prune(ChangeLog $changeLog, int $retentionInDays): int
    {
        $context = ['collection' => $changeLog->changesCollection, 'retention_days' => $retentionInDays];
        if ($retentionInDays <= 0) {
            $this->logger->info('Change log pruning disabled', $context);
            return 0;
        }

        $started = microtime(true);
        $deleted = 0;
        foreach ($this->findWatermarks($changeLog, $this->cutoff($retentionInDays)) as $watermark) {
            $deleted += $this->pruneOwner($changeLog, $watermark['_id'], $watermark['watermark']);
        }

        $this->logger->info('Change log pruning completed', $context + [
            'changes_deleted' => $deleted,
            'elapsed_seconds' => round(microtime(true) - $started, 3),
        ]);
        return $deleted;
    }

    private function cutoff(int $retentionInDays): UTCDateTime
    {
        return new UTCDateTime((time() - $retentionInDays * self::SECONDS_PER_DAY) * 1000);
    }

    /**
     * One scan of the `date` index: the new watermark of every collection having expired changes.
     */
    private function findWatermarks(ChangeLog $changeLog, UTCDateTime $cutoff): iterable
    {
        return $this->database->selectCollection($changeLog->changesCollection)->aggregate([
            ['$match' => ['date' => ['$lt' => $cutoff], 'synctoken' => ['$type' => 'number']]],
            ['$group' => ['_id' => '$' . $changeLog->ownerField, 'maxSyncToken' => ['$max' => '$synctoken']]],
            ['$project' => ['watermark' => ['$add' => ['$maxSyncToken', 1]]]],
        ], ['allowDiskUse' => true, 'typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']]);
    }

    private function pruneOwner(ChangeLog $changeLog, $ownerId, int $watermark): int
    {
        // Raised first: a sync reading the changes while they are deleted then sees the watermark and refuses its token
        $this->database->selectCollection($changeLog->ownersCollection)->updateOne(
            ['_id' => $ownerId],
            ['$max' => [ChangeLog::PRUNED_SYNC_TOKEN => $watermark]]
        );

        return $this->database->selectCollection($changeLog->changesCollection)->deleteMany([
            $changeLog->ownerField => $ownerId,
            'synctoken' => ['$lt' => $watermark],
        ])->getDeletedCount();
    }
}
