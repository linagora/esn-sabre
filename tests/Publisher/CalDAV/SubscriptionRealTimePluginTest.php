<?php
namespace ESN\Publisher\CalDAV;

require_once ESN_TEST_BASE . '/CalDAV/MockUtils.php';

class SubscriptionRealTimePluginTest extends \PHPUnit_Framework_TestCase {

    const PATH = "/calendars/123123.json";
    const ETAG = 'The etag';

    protected $eventEmitter;
    protected $plugin;
    protected $publisher;
    protected $server;

    function setUp() {
        $this->eventEmitter = $this->getMock(\Sabre\Event\EventEmitter::class);
        $this->eventEmitter->expects($this->exactly(3))->method('on');

        $this->publisher = $this->getMock(\ESN\Publisher\Publisher::class);
        $this->plugin = new SubscriptionRealTimePlugin($this->publisher, $this->eventEmitter);
        
        $this->server = $this->getMock(\Sabre\DAV\Server::class);

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
            ->with('calendar:subscription:created', json_encode(["calendarPath" => self::PATH]));
        $this->plugin->subscriptionCreated(self::PATH);
    }

    function testCalendarDeleted() {
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('calendar:subscription:deleted', json_encode(["calendarPath" => self::PATH]));
        $this->plugin->subscriptionDeleted(self::PATH);
    }

    function testCalendarUpdated() {
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('calendar:subscription:updated', json_encode(["calendarPath" => self::PATH]));
        $this->plugin->subscriptionUpdated(self::PATH);
    }
}