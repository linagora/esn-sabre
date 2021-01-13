<?php
namespace ESN\Publisher\CalDAV;
use Sabre\DAV\ServerPlugin;
use \Sabre\CalDAV\Schedule\IMipPlugin;

require_once ESN_TEST_BASE . '/CalDAV/MockUtils.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/ResponseMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/SapiMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVACL/PrincipalBackend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CalDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CardDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVServerTest.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAV/Auth/Backend/Mock.php';

class EventRealTimePluginTest extends \PHPUnit_Framework_TestCase {

    const PATH = "calendars/456456/123123/uid.ics";
    const PARENT = 'calendars/456456/123123';
    const ETAG = 'The etag';

        protected $caldavCalendar = array(
        '{DAV:}displayname' => 'Calendar',
        '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
        '{http://apple.com/ns/ical/}calendar-color' => '#0190FFFF',
        '{http://apple.com/ns/ical/}calendar-order' => '2',
        'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
        'uri' => 'calendar1',
    );

    protected $caldavCalendarUser2 = array(
        '{DAV:}displayname' => 'Calendar',
        '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
        '{http://apple.com/ns/ical/}calendar-color' => '#0190FFFF',
        '{http://apple.com/ns/ical/}calendar-order' => '2',
        'principaluri' => 'principals/resources/54b64eadf6d7d8e41d263e0e',
        'uri' => 'calendar2',
    );

    private $icalData;

    private function prepare() {
        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->{ESN_MONGO_ESNDB};

        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->sabredb->drop();
        $this->esndb->drop();

        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId('54b64eadf6d7d8e41d263e0f'),
            'firstname' => 'Roberto',
            'lastname' => 'Carlos',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                    'robertocarlos@realmadrid.com'
                    ]
                ]
            ],
            'domains' => []
        ]);
        $this->esndb->resources->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId('54b64eadf6d7d8e41d263e0e'),
            'type' => 'calendar',
            'name' => 'cal resource',
            "domain" => ''
        ]);

        $this->ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:daab17fe-fac4-4946-9105-0f2cdb30f5ab',
            'SUMMARY:Hello',
            'DTSTART:20150228T030000Z',
            'DTEND:20150228T040000Z',
            'END:VEVENT',
            'END:VCALENDAR',
            '']);

        $this->icalRec = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1550',
            'DTSTART:20180305T120000Z',
            'DTEND:20180305T130000Z',
            'SUMMARY:Lunch',
            'RRULE:FREQ=DAILY;COUNT=8',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1550',
            'DTSTART:20180306T120000Z',
            'DTEND:20180306T140000Z',
            'SUMMARY:Lunch',
            'RECURRENCE-ID:20180306T120000Z',
            'END:VEVENT',
            'END:VCALENDAR',
            '']);

        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);
        $this->caldavBackend = new \ESN\CalDAV\Backend\Mongo($this->sabredb);
        $this->carddavBackend = new \ESN\CardDAV\Backend\Mongo($this->sabredb);
        $this->tree[] = new \Sabre\DAV\SimpleCollection('principals', [
          new \Sabre\CalDAV\Principal\Collection($this->principalBackend, 'principals/users'),
          new \Sabre\CalDAV\Principal\Collection($this->principalBackend, 'principals/resources'),
          new \Sabre\CalDAV\Principal\Collection($this->principalBackend, 'principals/domains')
        ]);
        $this->tree[] = new \ESN\CalDAV\CalendarRoot(
            $this->principalBackend,
            $this->caldavBackend,
            $this->esndb
        );

        $this->server = new \Sabre\DAV\Server($this->tree);
        $this->server->sapi = new \Sabre\HTTP\SapiMock();
        $this->server->debugExceptions = true;

        $caldavPlugin = new \ESN\CalDAV\Plugin();
        $this->server->addPlugin($caldavPlugin);

        $this->server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
        $this->server->addPlugin(new \Sabre\CalDAV\SharingPlugin());

        $authBackend = new \Sabre\DAV\Auth\Backend\Mock();
        $authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0f');
        $authPlugin = new \Sabre\DAV\Auth\Plugin($authBackend);
        $this->server->addPlugin($authPlugin);

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals', 'principals/users', 'principals/resources'];
        $this->server->addPlugin($aclPlugin);

        $this->caldavSchedulePlugin = new \ESN\CalDAV\Schedule\Plugin();
        $this->server->addPlugin($this->caldavSchedulePlugin);

        $this->cal = $this->caldavCalendar;
        $this->cal['id'] = $this->caldavBackend->createCalendar($this->cal['principaluri'], $this->cal['uri'], $this->cal);
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'simple.ics', $this->ical);
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'rec.ics', $this->icalRec);

        $this->calUser2 = $this->caldavCalendarUser2;
        $this->calUser2['id'] = $this->caldavBackend->createCalendar($this->calUser2['principaluri'], $this->calUser2['uri'], $this->calUser2);
        $this->caldavBackend->createCalendarObject($this->calUser2['id'], 'simple.ics', $this->ical);

        $this->server->exec();
    }

    private function getPlugin($server = null) {
        $plugin = new EventRealTimePluginMock($server, new \ESN\CalDAV\CalDAVBackendMock());
        $server = $plugin->getServer();
        $this->mockTree($server);
        $this->icalData = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:a18225bc-3bfb-4e2a-a5f1-711c8d9cf531\r\nTRANSP:OPAQUE\r\nDTSTART;TZID=Europe/Berlin:20160209T113000\r\nDTEND;TZID=Europe/Berlin:20160209T140000\r\nSUMMARY:test\r\nORGANIZER;CN=admin admin:mailto:admin@open-paas.org\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        return $plugin;
    }

    private function mockTree($server) {
        $server->tree = $this->getMockBuilder('\Sabre\DAV\Tree')->disableOriginalConstructor()->getMock();
        $server->tree->expects($this->any())->method('nodeExists')
            ->with('/'.self::PATH)
            ->willReturn(true);

        $nodeMock = $this->getMockBuilder('\Sabre\DAV\File')->getMock();
        $nodeMock->expects($this->any())->method('getETag')->willReturn(self::ETAG);

        $server->tree->expects($this->any())->method('getNodeForPath')
            ->will($this->returnValue($nodeMock));
    }

    function testCreateFileNonCalendarHome() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $modified = false;
        $parent = new \Sabre\DAV\SimpleCollection("root", []);

        $this->assertTrue($server->emit('beforeCreateFile', ["test", &$this->icalData, $parent, &$modified]));
        $this->assertTrue($server->emit('afterCreateFile', ["test", $parent]));
        $this->assertNull($client->message);
    }

    function testCreateFileEvent() {
        $calendarInfo = [
            'uri' => 'calendars/456456/123123',
            'id' => '123123',
            'principaluri' => 'principals/users/456456'
        ];

        $parent = new \ESN\CalDAV\SharedCalendar(new \Sabre\CalDAV\SharedCalendar(new \ESN\CalDAV\CalDAVBackendMock(), $calendarInfo));

        $server = new \Sabre\DAV\Server([
            new \Sabre\DAV\SimpleCollection("calendars", [
                $parent
            ])
        ]);

        $plugin = $this->getPlugin($server);
        $client = $plugin->getClient();

        $this->assertTrue($server->emit('beforeCreateFile', ["calendars/456456/123123/uid.ics", &$this->icalData, $parent, &$modified]));
        $this->assertTrue($server->emit('afterCreateFile', ["/calendars/456456/123123/uid.ics", $parent]));
        $this->assertNotNull($client->message);
    }

    function testUnbindNonCalendarObject() {
        $data = "BEGIN:VCALENDAR";

        $parent = new \Sabre\DAV\SimpleFile("filename", "contents");
        $server = new \Sabre\DAV\Server([
            new \Sabre\DAV\SimpleCollection("calendars", [
                new \Sabre\DAV\SimpleCollection("123123", [
                    new \Sabre\DAV\SimpleFile("uid.ics", "content")
                ])
            ])
        ]);

        $plugin = $this->getPlugin($server);
        $client = $plugin->getClient();
        $this->assertTrue($server->emit('beforeUnbind', [self::PATH]));
        $this->assertTrue($server->emit('afterUnbind', [self::PATH]));
        $this->assertNull($client->message);
    }

    function testItipDoSendMessageIfScheduleFail() {
        $plugin = $this->getMockBuilder(EventRealTimePlugin::class)
            ->setMethods(['publishMessages'])
            ->setConstructorArgs(['', new \ESN\CalDAV\CalDAVBackendMock()])
            ->getMock();
        $plugin->expects($this->never())->method('publishMessages');

        $message = new \Sabre\VObject\ITip\Message();
        $message->scheduleStatus = \ESN\CalDAV\Schedule\IMipPlugin::SCHEDSTAT_FAIL_TEMPORARY;

        $plugin->itip($message);

        $this->verifyMockObjects();
    }

    function testItipDelegateToScheduleAndPublishMessage() {
        $plugin = $this->getMockBuilder(EventRealTimePlugin::class)
            ->setMethods(['schedule', 'publishMessages'])
            ->setConstructorArgs(['', new \ESN\CalDAV\CalDAVBackendMock()])
            ->getMock();
        $plugin->expects($this->once())->method('schedule')->will($this->returnCallback(function($message) {
            $this->assertInstanceOf(\Sabre\VObject\ITip\Message::class, $message);

            return $message;
        }));
        $plugin->expects($this->once())->method('publishMessages');

        $plugin->itip(new \Sabre\VObject\ITip\Message());
        $this->verifyMockObjects();
    }

    function testNoResourceCreationMessageWhenNoSignificantChange() {
        $this->prepare();

        $plugin = $this->getMockBuilder(EventRealTimePluginMock::class)
            ->setMethods(['notifyInvites', 'createMessage'])
            ->setConstructorArgs([$this->server,  $this->caldavBackend])
            ->getMock();

        $plugin->expects($this->any())
            ->method('createMessage')
            ->willReturnCallback(function() {
                $args = func_get_args();
                
                $this->assertNotEquals('resource:calendar:event:created', $args[0]);
            });
        
        $message = new \Sabre\VObject\ITip\Message();
        $message->method = 'REQUEST';
        $message->significantChange = false;
        $message->recipient = 'mailto:54b64eadf6d7d8e41d263e0e@example.com';
        $message->uid = "daab17fe-fac4-4946-9105-0f2cdb30f5ab";

        $plugin->itip($message);
    }

    function testBuildData() {
        $plugin = $this->getPlugin();
        $data = $plugin->buildData([
            'eventPath' => '/'.self::PATH,
            'event' => 'event'
        ]);

        $this->assertEquals($data['eventPath'], '/'.self::PATH);
        $this->assertEquals($data['etag'], self::ETAG);
        $this->assertEquals($data['event'], 'event');
    }

    function testBuildDataWithSource() {
        $path = '/path/for/calendar/event.ics';
        $plugin = $this->getPlugin();
        $data = $plugin->buildData([
            'eventPath' => $path,
            'eventSourcePath' => self::PATH,
            'event' => 'event'
        ]);

        $this->assertEquals($data['eventPath'], $path);
        $this->assertEquals($data['eventSourcePath'], self::PATH);
        $this->assertEquals($data['etag'], self::ETAG);
        $this->assertEquals($data['event'], 'event');
    }

}

class ClientMock implements \ESN\Publisher\Publisher {
    public $topic;
    public $message;

    function publish($topic, $message) {
        $this->topic = $topic;
        $this->message = $message;
    }
}

class EventRealTimePluginMock extends EventRealTimePlugin {

    function __construct($server, $backend) {
        if (!$server) $server = new \Sabre\DAV\Server([]);
        $this->initialize($server);
        $this->client = new ClientMock();
        parent::__construct($this->client, $backend);
        $this->server = $server;
    }

    function getClient() {
        return $this->client;
    }

    function getMessage() {
        return $this->message;
    }

    function getServer() {
        return $this->server;
    }

    protected function notifyInvites($invites, $dataMessage, $options) {
        return false;
    }
}
