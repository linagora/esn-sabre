<?php
/**
 * Creates the MongoDB indexes of the sabre database. Idempotent: MongoDB skips an index that already exists.
 *
 * Run once at container startup by scripts/start.sh, rather than on every HTTP request.
 *
 * Usage: php scripts/create-indexes.php [path/to/config.json]
 */

require_once __DIR__ . '/../vendor/autoload.php';

try {
    $configPath = $argv[1] ?? __DIR__ . '/../config.json';
    $config = json_decode(file_get_contents($configPath), true);
    if (!$config) {
        fwrite(STDERR, "Could not load " . $configPath . "\n");
        exit(1);
    }
    \ESN\Utils\Env::init($config['environment'] ?? null);

    $dbConfig = $config['database'];
    $connectionString = $dbConfig['sabre']['connectionString'];
    $client = new \MongoDB\Client($connectionString, $dbConfig['sabre']['connectionOptions'] ?? []);
    $dbName = \ESN\Utils\Utils::getDatabaseName("sabre", $connectionString, $dbConfig);
    if (!$dbName) {
        fwrite(STDERR, "Unable to get SABRE database name from configuration\n");
        exit(1);
    }
    $db = $client->{$dbName};

    $schedulingObjectTTLInDays = $dbConfig['schedulingObjectTTLInDays'] ?? 7;
    (new \ESN\CalDAV\Backend\Mongo($db, $schedulingObjectTTLInDays))->ensureIndexes();
    $carddavBackend = new \ESN\CardDAV\Backend\Mongo($db);
    $carddavBackend->ensureIndexes();

    echo "MongoDB indexes created on database " . $dbName . "\n";
} catch (\Throwable $error) {
    // Driver messages can quote the connection URI, password included ("Failed to parse MongoDB URI: '...'"),
    // and PHP would print it verbatim for an uncaught exception.
    fwrite(STDERR, "MongoDB index creation failed: " . get_class($error) . " (code " . $error->getCode() . "): "
        . \ESN\Utils\MongoCredentialRedactor::redact($error->getMessage(), $connectionString ?? null) . "\n");
    exit(1);
}
