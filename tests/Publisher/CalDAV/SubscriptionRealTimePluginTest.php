<?php
namespace ESN\Publisher\CalDAV;

require_once ESN_TEST_BASE . '/CalDAV/MockUtils.php';

class SubscriptionRealTimePluginTest extends \PHPUnit\Framework\TestCase {

    const PATH = "/calendars/123123.json";
    const ETAG = 'The etag';

    protected $eventEmitter;
    protected $plugin;
    protected $publisher;
    protected $server;

    function setUp(): void {
        $this->eventEmitter = $this->createMock(\Sabre\Event\EventEmitter::class);
        $this->eventEmitter->expects($this->exactly(3))->method('on');

        $this->publisher = $this->createMock(\ESN\Publisher\Publisher::class);
        $this->plugin = new SubscriptionRealTimePlugin($this->publisher, $this->eventEmitter);

        $this->server = $this->createMock(\Sabre\DAV\Server::class);

        $this->plugin->initialize($this->server);
    }

    function testPrepareAndPublishMessage() {
        $this->publisher->expects($this->once())->method('publish');
        $this->plugin->prepareAndPublishMessages('path', 'topic');
    }

    function testSubscriptionCreated() {
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('calendar:subscription:created', json_encode(["calendarPath" => self::PATH, "calendarSourcePath" => null]));
        $this->plugin->subscriptionCreated(self::PATH);
    }

    function testCalendarDeleted() {
        $sourcePath = 'source/path';
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('calendar:subscription:deleted', json_encode(["calendarPath" => self::PATH, "calendarSourcePath" => $sourcePath]));
        $this->plugin->subscriptionDeleted(self::PATH, $sourcePath);
    }

    function testCalendarUpdated() {
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('calendar:subscription:updated', json_encode(["calendarPath" => self::PATH, "calendarSourcePath" => null]));
        $this->plugin->subscriptionUpdated(self::PATH);
    }
}