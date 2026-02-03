<?php
namespace ESN\Publisher\CalDAV;

require_once ESN_TEST_BASE . '/CalDAV/MockUtils.php';

#[\AllowDynamicProperties]
class CalendarRealTimePluginTest extends \PHPUnit\Framework\TestCase {

    const PATH = "calendars/123123/uid.ics";
    protected $eventEmitter;
    protected $plugin;
    protected $publisher;
    protected $server;

    function setUp(): void {
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

        $nodeMock = new class extends \Sabre\CalDAV\Calendar {
            public function __construct() {}
            public function getProperties($properties) { return array(); }
            public function getPublicRight() { return 'privilege'; }
            public function getCalendarId() { return 'calID'; }
            public function getSubscribers() {
                return [
                    [
                        'principaluri' => 'principals/users/1',
                        'uri' => 'uri1'
                    ], [
                        'principaluri' => 'principals/users/2',
                        'uri' => 'uri2'
                    ]
                ];
            }
            public function getInvites() {
                return [
                    new ShareeSimple('principal/users/3', 1, 2)
                ];
            }
        };

        $this->server->tree->expects($this->any())->method('getNodeForPath')
            ->with('/'.self::PATH)
            ->willReturn($nodeMock);
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

        $firstExpectedData = [
            'calendarPath' => '/calendars/1/uri1',
            'calendarProps' => [
                'public_right' => 'privilege'
            ]
        ];

        $secondfirstExpectedData = [
            'calendarPath' => '/calendars/2/uri2',
            'calendarProps' => [
                'public_right' => 'privilege'
            ]
        ];

        $thirdExpectedData = [
            'calendarPath' => '/calendars/3/uri3',
            'calendarProps' => [
                'public_right' => 'privilege'
            ]
        ];

        $expectedCalls = [
            ['calendar:calendar:updated', json_encode($firstExpectedData)],
            ['calendar:calendar:updated', json_encode($secondfirstExpectedData)],
            ['calendar:calendar:updated', json_encode($thirdExpectedData)]
        ];
        $callIndex = 0;

        $this->publisher
            ->expects($this->exactly(3))
            ->method('publish')
            ->willReturnCallback(function($topic, $data) use (&$expectedCalls, &$callIndex) {
                $this->assertEquals($expectedCalls[$callIndex][0], $topic);
                $this->assertEquals($expectedCalls[$callIndex][1], $data);
                $callIndex++;
            });

        $this->plugin->updatePublicRight('/' . self::PATH);
    }

    function testCalendarPublicRightUpdatedWithoutSubscribers() {

        $expectedData = [
            'calendarPath' => '/calendars/3/uri3',
            'calendarProps' => [
                'public_right' => 'privilege'
            ]
        ];

        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('calendar:calendar:updated', json_encode($expectedData));

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

    function testUpdateShareesPublishesSourceDelegationUpdate() {
        // Given: a sharee update with a source calendar path.
        $sharingPlugin = $this->createMock(\ESN\DAV\Sharing\Plugin::class);
        $sharingPlugin->method('accessToRightRse')->willReturn('dav:read');
        $server = $this->createMock(\Sabre\DAV\Server::class);
        $server->method('getPlugin')->with('sharing')->willReturn($sharingPlugin);
        $this->plugin->initialize($server);

        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/users/alice', \Sabre\DAV\Sharing\Plugin::ACCESS_READ),
                'uri' => 'uid-alice',
                'type' => 'update',
                'sourceCalendarPath' => '/calendars/bob/bobcal'
            ]
        ];

        // When/Then: publish sharee update plus a delegation update for the source calendar.
        $expectedCalls = [
            ['calendar:calendar:updated', json_encode([
                'calendarPath' => '/calendars/alice/uid-alice',
                'calendarProps' => ['access' => 'dav:read']
            ])],
            ['calendar:calendar:updated', json_encode([
                'calendarPath' => '/calendars/bob/bobcal',
                'calendarProps' => ['delegation_updated' => true]
            ])]
        ];
        $callIndex = 0;

        $this->publisher
            ->expects($this->exactly(2))
            ->method('publish')
            ->willReturnCallback(function($topic, $data) use (&$expectedCalls, &$callIndex) {
                $this->assertEquals($expectedCalls[$callIndex][0], $topic);
                $this->assertEquals($expectedCalls[$callIndex][1], $data);
                $callIndex++;
            });

        $this->plugin->updateSharees($calendarInstances);
    }

    function testUpdateShareesDedupesSourceDelegationUpdate() {
        // Given: multiple sharee updates for the same source calendar.
        $sharingPlugin = $this->createMock(\ESN\DAV\Sharing\Plugin::class);
        $sharingPlugin->method('accessToRightRse')->willReturn('dav:read');
        $server = $this->createMock(\Sabre\DAV\Server::class);
        $server->method('getPlugin')->with('sharing')->willReturn($sharingPlugin);
        $this->plugin->initialize($server);

        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/users/alice', \Sabre\DAV\Sharing\Plugin::ACCESS_READ),
                'uri' => 'uid-alice',
                'type' => 'update',
                'sourceCalendarPath' => '/calendars/bob/bobcal'
            ],
            [
                'sharee' => new ShareeSimple('principal/users/cedric', \Sabre\DAV\Sharing\Plugin::ACCESS_READ),
                'uri' => 'uid-cedric',
                'type' => 'update',
                'sourceCalendarPath' => '/calendars/bob/bobcal'
            ]
        ];

        // When/Then: publish each sharee update but only one source delegation update.
        $expectedCalls = [
            ['calendar:calendar:updated', json_encode([
                'calendarPath' => '/calendars/alice/uid-alice',
                'calendarProps' => ['access' => 'dav:read']
            ])],
            ['calendar:calendar:updated', json_encode([
                'calendarPath' => '/calendars/bob/bobcal',
                'calendarProps' => ['delegation_updated' => true]
            ])],
            ['calendar:calendar:updated', json_encode([
                'calendarPath' => '/calendars/cedric/uid-cedric',
                'calendarProps' => ['access' => 'dav:read']
            ])]
        ];
        $callIndex = 0;

        $this->publisher
            ->expects($this->exactly(3))
            ->method('publish')
            ->willReturnCallback(function($topic, $data) use (&$expectedCalls, &$callIndex) {
                $this->assertEquals($expectedCalls[$callIndex][0], $topic);
                $this->assertEquals($expectedCalls[$callIndex][1], $data);
                $callIndex++;
            });

        $this->plugin->updateSharees($calendarInstances);
    }

    function testUpdateShareesDeletePublishesSourceDelegationUpdate() {
        // Given: a sharee delete with a source calendar path.
        $sharingPlugin = $this->createMock(\ESN\DAV\Sharing\Plugin::class);
        $server = $this->createMock(\Sabre\DAV\Server::class);
        $server->method('getPlugin')->with('sharing')->willReturn($sharingPlugin);
        $this->plugin->initialize($server);

        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/users/alice', 1),
                'uri' => 'uid-alice',
                'type' => 'delete',
                'sourceCalendarPath' => '/calendars/bob/bobcal'
            ]
        ];

        // When/Then: publish delete for the sharee and delegation update for the source.
        $expectedCalls = [
            ['calendar:calendar:deleted', json_encode([
                'calendarPath' => '/calendars/alice/uid-alice',
                'calendarProps' => null
            ])],
            ['calendar:calendar:updated', json_encode([
                'calendarPath' => '/calendars/bob/bobcal',
                'calendarProps' => ['delegation_updated' => true]
            ])]
        ];
        $callIndex = 0;

        $this->publisher
            ->expects($this->exactly(2))
            ->method('publish')
            ->willReturnCallback(function($topic, $data) use (&$expectedCalls, &$callIndex) {
                $this->assertEquals($expectedCalls[$callIndex][0], $topic);
                $this->assertEquals($expectedCalls[$callIndex][1], $data);
                $callIndex++;
            });

        $this->plugin->updateSharees($calendarInstances);
    }
}

#[\AllowDynamicProperties]
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
