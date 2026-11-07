<?php

namespace ESN\CalDAV\Migration;

use ESN\Utils\Migration\MigrationRunner;
use MongoDB\Client;
use MongoDB\Database;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class Version0002RecomputePrivateOverrideClassificationTest extends TestCase
{
    private Database $database;
    private TestHandler $logs;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->database = (new Client(ESN_MONGO_SABREURI))->selectDatabase(ESN_MONGO_SABREDB . '_classification');
        $this->database->drop();
        $this->logs = new TestHandler();
        $this->logger = new Logger('MigrationTest', [$this->logs]);
    }

    protected function tearDown(): void
    {
        $this->database->drop();
    }

    private function series(string $masterClass, string $overrideClass): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:s\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T090000Z\r\n"
            . "RRULE:FREQ=WEEKLY;COUNT=3\r\nCLASS:$masterClass\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:s\r\nDTSTAMP:20260101T000000Z\r\nRECURRENCE-ID:20260108T090000Z\r\n"
            . "DTSTART:20260108T090000Z\r\nCLASS:$overrideClass\r\nSUMMARY:Doctor\r\nEND:VEVENT\r\n"
            . "END:VCALENDAR\r\n";
    }

    public function testPrivateOverrideOfPublicSeriesBecomesPrivate(): void
    {
        $this->database->calendarobjects->insertMany([
            ['_id' => 1, 'calendardata' => $this->series('PUBLIC', 'PRIVATE'), 'classification' => 'PUBLIC', 'etag' => 'e1'],
            ['_id' => 2, 'calendardata' => $this->series('PUBLIC', 'Confidential'), 'classification' => null, 'etag' => 'e2'],
            ['_id' => 3, 'calendardata' => $this->series('PUBLIC', 'PUBLIC'), 'classification' => 'PUBLIC', 'etag' => 'e3'],
            ['_id' => 4, 'calendardata' => $this->series('PRIVATE', 'PUBLIC'), 'classification' => 'PRIVATE', 'etag' => 'e4'],
            ['_id' => 5, 'calendardata' => "CLASS:PRIVATE\r\nnot a calendar", 'classification' => null, 'etag' => 'e5'],
        ]);

        (new MigrationRunner($this->database, $this->logger))->run();

        $classification = fn ($id) => $this->database->calendarobjects->findOne(['_id' => $id])['classification'];
        $this->assertSame('PRIVATE', $classification(1));
        $this->assertSame('CONFIDENTIAL', $classification(2));
        $this->assertSame('PUBLIC', $classification(3));
        $this->assertSame('PRIVATE', $classification(4));
        $this->assertNull($classification(5));
        $this->assertSame('e1', $this->database->calendarobjects->findOne(['_id' => 1])['etag']);
        $this->assertSame(2, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
        $this->assertTrue($this->logs->hasWarningThatContains('Skipping calendar object'));
        $this->assertFalse($this->logs->hasErrorRecords());
    }
}
