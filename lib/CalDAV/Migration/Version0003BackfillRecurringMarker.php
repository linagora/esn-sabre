<?php

namespace ESN\CalDAV\Migration;

use ESN\CalDAV\Backend\Service\CalendarDataNormalizer;
use ESN\Utils\Migration\Migration;
use ESN\Utils\Migration\MigrationException;
use MongoDB\Database;
use Psr\Log\LoggerInterface;

/**
 * Time-range calendar-queries skip the vobject post-filter for the events
 * stored with `recurring: false`. Events stored before that marker existed
 * are treated as recurring, which is correct but slow: compute it for them.
 */
class Version0003BackfillRecurringMarker implements Migration
{
    public const BATCH_SIZE = 500;

    public function getName(): string
    {
        return '0003_backfill_recurring_marker';
    }

    public function up(Database $database, LoggerInterface $logger): void
    {
        $started = microtime(true);
        $objects = $database->selectCollection('calendarobjects');
        $normalizer = new CalendarDataNormalizer();
        $context = ['migration' => $this->getName(), 'database' => $database->getDatabaseName()];
        $logger->info('Calendar object recurring marker backfill starting', $context + ['batch_size' => self::BATCH_SIZE]);
        $cursor = $objects->find([
            // Only time-range queries on events read the marker.
            'componenttype' => 'VEVENT',
            'recurring' => ['$exists' => false],
        ], [
            'projection' => ['_id' => 1, 'calendardata' => 1],
            'sort' => ['_id' => 1],
            'batchSize' => self::BATCH_SIZE,
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ]);
        $operations = [];
        $read = $updated = $skipped = $batch = 0;

        foreach ($cursor as $object) {
            $read++;
            try {
                $recurring = $normalizer->getDenormalizedData($object['calendardata'])['recurring'];
            } catch (\Exception $error) {
                $skipped++;
                // Parser messages can contain event contents; log only the object ID.
                $logger->warning('Skipping calendar object with invalid data', $context + [
                    'calendar_object_id' => (string)$object['_id'], 'batch' => $batch + 1,
                ]);
                continue;
            }

            // DAV remains writable: only update the payload we actually read, unless a write already set the marker.
            $operations[] = ['updateOne' => [
                ['_id' => $object['_id'], 'calendardata' => $object['calendardata'], 'recurring' => ['$exists' => false]],
                ['$set' => ['recurring' => $recurring]],
            ]];
            if (count($operations) === self::BATCH_SIZE) {
                $updated += $this->writeBatch($objects, $operations, ++$batch);
                $operations = [];
            }
        }

        if ($operations) {
            $updated += $this->writeBatch($objects, $operations, ++$batch);
        }
        $logger->info('Calendar object recurring marker backfill completed', $context + [
            'batches' => $batch, 'calendar_objects_read' => $read, 'calendar_objects_updated' => $updated,
            'calendar_objects_skipped' => $skipped,
            'elapsed_seconds' => round(microtime(true) - $started, 3),
        ]);
    }

    private function writeBatch(\MongoDB\Collection $objects, array $operations, int $batch): int
    {
        try {
            return $objects->bulkWrite($operations)->getModifiedCount();
        } catch (\Throwable $error) {
            throw new MigrationException('Unable to write calendar object recurring marker batch ' . $batch, 0, $error);
        }
    }
}
