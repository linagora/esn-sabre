<?php

namespace ESN\CardDAV\Migration;

use ESN\CardDAV\Backend\Mongo;
use ESN\Utils\Migration\MigrationException;
use ESN\Utils\Migration\MigrationRunner;
use MongoDB\BSON\ObjectId;
use MongoDB\Client;
use MongoDB\Database;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Version0001BackfillContactSortFieldsTest extends TestCase
{
    private Database $database;
    private TestHandler $logs;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->database = (new Client(ESN_MONGO_SABREURI))->selectDatabase(ESN_MONGO_SABREDB . '_backfill');
        $this->database->drop();
        $this->logs = new TestHandler();
        $this->logger = new Logger('MigrationTest', [$this->logs]);
    }

    protected function tearDown(): void
    {
        $this->database->drop();
    }

    private function vCard(string $fullName = 'Contact', string $emails = '', string $version = '3.0'): string
    {
        return "BEGIN:VCARD\r\nVERSION:$version\r\nFN:$fullName\r\n$emails\r\nEND:VCARD\r\n";
    }

    public static function contactPayloads(): array
    {
        return [
            'vcard3 preferred' => ['3.0', "EMAIL:first@example.org\r\nEMAIL;TYPE=PREF: preferred@example.org ", 'preferred@example.org'],
            'vcard4 preferred' => ['4.0', "EMAIL;PREF=9:other@example.org\r\nEMAIL;PREF=1: preferred@example.org ", 'preferred@example.org'],
            'first email fallback' => ['3.0', "EMAIL: first@example.org \r\nEMAIL:second@example.org", 'first@example.org'],
            'no email' => ['3.0', '', ''],
        ];
    }

    #[DataProvider('contactPayloads')]
    public function testBackfillMatchesCreationAndUpdateAndPreservesMetadata(string $version, string $emails, string $expectedEmail): void
    {
        $bookId = new ObjectId();
        $this->database->addressbooks->insertOne(['_id' => $bookId, 'synctoken' => 42]);
        $payload = $this->vCard(' Élodie Nguyễn ', $emails, $version);
        $backend = new Mongo($this->database);
        $backend->createCard((string)$bookId, 'contact.vcf', $payload);
        $created = $this->database->cards->findOne(['uri' => 'contact.vcf']);
        $backend->updateCard((string)$bookId, 'contact.vcf', $payload);
        $updated = $this->database->cards->findOne(['uri' => 'contact.vcf']);
        $this->assertSame('Élodie Nguyễn', $created['fn_sort']);
        $this->assertSame($expectedEmail, $created['email_sort']);
        $this->assertSame($created['fn_sort'], $updated['fn_sort']);
        $this->assertSame($created['email_sort'], $updated['email_sort']);

        $book = $this->database->addressbooks->findOne(['_id' => $bookId]);
        $changes = $this->database->addressbookchanges->find()->toArray();
        $this->database->cards->updateOne(['_id' => $created['_id']], ['$unset' => ['fn_sort' => '', 'email_sort' => '']]);

        (new MigrationRunner($this->database, $this->logger))->run();

        $this->assertEquals($updated, $this->database->cards->findOne(['_id' => $created['_id']]));
        $this->assertEquals($book, $this->database->addressbooks->findOne(['_id' => $bookId]));
        $this->assertEquals($changes, $this->database->addressbookchanges->find()->toArray());
    }

    public function testOnlyMissingFieldsAreWrittenAndCompleteCardsAreNotParsed(): void
    {
        $payload = $this->vCard('Actual name', 'EMAIL:actual@example.org');
        $this->database->cards->insertMany([
            ['_id' => 1, 'carddata' => $payload, 'fn_sort' => ''],
            ['_id' => 2, 'carddata' => $payload, 'email_sort' => 'existing@example.org'],
            ['_id' => 3, 'carddata' => 'invalid but already complete', 'fn_sort' => '', 'email_sort' => ''],
            ['_id' => 4, 'fn_sort' => null, 'email_sort' => null],
        ]);
        (new Version0001BackfillContactSortFields())->up($this->database, $this->logger);

        $this->assertSame('', $this->database->cards->findOne(['_id' => 1])['fn_sort']);
        $this->assertSame('actual@example.org', $this->database->cards->findOne(['_id' => 1])['email_sort']);
        $this->assertSame('Actual name', $this->database->cards->findOne(['_id' => 2])['fn_sort']);
        $this->assertSame('existing@example.org', $this->database->cards->findOne(['_id' => 2])['email_sort']);
        $this->assertSame('', $this->database->cards->findOne(['_id' => 3])['email_sort']);
        $this->assertNull($this->database->cards->findOne(['_id' => 4])['fn_sort']);
    }

    public function testInvalidContactIsSkippedAndVersionAdvancesAfterAllBatches(): void
    {
        $cards = [];
        for ($id = 1; $id <= Version0001BackfillContactSortFields::BATCH_SIZE; $id++) {
            $cards[] = ['_id' => $id, 'carddata' => $this->vCard()];
        }
        $cards[] = ['_id' => 501, 'carddata' => 'PRIVATE INVALID VCARD'];
        $cards[] = ['_id' => 502, 'carddata' => $this->vCard()];
        $this->database->cards->insertMany($cards);
        $runner = new MigrationRunner($this->database, $this->logger);
        $runner->run();
        $this->assertSame(501, $this->database->cards->countDocuments(['fn_sort' => ['$exists' => true]]));
        $this->assertSame('Contact', $this->database->cards->findOne(['_id' => 502])['fn_sort']);
        $this->assertSame(1, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
        $records = $this->logs->getRecords();
        $batchLogs = array_values(array_filter($records, fn ($record) => $record['message'] === 'Contact sort backfill batch completed'));
        $this->assertCount(2, $batchLogs);
        $this->assertSame(500, $batchLogs[0]['context']['contacts_read']);
        $this->assertSame(500, $batchLogs[0]['context']['contacts_updated']);
        $this->assertArrayHasKey('elapsed_seconds', $batchLogs[0]['context']);
        $this->assertSame(502, $batchLogs[1]['context']['contacts_read']);
        $this->assertSame(501, $batchLogs[1]['context']['contacts_updated']);
        $this->assertSame(1, $batchLogs[1]['context']['contacts_skipped']);
        $this->assertTrue($this->logs->hasWarningThatContains('Skipping contact'));
        $warning = array_values(array_filter($records, fn ($record) => $record['level'] === Logger::WARNING))[0];
        $this->assertSame('501', $warning['context']['contact_id']);
        $this->assertSame(2, $warning['context']['batch']);
        $this->assertStringNotContainsString('PRIVATE INVALID VCARD', json_encode($records));
    }

    public function testConcurrentContactUpdateIsNotOverwrittenByBackfill(): void
    {
        $bookId = new ObjectId();
        $this->database->addressbooks->insertOne(['_id' => $bookId, 'synctoken' => 42]);
        $this->database->cards->insertMany([
            ['_id' => 1, 'addressbookid' => $bookId, 'uri' => 'contact.vcf',
                'carddata' => $this->vCard('Old name', 'EMAIL:old@example.org')],
            ['_id' => 2, 'carddata' => 'invalid'],
        ]);
        $updated = null;
        $logger = $this->createMock(\Psr\Log\LoggerInterface::class);
        // The warning occurs after contact 1 is read, but before the pending batch is written.
        $logger->expects($this->once())->method('warning')->willReturnCallback(function () use ($bookId, &$updated) {
            (new Mongo($this->database))->updateCard(
                (string)$bookId, 'contact.vcf', $this->vCard('New name', 'EMAIL:new@example.org')
            );
            $updated = $this->database->cards->findOne(['_id' => 1]);
        });

        (new MigrationRunner($this->database, $logger))->run();

        $this->assertSame('New name', $this->database->cards->findOne(['_id' => 1])['fn_sort']);
        $this->assertSame('new@example.org', $this->database->cards->findOne(['_id' => 1])['email_sort']);
        $this->assertEquals($updated, $this->database->cards->findOne(['_id' => 1]));
        $this->assertSame(1, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
    }

    public static function invalidCards(): array
    {
        return [
            'missing payload' => [[]],
            'non-string payload' => [['carddata' => 123]],
            'malformed payload' => [['carddata' => 'not a vcard']],
            'calendar payload' => [['carddata' => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n"]],
        ];
    }

    public function testPartialBulkWriteFailureKeepsVersionAndRetryPreservesSuccessfulUpdates(): void
    {
        $this->database->createCollection('cards', ['validator' => [
            '$or' => [['_id' => ['$ne' => 2]], ['email_sort' => ['$exists' => false]]],
        ]]);
        $this->database->cards->insertMany([
            ['_id' => 1, 'carddata' => $this->vCard()],
            ['_id' => 2, 'carddata' => $this->vCard()],
        ]);
        $runner = new MigrationRunner($this->database, $this->logger);
        try {
            $runner->run();
            $this->fail('Expected bulk write failure');
        } catch (MigrationException $error) {
            $this->assertSame('Unable to write contact sort batch 1, contact 2', $error->getMessage());
            $this->assertSame(1, $this->database->cards->countDocuments(['fn_sort' => ['$exists' => true]]));
            $this->assertNull($this->database->db_version->findOne(['_id' => 'schema']));
        }
        $this->database->command(['collMod' => 'cards', 'validator' => new \stdClass()]);
        $runner->run();
        $this->assertSame(2, $this->database->cards->countDocuments(['fn_sort' => 'Contact', 'email_sort' => '']));
        $this->assertSame(1, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
    }

    #[DataProvider('invalidCards')]
    public function testInvalidContactIsPreservedAndDoesNotPreventVersionAdvance(array $card): void
    {
        $this->database->cards->insertMany([['_id' => 1] + $card, ['_id' => 2, 'carddata' => $this->vCard()]]);
        $invalid = $this->database->cards->findOne(['_id' => 1]);

        (new MigrationRunner($this->database, $this->logger))->run();

        $this->assertEquals($invalid, $this->database->cards->findOne(['_id' => 1]));
        $this->assertSame('Contact', $this->database->cards->findOne(['_id' => 2])['fn_sort']);
        $this->assertSame(1, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
        $this->assertTrue($this->logs->hasWarningThatContains('Skipping contact'));
        $this->assertFalse($this->logs->hasErrorRecords());
    }
}
