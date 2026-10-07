<?php

namespace ESN\CardDAV\Migration;

use ESN\Utils\Migration\Migration;
use ESN\Utils\Migration\MigrationException;
use MongoDB\Database;
use Psr\Log\LoggerInterface;
use Sabre\VObject\Component\VCard;
use Sabre\VObject\ParseException;
use Sabre\VObject\Reader;

class Version0001BackfillContactSortFields implements Migration
{
    public const BATCH_SIZE = 500;

    public function getName(): string
    {
        return '0001_backfill_contact_sort_fields';
    }

    public function up(Database $database, LoggerInterface $logger): void
    {
        $started = microtime(true);
        $cards = $database->selectCollection('cards');
        $context = ['migration' => $this->getName(), 'database' => $database->getDatabaseName()];
        $logger->info('Contact sort backfill starting', $context + ['batch_size' => self::BATCH_SIZE]);
        $cursor = $cards->find([
            '$or' => [['fn_sort' => ['$exists' => false]], ['email_sort' => ['$exists' => false]]],
        ], [
            'projection' => ['_id' => 1, 'carddata' => 1, 'fn_sort' => 1, 'email_sort' => 1],
            'sort' => ['_id' => 1],
            'batchSize' => self::BATCH_SIZE,
            'typeMap' => ['root' => 'array', 'document' => 'array'],
        ]);
        $operations = [];
        $read = $updated = $skipped = $batch = 0;

        foreach ($cursor as $card) {
            $read++;
            try {
                if (!isset($card['carddata']) || !is_string($card['carddata'])) {
                    throw new \UnexpectedValueException('Missing or non-string carddata');
                }
                $vcard = Reader::read($card['carddata']);
                if (!$vcard instanceof VCard) {
                    throw new \UnexpectedValueException('Expected a vCard');
                }
                // Match contact writes: trim FN and let Sabre select the preferred email.
                $values = [
                    'fn_sort' => trim((string)$vcard->FN),
                    'email_sort' => trim((string)$vcard->preferred('EMAIL')),
                ];
            } catch (ParseException | \UnexpectedValueException $error) {
                $skipped++;
                // Parser messages can contain vCard contents; log only a safe reason and the contact ID.
                $logger->warning('Skipping contact with invalid vCard', $context + [
                    'contact_id' => (string)$card['_id'], 'batch' => $batch + 1,
                    'reason' => $error instanceof ParseException ? 'Unable to parse vCard' : $error->getMessage(),
                ]);
                continue;
            }

            $missing = array_diff_key($values, $card);
            // Direct updates preserve payload, ETag, modification time and CardDAV sync state.
            $operations[] = ['updateOne' => [['_id' => $card['_id']], ['$set' => $missing]]];
            if (count($operations) === self::BATCH_SIZE) {
                $updated += $this->writeBatch($cards, $operations, ++$batch);
                $operations = [];
                $logger->info('Contact sort backfill batch completed', $context + [
                    'batch' => $batch, 'contacts_read' => $read, 'contacts_updated' => $updated,
                    'contacts_skipped' => $skipped,
                    'elapsed_seconds' => round(microtime(true) - $started, 3),
                ]);
            }
        }

        if ($operations) {
            $updated += $this->writeBatch($cards, $operations, ++$batch);
            $logger->info('Contact sort backfill batch completed', $context + [
                'batch' => $batch, 'contacts_read' => $read, 'contacts_updated' => $updated,
                'contacts_skipped' => $skipped,
                'elapsed_seconds' => round(microtime(true) - $started, 3),
            ]);
        }
        $logger->info('Contact sort backfill completed', $context + [
            'batches' => $batch, 'contacts_read' => $read, 'contacts_updated' => $updated,
            'contacts_skipped' => $skipped,
            'elapsed_seconds' => round(microtime(true) - $started, 3),
        ]);
    }

    private function writeBatch(\MongoDB\Collection $cards, array $operations, int $batch): int
    {
        try {
            return $cards->bulkWrite($operations)->getModifiedCount();
        } catch (\Throwable $error) {
            $message = 'Unable to write contact sort batch ' . $batch;
            if ($error instanceof \MongoDB\Driver\Exception\BulkWriteException) {
                $writeErrors = $error->getWriteResult()->getWriteErrors();
                if ($writeErrors) {
                    $operation = $operations[$writeErrors[0]->getIndex()];
                    $message .= ', contact ' . (string)$operation['updateOne'][0]['_id'];
                }
            }
            throw new MigrationException($message, 0, $error);
        }
    }
}
