<?php
namespace ESN\Publisher;

require_once ESN_TEST_BASE . '/CalDAV/MockUtils.php';

class NewRealTimeTest extends \PHPUnit_Framework_TestCase {

    const PATH = "calendars/123123/uid.ics";
    const ETAG = 'The etag';

    private function mockTree($server) {
        $server->tree = $this->getMockBuilder('\Sabre\DAV\Tree')->disableOriginalConstructor()->getMock();
        $server->tree->expects($this->any())->method('nodeExists')
            ->with('/'.self::PATH)
            ->willReturn(true);

        $nodeMock = $this->getMockBuilder('\Sabre\CalDAV\Calendar')->disableOriginalConstructor()->getMock();
        $nodeMock->expects($this->any())->method('getETag')->willReturn(self::ETAG);
        $nodeMock->expects($this->any())->method('getProperties')->willReturn(array());

        $server->tree->expects($this->any())->method('getNodeForPath')
            ->with('/'.self::PATH)
            ->will($this->returnValue($nodeMock));
    }

    function testEventEmitterListening() {
        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $eventEmitter = $plugin->getEventEmitter();
        $server = $plugin->getServer();
        $this->mockTree($server);

        $this->assertTrue($eventEmitter->emit('esn:calendarCreated', ['/' . self::PATH]));
        $this->assertTrue($eventEmitter->emit('esn:calendarUpdated', ['/' . self::PATH]));
        $this->assertTrue($eventEmitter->emit('esn:calendarDeleted', ['/' . self::PATH]));
        $this->assertTrue($eventEmitter->emit('esn:updateSharees', [[]]));
    }

    function testBuildCalendarBody() {
        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $plugin->buildCalendarBody('calendarPath', 'type', 'calendarProps');

        $body = $plugin->getBody();
        $this->assertEquals($body['calendarPath'], 'calendarPath');
        $this->assertEquals($body['type'], 'type');
        $this->assertEquals($body['calendarProps'], 'calendarProps');
    }

    function testCreateCalendarMessage() {
        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $plugin->buildCalendarBody('calendarPath1', 'type1', 'calendarProps1');
        $plugin->createCalendarMessage('topic1');
        $body1 = $plugin->getBody();

        $plugin->buildCalendarBody('calendarPath2', 'type2', 'calendarProps2');
        $plugin->createCalendarMessage('topic2');
        $body2 = $plugin->getBody();

        $plugin->buildCalendarBody('calendarPath3', 'type3', 'calendarProps3');
        $plugin->createCalendarMessage('topic3');
        $body3 = $plugin->getBody();

        $messages = $plugin->getMessages();

        $this->assertTrue(is_array($messages));
        $this->assertEquals(sizeof($messages), 3);

        $this->assertEquals($messages[0]['topic'], 'topic1');
        $this->assertEquals($messages[0]['data'], $body1);
        $this->assertEquals($messages[1]['topic'], 'topic2');
        $this->assertEquals($messages[1]['data'], $body2);
        $this->assertEquals($messages[2]['topic'], 'topic3');
        $this->assertEquals($messages[2]['data'], $body3);
    }

    function testCreateCalendarMessageEmptyBody() {
        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $this->assertTrue(empty($plugin->getMessages()));
        $plugin->createCalendarMessage('topic');
        $this->assertTrue(empty($plugin->getMessages()));
    }

    function testGetCalendarProps() {
        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $server = $plugin->getServer();

        $this->mockTree($server);
        $node = $server->tree->getNodeForPath('/' . self::PATH);

        $this->assertTrue(is_array($plugin->getCalendarProps($node)));
    }

    function testPrepareAndPublishMessage() {
        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $path = 'path';
        $type = 'type';
        $props = 'props';
        $topic = 'topic';
        $plugin->prepareAndPublishMessage($path, $type, $props, $topic);

        $client = $plugin->getClient();
        $message = json_encode([
            'calendarPath' => $path,
            'type' => $type,
            'calendarProps' => $props
        ]);

        $this->assertEquals($client->topic, $topic);
        $this->assertEquals($client->message, $message);
    }

    function testCalendarCreated() {
        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $server = $plugin->getServer();
        $this->mockTree($server);

        $plugin->calendarCreated('/' . self::PATH);

        $client = $plugin->getClient();
        $this->assertEquals($client->topic, 'calendar:calendar:created');

        $message = json_decode($client->message);
        $this->assertEquals($message->calendarPath, '/' . self::PATH);
        $this->assertEquals($message->type, 'created');
    }

    function testCalendarDeleted() {
        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $server = $plugin->getServer();
        $this->mockTree($server);

        $plugin->calendarDeleted('/' . self::PATH);

        $client = $plugin->getClient();
        $this->assertEquals($client->topic, 'calendar:calendar:deleted');

        $message = json_decode($client->message);
        $this->assertEquals($message->calendarPath, '/' . self::PATH);
        $this->assertEquals($message->type, 'deleted');
        $this->assertNull($message->calendarProps);
    }

    function testCalendarUpdated() {
        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $server = $plugin->getServer();
        $this->mockTree($server);

        $plugin->calendarUpdated('/' . self::PATH);

        $client = $plugin->getClient();
        $this->assertEquals($client->topic, 'calendar:calendar:updated');

        $message = json_decode($client->message);
        $this->assertEquals($message->calendarPath, '/' . self::PATH);
        $this->assertEquals($message->type, 'updated');
    }

    function testUpdateMultipleSharees() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('/principal/user/userUri1', 1),
                'uri' => 'uid.ics',
                'type' => 'delete'
            ],
            [
                'sharee' => new ShareeSimple('/principal/user/userUri1', 1),
                'uri' => 'uid.ics',
                'type' => 'create'
            ],
            [
                'sharee' => new ShareeSimple('/principal/user/userUri1', 1),
                'uri' => 'uid.ics',
                'type' => 'update'
            ]
        ];

        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $server = $plugin->getServer();
        $server->addPlugin(new \ESN\DAV\Sharing\Plugin());

        $plugin->updateSharees($calendarInstances);

        $client = $plugin->getClient();
        $this->assertEquals($client->messagesSent, sizeof($calendarInstances));
    }

    function testUpdateShareesDelete() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/user/userUri', 1),
                'uri' => 'uid.ics',
                'type' => 'delete'
            ]
        ];

        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $server = $plugin->getServer();
        $server->addPlugin(new \ESN\DAV\Sharing\Plugin());

        $plugin->updateSharees($calendarInstances);

        $client = $plugin->getClient();
        $this->assertEquals($client->messagesSent, sizeof($calendarInstances));
        $this->assertEquals($client->topic, 'calendar:calendar:deleted');
        $message = json_decode($client->message);
        $this->assertEquals($message->calendarPath, '/calendars/userUri/uid.ics');
        $this->assertEquals($message->type, 'delete');
        $this->assertNull($message->calendarProps);
    }

    function testUpdateShareesCreate() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/user/userUri', 1),
                'uri' => 'uid.ics',
                'type' => 'create'
            ]
        ];

        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $server = $plugin->getServer();
        $server->addPlugin(new \ESN\DAV\Sharing\Plugin());

        $plugin->updateSharees($calendarInstances);

        $client = $plugin->getClient();
        $this->assertEquals($client->messagesSent, sizeof($calendarInstances));
        $this->assertEquals($client->topic, 'calendar:calendar:created');
        $message = json_decode($client->message);
        $this->assertEquals($message->calendarPath, '/calendars/userUri/uid.ics');
        $this->assertEquals($message->type, 'create');

        $sharingPlugin = $server->getPlugin('sharing');
        $props = (object) [
            'access' => $sharingPlugin->accessToRightRse(1)
        ];
        $this->assertEquals($message->calendarProps, $props);
    }

    function testUpdateShareesUpdate() {
        $calendarInstances = [
            [
                'sharee' => new ShareeSimple('principal/user/userUri', 1),
                'uri' => 'uid.ics',
                'type' => 'update'
            ]
        ];

        $plugin = new NewRealTimeMock(new \ESN\CalDAV\CalDAVBackendMock());
        $server = $plugin->getServer();
        $server->addPlugin(new \ESN\DAV\Sharing\Plugin());

        $plugin->updateSharees($calendarInstances);

        $client = $plugin->getClient();
        $this->assertEquals($client->messagesSent, sizeof($calendarInstances));
        $this->assertEquals($client->topic, 'calendar:calendar:updated');
        $message = json_decode($client->message);
        $this->assertEquals($message->calendarPath, '/calendars/userUri/uid.ics');
        $this->assertEquals($message->type, 'update');

        $sharingPlugin = $server->getPlugin('sharing');
        $props = (object) [
            'access' => $sharingPlugin->accessToRightRse(1)
        ];
        $this->assertEquals($message->calendarProps, $props);
    }
}

class ShareeSimple {
    public $principal;
    public $access;

    function __construct($principal, $access) {
        $this->principal = $principal;
        $this->access = $access;
    }
}

class RealTimeMock2 implements \ESN\Publisher\Publisher {
    public $topic;
    public $message;
    public $messagesSent = 0;

    function publish($topic, $message) {
        $this->topic = $topic;
        $this->message = $message;
        $this->messagesSent++;
    }
}

class NewRealTimeMock extends NewRealTime {

    function __construct($backend) {
        $this->server = new \Sabre\DAV\Server([]);
        $this->eventEmitter = new \Sabre\Event\EventEmitter();
        $this->initialize($this->server);
        $this->client = new RealTimeMock2();
    }

    function getEventEmitter() {
        return $this->eventEmitter;
    }

    function getClient() {
        return $this->client;
    }

    function getMessages() {
        return $this->messages;
    }

    function getServer() {
        return $this->server;
    }

    function getBody() {
        return $this->body;
    }

    public function createCalendarMessage($topic) {
        parent::createCalendarMessage($topic);
    }

    function getTopics() {
        return $this->CALENDAR_TOPICS;
    }
}