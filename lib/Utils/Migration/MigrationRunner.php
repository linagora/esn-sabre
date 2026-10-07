<?php

namespace ESN\Utils\Migration;

use ESN\CardDAV\Migration\Version0001BackfillContactSortFields;
use MongoDB\Collection;
use MongoDB\Database;
use Psr\Log\LoggerInterface;

class MigrationRunner
{
    private array $migrations;

    public function __construct(private Database $database, private LoggerInterface $logger, ?array $migrations = null)
    {
        $this->migrations = $migrations ?? [1 => new Version0001BackfillContactSortFields()];
    }

    public function run(): void
    {
        $started = microtime(true);
        $context = ['database' => $this->database->getDatabaseName()];
        $this->logger->info('Database migration runner starting', $context);

        try {
            $target = $this->validateMigrationRegistry();
            $versions = $this->database->selectCollection('db_version');
            $current = $this->loadCurrentVersion($versions);
            $context += ['current_version' => $current, 'target_version' => $target];
            $this->logger->info('Database schema version loaded', $context);
            if ($current > $target) {
                throw new MigrationException('Database schema version is newer than this server supports');
            }
            $pending = array_filter($this->migrations, fn ($version) => $version > $current, ARRAY_FILTER_USE_KEY);
            $this->logger->info($pending ? 'Pending database migrations' : 'No pending database migrations', $context + [
                'migrations' => array_map(fn (Migration $migration) => $migration->getName(), $pending),
            ]);

            foreach ($pending as $version => $migration) {
                $context['migration'] = $migration->getName();
                $context['from_version'] = $current;
                $context['to_version'] = $version;
                $migrationStarted = microtime(true);
                $this->logger->info('Database migration starting', $context);
                $migration->up($this->database, $this->logger);
                $this->logger->info('Database migration completed', $context + [
                    'elapsed_seconds' => round(microtime(true) - $migrationStarted, 3),
                ]);

                // A slower startup runner must not lower a checkpoint already saved by another replica.
                $result = $versions->updateOne(['_id' => 'schema'], ['$max' => ['version' => $version]], ['upsert' => true]);
                if (!$result->isAcknowledged()) {
                    throw new MigrationException('Database schema version write was not acknowledged');
                }
                $current = $version;
                $context['current_version'] = $current;
                $this->logger->info('Database schema version saved', $context);
            }

            $this->logger->info('Database migration runner completed', $context + [
                'elapsed_seconds' => round(microtime(true) - $started, 3),
            ]);
        } catch (\Throwable $error) {
            // Driver/parser messages can contain connection URIs or vCard contents. Do not log them verbatim.
            $this->logger->error('Database migration failed', $context + [
                'reason' => $error instanceof MigrationException ? $error->getMessage() : get_class($error),
                'cause' => get_class($error->getPrevious() ?? $error),
                'error_code' => ($error->getPrevious() ?? $error)->getCode(),
                'elapsed_seconds' => round(microtime(true) - $started, 3),
            ]);
            throw $error;
        }
    }

    private function validateMigrationRegistry(): int
    {
        foreach ($this->migrations as $version => $migration) {
            if (!is_int($version) || $version < 1) {
                throw new MigrationException('Invalid migration registry');
            }
            if (!$migration instanceof Migration) {
                throw new MigrationException('Invalid migration registry');
            }
        }
        ksort($this->migrations);
        $target = $this->migrations ? max(array_keys($this->migrations)) : 0;
        if ($target > 0 && array_keys($this->migrations) !== range(1, $target)) {
            throw new MigrationException('Migration registry contains missing versions');
        }
        return $target;
    }

    private function loadCurrentVersion(Collection $versions): int
    {
        $record = $versions->findOne(['_id' => 'schema']);
        $current = $record === null ? 0 : ($record['version'] ?? null);
        if (!is_int($current) || $current < 0) {
            throw new MigrationException('Invalid database schema version');
        }
        return $current;
    }
}
