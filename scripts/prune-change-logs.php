<?php
/**
 * Deletes the calendar and address book changes older than their retention, see doc/storage/MONGO.md § "calendarchanges".
 *
 * Run in the background by scripts/start.sh, unless SABRE_PRUNE_CHANGES_ON_STARTUP=false; operators can also run it
 * periodically (cron, Kubernetes CronJob). Safe to run at any time and concurrently with DAV traffic.
 *
 * Usage: php scripts/prune-change-logs.php [--on-startup] [path/to/config.json]
 */

require_once __DIR__ . '/../vendor/autoload.php';

use ESN\DAV\ChangeLog\ChangeLog;
use ESN\DAV\ChangeLog\ChangeLogPruner;
use ESN\Utils\MongoCredentialRedactor;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

$logger = new Logger('ChangeLogPruning', [new StreamHandler('php://stdout', Logger::INFO)]);

try {
    $arguments = array_slice($argv, 1);
    $onStartup = ($arguments[0] ?? null) === '--on-startup';
    if ($onStartup) {
        array_shift($arguments);
    }
    if (count($arguments) > 1 || (isset($arguments[0]) && str_starts_with($arguments[0], '--'))) {
        throw new InvalidArgumentException('Usage: php scripts/prune-change-logs.php [--on-startup] [config-path]');
    }
    $configPath = $arguments[0] ?? __DIR__ . '/../config.json';
    $contents = @file_get_contents($configPath);
    $config = $contents === false ? null : json_decode($contents, true);
    if (!is_array($config) || !isset($config['database']['sabre']['connectionString'])) {
        throw new InvalidArgumentException('Unable to load Sabre database configuration from ' . $configPath);
    }
    \ESN\Utils\Env::init($config['environment'] ?? null);
    // Only automatic startup honors this switch; explicit operator commands must still run.
    if ($onStartup && !\ESN\Utils\Env::getBoolean('SABRE_PRUNE_CHANGES_ON_STARTUP', true)) {
        $logger->info('Automatic change log pruning disabled', ['setting' => 'SABRE_PRUNE_CHANGES_ON_STARTUP']);
        exit(0);
    }

    $dbConfig = $config['database'];
    $connectionString = $dbConfig['sabre']['connectionString'];
    $dbName = \ESN\Utils\Utils::getDatabaseName('sabre', $connectionString, $dbConfig);
    if (!$dbName) {
        throw new InvalidArgumentException('Unable to get Sabre database name from configuration');
    }
    $client = new \MongoDB\Client($connectionString, $dbConfig['sabre']['connectionOptions'] ?? []);
    $pruner = new ChangeLogPruner($client->selectDatabase($dbName), $logger);

    $defaultRetentionInDays = 90;
    $pruner->prune(ChangeLog::calendars(), (int) ($dbConfig['calendarChangesRetentionInDays'] ?? $defaultRetentionInDays));
    $pruner->prune(ChangeLog::addressBooks(), (int) ($dbConfig['addressBookChangesRetentionInDays'] ?? $defaultRetentionInDays));
} catch (\Throwable $error) {
    // Driver messages can quote the connection URI, password included ("Failed to parse MongoDB URI: '...'").
    $logger->error('Change log pruning failed', [
        'reason' => MongoCredentialRedactor::redact($error->getMessage(), $connectionString ?? null),
        'error' => get_class($error),
        'error_code' => $error->getCode(),
    ]);
    exit(1);
}
