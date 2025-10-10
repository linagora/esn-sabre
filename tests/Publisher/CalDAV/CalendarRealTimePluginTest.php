<?php
namespace ESN\Publisher\CalDAV;

require_once ESN_TEST_BASE . '/CalDAV/MockUtils.php';

class CalendarRealTimePluginTest extends \PHPUnit\Framework\TestCase {

    const PATH = "calendars/123123/uid.ics";
    protected $eventEmitter;
    protected $plugin;
    protected $publisher;
    protected $server;

    function setUp() {
        $this->calendarBackend = new CalendarBackendMock();
        $this->calendarBackend->setEventEmitter($this->createMock(\Sabre\Event\EventEmitter::class));

        $this->publisher = $this->createMock(\ESN\Publisher\Publisher::class);
        $this->plugin = new CalendarRealTimePlugin($this->publisher, $this->calendarBackend, $this->createMock(\Sabre\Event\EventEmitter::class));

        $this->server = $this->createMock(\Sabre\DAV\Server::class);
        $this->mockTree();

        $this->plugin->initialize($this->server);
    }

    private function mockTree() {
        $this->server->tree = $this->getMockBuilder('\Sabre\DAV\Tree')->disableOriginalConstructor()->getMock();
        $this->server->tree->expects($this->any())->method('nodeExists')
            ->with('/'.self::PATH)
            ->willReturn(true);
        $this->server->expects($this->any())->method('getPlugin')
            ->willReturn($this->createMock(\ESN\DAV\Sharing\Plugin::class));

        $nodeMock = $this->getMockBuilder('\Sabre\CalDAV\Calendar')
            ->disableOriginalConstructor()
            ->setMethods(array('getProperties', 'getSubscribers', 'getInvites', 'getCalendarId', 'getPublicRight'))
            ->getMock();
        $nodeMock->expects($this->any())->method('getProperties')->willReturn(array());
        $nodeMock->expects($this->any())->method('getPublicRight')->willReturn('privilege');
        $nodeMock->expects($this->any())->method('getCalendarId')->willReturn('calID');
        $nodeMock->expects($this->any())->method('getSubscribers')
            ->willReturn([
                [
                    'principaluri' => 'principals/users/1',
                    'uri' => 'uri1'
                ], [
                    'principaluri' => 'principals/users/2',
                    'uri' => 'uri2'
                ]
            ]);
        $nodeMock->expects($this->any())->method('getInvites')
            ->willReturn([
                new ShareeSimple('principal/users/3', 1, 2)
            ]);

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

    function testCalendarPublicRightUpdatedWithSubscribers() {

        $this->publisher
            ->expects($this->exactly(3))
            ->method('publish')
            ->with('calendar:calendar:updated');

        $firstExpectedData = [
            'calendarPath' => '/calendars/1/uri1',
            'calendarProps' => [
                'public_right' => 'privilege'
            ]
        ];

        $this->publisher
            ->expects($this->at(0))
            ->method('publish')
            ->with('calendar:calendar:updated', json_encode($firstExpectedData) );


        $secondfirstExpectedData = [
            'calendarPath' => '/calendars/2/uri2',
            'calendarProps' => [
                'public_right' => 'privilege'
            ]
        ];

        $this->publisher
            ->expects($this->at(1))
            ->method('publish')
            ->with('calendar:calendar:updated', json_encode($secondfirstExpectedData) );

        $thirdExpectedData = [
            'calendarPath' => '/calendars/3/uri3',
            'calendarProps' => [
                'public_right' => 'privilege'
            ]
        ];

        $this->publisher
            ->expects($this->at(2))
            ->method('publish')
            ->with('calendar:calendar:updated', json_encode($thirdExpectedData) );

        $this->plugin->updatePublicRight('/' . self::PATH);
    }

    function testCalendarPublicRightUpdatedWithoutSubscribers() {

        $this->publisher
            ->expects($this->exactly(1))
            ->method('publish')
            ->with('calendar:calendar:updated');

        $expectedData = [
            'calendarPath' => '/calendars/3/uri3',
            'calendarProps' => [
                'public_right' => 'privilege'
            ]
        ];

        $this->publisher
            ->expects($this->at(0))
            ->method('publish')
            ->with('calendar:calendar:updated', json_encode($expectedData) );

        $this->plugin->updatePublicRight('/' . self::PATH, false);
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

    function testUpdateShareesDelete() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/user/userUri', 1),
                'uri' => 'uid.ics',
                'type' => 'delete'
            ]
        ];

        $this->publisher
            ->expects($this->exactly(sizeof($calendarInstances)))
            ->method('publish');

        $this->plugin->updateSharees($calendarInstances);
    }

    function testUpdateShareesCreate() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/user/userUri', 1),
                'uri' => 'uid.ics',
                'type' => 'create'
            ]
        ];

        $this->publisher
            ->expects($this->exactly(sizeof($calendarInstances)))
            ->method('publish');

        $this->plugin->updateSharees($calendarInstances);
    }

    function testUpdateShareesUpdate() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/user/userUri', 1),
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

class CalendarBackendMock extends \ESN\CalDAV\CalDAVBackendMock {

    protected $eventEmitter;

    function getCalendarsForUser($principalUri) {
        return [
            [
                'id' => [
                    'calID',
                    'instanceID'
                ],
                'uri' => 'uri3'
            ]
        ];
    }

    function setEventEmitter($value) {
        $this->eventEmitter = $value;
    }

    function getEventEmitter() {
        return $this->eventEmitter;
    }
}
