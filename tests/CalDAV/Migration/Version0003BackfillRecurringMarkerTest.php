<?php

namespace ESN\CalDAV\Migration;

use ESN\Utils\Migration\MigrationRunner;
use MongoDB\Client;
use MongoDB\Database;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;

class Version0003BackfillRecurringMarkerTest extends TestCase
{
    private Database $database;
    private TestHandler $logs;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->database = (new Client(ESN_MONGO_SABREURI))->selectDatabase(ESN_MONGO_SABREDB . '_recurring');
        $this->database->drop();
        $this->logs = new TestHandler();
        $this->logger = new Logger('MigrationTest', [$this->logs]);
    }

    protected function tearDown(): void
    {
        $this->database->drop();
    }

    private function event(string $extraLines): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//test//EN\r\n"
            . "BEGIN:VEVENT\r\nUID:e\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T090000Z\r\n"
            . $extraLines . "END:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    public function testRecurringMarkerIsBackfilledForEvents(): void
    {
        $this->database->calendarobjects->insertMany([
            ['_id' => 1, 'componenttype' => 'VEVENT', 'calendardata' => $this->event(''), 'etag' => 'e1'],
            ['_id' => 2, 'componenttype' => 'VEVENT', 'calendardata' => $this->event("RRULE:FREQ=WEEKLY;COUNT=3\r\n"), 'etag' => 'e2'],
            ['_id' => 3, 'componenttype' => 'VEVENT', 'calendardata' => $this->event(''), 'recurring' => true, 'etag' => 'e3'],
            ['_id' => 4, 'componenttype' => 'VEVENT', 'calendardata' => 'not a calendar', 'etag' => 'e4'],
            ['_id' => 5, 'componenttype' => 'VTODO', 'calendardata' => "BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nEND:VTODO\r\nEND:VCALENDAR\r\n", 'etag' => 'e5'],
        ]);

        (new MigrationRunner($this->database, $this->logger))->run();

        $object = fn ($id) => $this->database->calendarobjects->findOne(['_id' => $id]);
        $this->assertFalse($object(1)['recurring']);
        $this->assertTrue($object(2)['recurring']);
        $this->assertTrue($object(3)['recurring']);
        $this->assertFalse(isset($object(4)['recurring']));
        $this->assertFalse(isset($object(5)['recurring']));
        $this->assertSame('e1', $object(1)['etag']);
        $this->assertSame(6, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
        $this->assertTrue($this->logs->hasWarningThatContains('Skipping calendar object'));
        $this->assertFalse($this->logs->hasErrorRecords());
    }

    public function testLegacyBoundsAreRecomputedWithTheMarker(): void
    {
        // An older backend stored this 09:00-10:00 UTC event one hour late.
        $this->database->calendarobjects->insertOne([
            '_id' => 1, 'componenttype' => 'VEVENT', 'calendardata' => $this->event("DTEND:20260101T100000Z\r\n"),
            'firstoccurence' => 1767261600, 'lastoccurence' => 1767265200,
        ]);

        (new MigrationRunner($this->database, $this->logger))->run();

        $object = $this->database->calendarobjects->findOne(['_id' => 1]);
        $this->assertFalse($object['recurring']);
        $this->assertSame(1767258000, $object['firstoccurence']);
        $this->assertSame(1767261600, $object['lastoccurence']);
    }
}
