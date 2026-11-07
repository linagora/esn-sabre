<?php

namespace ESN\CalDAV\Migration;

use ESN\CalDAV\Backend\Service\CalendarDataNormalizer;
use ESN\Utils\Migration\Migration;
use ESN\Utils\Migration\MigrationException;
use ESN\Utils\Utils;
use MongoDB\BSON\Regex;
use MongoDB\Database;
use Psr\Log\LoggerInterface;

/**
 * The stored classification used to be the CLASS of the first component only:
 * a private override of a public series was stored as public and served
 * unsanitised. Recompute it for the objects holding a private CLASS that are
 * not stored as private.
 */
class Version0002RecomputePrivateOverrideClassification implements Migration
{
    public const BATCH_SIZE = 500;

    public function getName(): string
    {
        return '0002_recompute_private_override_classification';
    }

    public function up(Database $database, LoggerInterface $logger): void
    {
        $started = microtime(true);
        $objects = $database->selectCollection('calendarobjects');
        $normalizer = new CalendarDataNormalizer();
        $context = ['migration' => $this->getName(), 'database' => $database->getDatabaseName()];
        $logger->info('Calendar object classification recompute starting', $context + ['batch_size' => self::BATCH_SIZE]);
        $cursor = $objects->find([
            'classification' => ['$nin' => ['PRIVATE', 'CONFIDENTIAL']],
            // Narrow the parse to candidates; the normalizer decides.
            'calendardata' => new Regex('^CLASS[^:\r\n]*:[ \t]*(PRIVATE|CONFIDENTIAL)', 'im'),
        ], [
            'projection' => ['_id' => 1, 'calendardata' => 1, 'classification' => 1],
            'sort' => ['_id' => 1],
            'batchSize' => self::BATCH_SIZE,
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ]);
        $operations = [];
        $read = $updated = $skipped = $batch = 0;

        foreach ($cursor as $object) {
            $read++;
            try {
                $classification = $normalizer->getDenormalizedData($object['calendardata'])['classification'];
            } catch (\Exception $error) {
                $skipped++;
                // Parser messages can contain event contents; log only the object ID.
                $logger->warning('Skipping calendar object with invalid data', $context + [
                    'calendar_object_id' => (string)$object['_id'], 'batch' => $batch + 1,
                ]);
                continue;
            }
            if (!Utils::isPrivateClassification($classification)) {
                continue;
            }

            // DAV remains writable: only update the payload we actually read.
            $operations[] = ['updateOne' => [
                ['_id' => $object['_id'], 'calendardata' => $object['calendardata']],
                ['$set' => ['classification' => $classification]],
            ]];
            if (count($operations) === self::BATCH_SIZE) {
                $updated += $this->writeBatch($objects, $operations, ++$batch);
                $operations = [];
            }
        }

        if ($operations) {
            $updated += $this->writeBatch($objects, $operations, ++$batch);
        }
        $logger->info('Calendar object classification recompute completed', $context + [
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
            throw new MigrationException('Unable to write calendar object classification batch ' . $batch, 0, $error);
        }
    }
}
