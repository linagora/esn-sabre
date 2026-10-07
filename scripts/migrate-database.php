<?php

require_once __DIR__ . '/../vendor/autoload.php';

use ESN\Utils\Migration\MigrationException;
use ESN\Utils\Migration\MigrationRunner;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

// Startup INFO logs must remain visible even when HTTP logging only enables ERROR.
$logger = new Logger('DatabaseMigration', [new StreamHandler('php://stdout', Logger::INFO)]);

try {
    if ($argc > 2 || (isset($argv[1]) && str_starts_with($argv[1], '--'))) {
        throw new MigrationException('Usage: php scripts/migrate-database.php [config-path]');
    }
    $configPath = $argv[1] ?? __DIR__ . '/../config.json';
    $logger->info('Loading database migration configuration', ['config_path' => $configPath]);
    $contents = @file_get_contents($configPath);
    $config = $contents === false ? null : json_decode($contents, true);
    if (!is_array($config) || !isset($config['database']['sabre']['connectionString'])) {
        throw new MigrationException('Unable to load Sabre database configuration');
    }
    \ESN\Utils\Env::init($config['environment'] ?? null);
    $dbConfig = $config['database'];
    $connectionString = $dbConfig['sabre']['connectionString'];
    $dbName = \ESN\Utils\Utils::getDatabaseName('sabre', $connectionString, $dbConfig);
    if (!$dbName) {
        throw new MigrationException('Unable to get Sabre database name from configuration');
    }
    $client = new \MongoDB\Client($connectionString, $dbConfig['sabre']['connectionOptions'] ?? []);
    (new MigrationRunner($client->selectDatabase($dbName), $logger))->run();
} catch (\Throwable $error) {
    $logger->error('Database migration command failed', [
        'reason' => $error instanceof MigrationException ? $error->getMessage() : get_class($error),
        'error_code' => $error->getCode(),
    ]);
    exit(1);
}
