<?php
namespace ESN\Publisher\CalDAV;

require_once ESN_TEST_BASE . '/CalDAV/MockUtils.php';

class CalendarRealTimePluginTest extends \PHPUnit_Framework_TestCase {

    const PATH = "calendars/123123/uid.ics";
    const ETAG = 'The etag';

    protected $eventEmitter;
    protected $plugin;
    protected $publisher;
    protected $server;

    function setUp() {
        $this->eventEmitter = $this->getMock(\Sabre\Event\EventEmitter::class);
        $this->eventEmitter->expects($this->exactly(4))->method('on');

        $this->publisher = $this->getMock(\ESN\Publisher\Publisher::class);
        $this->plugin = new CalendarRealTimePlugin($this->publisher, $this->eventEmitter);
        
        $this->server = $this->getMock(\Sabre\DAV\Server::class);
        $this->mockTree();

        $this->plugin->initialize($this->server);
    }

    private function mockTree() {
        $this->server->tree = $this->getMockBuilder('\Sabre\DAV\Tree')->disableOriginalConstructor()->getMock();
        $this->server->tree->expects($this->any())->method('nodeExists')
            ->with('/'.self::PATH)
            ->willReturn(true);
        $this->server->expects($this->any())->method('getPlugin')
            ->willReturn($this->getMock(\ESN\DAV\Sharing\Plugin::class));

        $nodeMock = $this->getMockBuilder('\Sabre\CalDAV\Calendar')->disableOriginalConstructor()->getMock();
        $nodeMock->expects($this->any())->method('getETag')->willReturn(self::ETAG);
        $nodeMock->expects($this->any())->method('getProperties')->willReturn(array());

        $this->server->tree->expects($this->any())->method('getNodeForPath')
            ->with('/'.self::PATH)
            ->will($this->returnValue($nodeMock));
    }

    function testGetCalendarProps() {
        $node = $this->getMockBuilder('\Sabre\CalDAV\Calendar')->disableOriginalConstructor()->getMock();

        $node->expects($this->once())->method('getProperties');
        $this->plugin->getCalendarProps($node);
    }

    function testPrepareAndPublishMessage() {

        $this->publisher->expects($this->once())->method('publish');

        $this->plugin->prepareAndPublishMessages('path', 'props', 'topic');
    }

    function testCalendarCreated() {
        
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('calendar:calendar:created');
        $this->plugin->calendarCreated('/' . self::PATH);
    }

    function testCalendarDeleted() {

        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('calendar:calendar:deleted');
        $this->plugin->calendarDeleted('/' . self::PATH);
    }

    function testCalendarUpdated() {

        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('calendar:calendar:updated');
        $this->plugin->calendarUpdated('/' . self::PATH);
    }

    function testUpdateMultipleSharees() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('/principal/user/userUri1', 1, 2),
                'uri' => 'uid.ics',
                'type' => 'delete'
            ],
            [
                'sharee' => new ShareeSimple('/principal/user/userUri1', 1, 2),
                'uri' => 'uid.ics',
                'type' => 'create'
            ],
            [
                'sharee' => new ShareeSimple('/principal/user/userUri1', 1, 2),
                'uri' => 'uid.ics',
                'type' => 'update'
            ]
        ];

        $this->publisher
            ->expects($this->exactly(sizeof($calendarInstances)))
            ->method('publish');

        $this->plugin->updateSharees($calendarInstances);
    }

    function testUpdateNotAcceptedShareesDelete() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/user/userUri', 1),
                'uri' => 'uid.ics',
                'type' => 'delete'
            ]
        ];

        $this->publisher
            ->expects($this->never())
            ->method('publish');

        $this->plugin->updateSharees($calendarInstances);
    }

    function testUpdateAcceptedShareesDelete() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/user/userUri', 1, 2),
                'uri' => 'uid.ics',
                'type' => 'delete'
            ]
        ];

        $this->publisher
            ->expects($this->exactly(sizeof($calendarInstances)))
            ->method('publish');

        $this->plugin->updateSharees($calendarInstances);
    }

    function testUpdateNotAcceptedShareesCreate() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/user/userUri', 1),
                'uri' => 'uid.ics',
                'type' => 'create'
            ]
        ];

        $this->publisher
            ->expects($this->never())
            ->method('publish');

        $this->plugin->updateSharees($calendarInstances);
    }

    function testUpdateAcceptedShareesCreate() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/user/userUri', 1, 2),
                'uri' => 'uid.ics',
                'type' => 'create'
            ]
        ];

        $this->publisher
            ->expects($this->exactly(sizeof($calendarInstances)))
            ->method('publish');

        $this->plugin->updateSharees($calendarInstances);
    }

    function testUpdateNotAcceptedShareesUpdate() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/user/userUri', 1),
                'uri' => 'uid.ics',
                'type' => 'update'
            ]
        ];

        $this->publisher
            ->expects($this->never())
            ->method('publish');

        $this->plugin->updateSharees($calendarInstances);
    }

    function testUpdateAcceptedShareesUpdate() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/user/userUri', 1, 2),
                'uri' => 'uid.ics',
                'type' => 'update'
            ]
        ];

        $this->publisher
            ->expects($this->exactly(sizeof($calendarInstances)))
            ->method('publish');

        $this->plugin->updateSharees($calendarInstances);
    }
}

class ShareeSimple {
    public $principal;
    public $access;

    function __construct($principal, $access, $inviteStatus = null) {
        $this->principal = $principal;
        $this->access = $access;
        $this->inviteStatus = $inviteStatus;
    }
}