<?php

namespace ESN\CalDAV\Migration;

use ESN\Utils\Migration\MigrationRunner;
use MongoDB\BSON\ObjectId;
use MongoDB\Client;
use MongoDB\Database;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class Version0007RemapSharedInstanceSubscriptionsTest extends TestCase
{
    private const OWNER = '5f50a663bdaffe002629099c';
    private const SHAREE = '54b64eadf6d7d8e41d263e0f';
    private const SUBSCRIBER = 'principals/users/54b64eadf6d7d8e41d263e0e';

    private Database $database;
    private TestHandler $logs;
    private Logger $logger;
    private ObjectId $calendarId;

    protected function setUp(): void
    {
        $this->database = (new Client(ESN_MONGO_SABREURI))->selectDatabase(ESN_MONGO_SABREDB . '_shared_instance_subscriptions');
        $this->database->drop();
        $this->logs = new TestHandler();
        $this->logger = new Logger('MigrationTest', [$this->logs]);
        $this->calendarId = new ObjectId();
        $this->database->calendarinstances->insertMany([
            ['calendarid' => $this->calendarId, 'principaluri' => 'principals/users/' . self::OWNER, 'uri' => 'events', 'access' => 1],
            ['calendarid' => $this->calendarId, 'principaluri' => 'principals/users/' . self::SHAREE, 'uri' => 'shared-uri', 'access' => 2],
        ]);
    }

    protected function tearDown(): void
    {
        $this->database->drop();
    }

    public static function sharedInstanceSources(): array
    {
        return [
            'canonical' => ['calendars/' . self::SHAREE . '/shared-uri'],
            'absolute' => ['/calendars/' . self::SHAREE . '/shared-uri/'],
        ];
    }

    #[DataProvider('sharedInstanceSources')]
    public function testSubscriptionToASharedInstanceIsRemappedToTheOwnerInstance(string $source): void
    {
        $this->subscribe('shared', $source);

        (new MigrationRunner($this->database, $this->logger))->run();

        $this->assertSame('calendars/' . self::OWNER . '/events', $this->storedSource('shared'));
        $this->assertSame(7, $this->database->db_version->findOne(['_id' => 'schema'])['version']);
        $this->assertTrue($this->logs->hasInfoThatContains('Shared instance subscription remapping completed'));
        $this->assertFalse($this->logs->hasErrorRecords());
    }

    public static function untouchedSources(): array
    {
        return [
            'owner instance' => ['/calendars/' . self::OWNER . '/events'],
            'unknown calendar' => ['calendars/' . self::SHAREE . '/unknown'],
            'not a calendar path' => ['calendars/' . self::SHAREE . '/shared-uri/event.ics'],
        ];
    }

    #[DataProvider('untouchedSources')]
    public function testOtherSubscriptionsAreLeftUntouched(string $source): void
    {
        $this->subscribe('other', $source);

        (new Version0007RemapSharedInstanceSubscriptions())->up($this->database, $this->logger);

        $this->assertSame($source, $this->storedSource('other'));
    }

    public function testSharedInstanceOfADeletedCalendarIsLeftUntouched(): void
    {
        $this->database->calendarinstances->deleteOne(['access' => 1]);
        $source = 'calendars/' . self::SHAREE . '/shared-uri';
        $this->subscribe('orphan', $source);

        (new Version0007RemapSharedInstanceSubscriptions())->up($this->database, $this->logger);

        $this->assertSame($source, $this->storedSource('orphan'));
    }

    private function subscribe(string $uri, string $source): void
    {
        $this->database->calendarsubscriptions->insertOne(['principaluri' => self::SUBSCRIBER, 'uri' => $uri, 'source' => $source]);
    }

    private function storedSource(string $uri): string
    {
        return $this->database->calendarsubscriptions->findOne(['uri' => $uri])['source'];
    }
}
