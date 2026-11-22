<?php

namespace ESN\DAV\Migration;

use ESN\Utils\Migration\Migration;
use ESN\Utils\Migration\MigrationException;
use MongoDB\Database;
use Psr\Log\LoggerInterface;

/**
 * The `calendarchanges` and `addressbookchanges` logs are pruned on their `date` field (scripts/prune-change-logs.php).
 * Rows written before that field existed have none and would be kept forever: date them from the creation time of
 * their `_id`.
 */
class Version0004BackfillChangeDates implements Migration
{
    public const CHANGE_COLLECTIONS = ['calendarchanges', 'addressbookchanges'];

    public function getName(): string
    {
        return '0004_backfill_change_dates';
    }

    public function up(Database $database, LoggerInterface $logger): void
    {
        array_map(fn (string $collection) => $this->backfill($database, $collection, $logger), self::CHANGE_COLLECTIONS);
    }

    private function backfill(Database $database, string $collection, LoggerInterface $logger): void
    {
        $started = microtime(true);
        $context = ['migration' => $this->getName(), 'database' => $database->getDatabaseName(), 'collection' => $collection];
        $logger->info('Change log date backfill starting', $context);

        try {
            $updated = $database->selectCollection($collection)->updateMany(
                ['date' => ['$exists' => false], '_id' => ['$type' => 'objectId']],
                [['$set' => ['date' => ['$toDate' => '$_id']]]]
            )->getModifiedCount();
        } catch (\Throwable $error) {
            throw new MigrationException('Unable to backfill the change dates of ' . $collection, 0, $error);
        }

        $logger->info('Change log date backfill completed', $context + [
            'changes_updated' => $updated,
            'elapsed_seconds' => round(microtime(true) - $started, 3),
        ]);
    }
}
