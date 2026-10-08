<?php

namespace ESN\DAV\Migration;

use MongoDB\Client;
use MongoDB\Database;
use PHPUnit\Framework\TestCase;

class MigrationCommandTest extends TestCase
{
    private Database $database;
    private string $configPath;
    private array $config;

    protected function setUp(): void
    {
        $this->database = (new Client(ESN_MONGO_SABREURI))->selectDatabase(ESN_MONGO_SABREDB . '_command');
        $this->database->drop();
        $this->configPath = tempnam(ESN_TEMPDIR, 'migration-');
        $this->config = ['database' => ['sabre' => [
            'connectionString' => ESN_MONGO_SABREURI,
            'db' => $this->database->getDatabaseName(),
        ]]];
    }

    protected function tearDown(): void
    {
        $this->database->drop();
        unlink($this->configPath);
    }

    private function runCommand(array $arguments, string $exportedValue = ''): array
    {
        file_put_contents($this->configPath, json_encode($this->config));
        $process = proc_open([
            PHP_BINARY, dirname(__DIR__, 3) . '/scripts/migrate-database.php', ...$arguments, $this->configPath,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null,
            array_replace(getenv(), ['SABRE_MIGRATE_ON_STARTUP' => $exportedValue]));
        $output = stream_get_contents($pipes[1]);
        $output .= stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return [proc_close($process), $output];
    }

    public function testStartupMigratesByDefault(): void
    {
        [$code, $output] = $this->runCommand(['--on-startup']);

        $this->assertSame(0, $code, $output);
        $this->assertSame(1, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
        $this->assertStringContainsString('Database migration runner completed', $output);
    }

    public function testStartupSkipsMigrationWhenDisabled(): void
    {
        [$code, $output] = $this->runCommand(['--on-startup'], 'false');

        $this->assertSame(0, $code, $output);
        $this->assertNull($this->database->db_version->findOne(['_id' => 'schema']));
        $this->assertStringContainsString('Automatic database migration disabled', $output);
    }

    public function testManualCommandRunsWhenAutomaticMigrationIsDisabled(): void
    {
        $this->config['environment'] = ['SABRE_MIGRATE_ON_STARTUP' => false];

        [$code, $output] = $this->runCommand([], 'false');

        $this->assertSame(0, $code, $output);
        $this->assertSame(1, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
        $this->assertStringContainsString('Database migration runner completed', $output);
    }
}
