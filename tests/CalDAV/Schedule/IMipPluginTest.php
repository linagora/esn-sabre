<?php

namespace ESN\CalDAV\Schedule;

use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Xml\Property\Href;

require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/ResponseMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/SapiMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVACL/PrincipalBackend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CalDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CardDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVServerTest.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAV/Auth/Backend/Mock.php';

/**
 * @medium
 */
class IMipPluginTest extends \PHPUnit_Framework_TestCase {

    const NAME = "calendar1";

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
        'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0e',
        'uri' => 'calendar2',
    );

    function setUp() {
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
        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId('54b64eadf6d7d8e41d263e0e'),
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                    'johndoe@example.org'
                    ]
                ]
            ],
            'domains' => []
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

        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\EsnRequest($this->esndb);
        $this->caldavBackend = new \ESN\CalDAV\Backend\Mongo($this->sabredb);
        $this->carddavBackend = new \ESN\CardDAV\Backend\Mongo($this->sabredb);

        $this->tree[] = new \Sabre\DAV\SimpleCollection('principals', [
        new \Sabre\CalDAV\Principal\Collection($this->principalBackend, 'principals/users')
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
        $aclPlugin->principalCollectionSet = ['principals', 'principals/users'];
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

        $this->calendarURI = self::NAME;
        $_SERVER["REQUEST_URI"] = "/calendars/54b64eadf6d7d8e41d263e0f/".$this->calendarURI."/171EBEFC-C951-499D-B234-7BA7D677B45D.ics";

        $this->server->exec();
    }

    private function getPlugin($sendResult = true) {
        $plugin = new IMipPluginMock("/api", $this->server, null);

        $this->msg = new \Sabre\VObject\ITip\Message();
        if ($this->ical) {
            $this->msg->message = \Sabre\VObject\Reader::read($this->ical);
        }

        $client = $plugin->getClient();
        $client->on('curlExec', function(&$return) use ($sendResult) {
            if ($sendResult) {
                $return = "HTTP/1.1 OK\r\n\r\nOk";
            } else {
                $return = "HTTP/1.1 NOT OK\r\n\r\nNot ok";
            }
        });
        $client->on('curlStuff', function(&$return) use ($sendResult) {
            if ($sendResult) {
                $return = [ [ 'http_code' => 200, 'header_size' => 0 ], 0, '' ];
            } else {
                $return = [ [ 'http_code' => 503, 'header_size' => 0 ], 0, '' ];
            }
        });

        return $plugin;
    }

    function testScheduleNoconfig() {
        $plugin = $this->getPlugin();
        $plugin->setApiRoot(null);
        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, '5.2');
        return $plugin;
    }

    function testScheduleNotSignificant() {
        $plugin = $this->getPlugin();
        $this->msg->significantChange = false;
        $this->msg->hasChange = false;

        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, '1.0');
    }

    function testNotMailto() {
        $plugin = $this->getPlugin();
        $this->msg->sender = 'http://example.com';
        $this->msg->recipient = 'http://example.com';
        $this->msg->scheduleStatus = 'unchanged';

        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, 'unchanged');

        $this->msg->sender = 'mailto:valid';

        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, 'unchanged');
    }

    function testCannotFindRecipient() {
        $plugin = $this->getPlugin(false);
        $client = $plugin->getClient();

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:unknown@example.org';
        $this->msg->method = "CANCEL";

        $plugin->schedule($this->msg);
        $this->assertEquals('5.1', $this->msg->scheduleStatus);
    }

    function testCannotFindCalendarObject() {
        $plugin = $this->getPlugin(false);
        $client = $plugin->getClient();

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:johndoe@example.org';
        $this->msg->method = "CANCEL";
        $this->msg->uid = "fakeUid";

        $plugin->schedule($this->msg);
        $this->assertEquals('5.1', $this->msg->scheduleStatus);
    }

    function testSendSuccess() {
        $plugin = $this->getPlugin();
        $client = $plugin->getClient();

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:johndoe@example.org';
        $this->msg->method = "REQUEST";
        $this->msg->uid = "daab17fe-fac4-4946-9105-0f2cdb30f5ab";

        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, &$requestCalled) {
            $jsondata = json_decode($request->getBodyAsString());
            $self->assertEquals($jsondata->method, 'REQUEST');
            $self->assertEquals($jsondata->email, 'johndoe@example.org');
            $self->assertEquals($jsondata->event, $self->ical);
            $self->assertEquals($jsondata->calendarURI, $self->calendarURI);
            $self->assertTrue($jsondata->notify);
            $requestCalled = true;
        });

        $plugin->schedule($this->msg);
        $this->assertEquals('1.2', $this->msg->scheduleStatus);
        $this->assertTrue($requestCalled);
    }

    function testSendFailed() {
        $plugin = $this->getPlugin(false);
        $client = $plugin->getClient();

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:johndoe@example.org';
        $this->msg->method = "CANCEL";
        $this->msg->uid = "daab17fe-fac4-4946-9105-0f2cdb30f5ab";

        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, &$requestCalled) {
            $jsondata = json_decode($request->getBodyAsString());
            $self->assertEquals($jsondata->method, 'CANCEL');
            $self->assertEquals($jsondata->email, 'johndoe@example.org');
            $self->assertEquals($jsondata->event, $self->ical);
            $self->assertEquals($jsondata->calendarURI, $self->calendarURI);
            $self->assertTrue($jsondata->notify);
            $requestCalled = true;
        });

        $plugin->schedule($this->msg);
        $this->assertEquals('5.1', $this->msg->scheduleStatus);
        $this->assertTrue($requestCalled);
    }

    function testSendRecToOpUser() {
        $plugin = $this->getPlugin();
        $client = $plugin->getClient();

        $this->msg->message = \Sabre\VObject\Reader::read($this->icalRec);
        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:johndoe@example.org';
        $this->msg->method = "REQUEST";
        $this->msg->uid = "e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1550";

        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, &$requestCalled) {
            $jsondata = json_decode($request->getBodyAsString());
            $self->assertEquals($jsondata->method, 'REQUEST');
            $self->assertEquals($jsondata->email, 'johndoe@example.org');
            $self->assertEquals($jsondata->event, $self->icalRec);
            $self->assertEquals($jsondata->calendarURI, $self->calendarURI);
            $self->assertTrue($jsondata->notify);
            $requestCalled = true;
        });

        $plugin->schedule($this->msg);
        $this->assertEquals('1.2', $this->msg->scheduleStatus);
        $this->assertTrue($requestCalled);
    }

    function testSendRecToExternalUser() {
        $messages[] = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1550',
            'DTSTART:20180305T120000Z',
            'DTEND:20180305T130000Z',
            'SUMMARY:Lunch',
            'RRULE:FREQ=DAILY;COUNT=8',
            'END:VEVENT',
            'END:VCALENDAR',
            '']);

        $messages[] = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1550',
            'DTSTART:20180306T120000Z',
            'DTEND:20180306T140000Z',
            'SUMMARY:Lunch',
            'RECURRENCE-ID:20180306T120000Z',
            'END:VEVENT',
            'END:VCALENDAR',
            '']);

        $plugin = $this->getPlugin();
        $client = $plugin->getClient();

        $this->msg->message = \Sabre\VObject\Reader::read($this->icalRec);
        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:johndoe@other.org';
        $this->msg->method = "REQUEST";
        $this->msg->uid = "e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1550";

        $timesCalled = 0;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, &$timesCalled, $messages) {
            $jsondata = json_decode($request->getBodyAsString());
            $self->assertEquals($jsondata->method, 'REQUEST');
            $self->assertEquals($jsondata->email, 'johndoe@other.org');
            $self->assertEquals($jsondata->calendarURI, $self->calendarURI);
            $self->assertTrue($jsondata->notify);
            $self->assertEquals($jsondata->event, $messages[$timesCalled]);

            $timesCalled++;
        });

        $plugin->schedule($this->msg);
        $this->assertEquals('1.2', $this->msg->scheduleStatus);
        $this->assertEquals(2, $timesCalled);
    }
}

class MockAuthBackend {
    function getAuthCookies() {
        return "coookies!!!";
    }
}

class IMipPluginMock extends IMipPlugin {
    function __construct($apiroot, $server, $db) {
        require_once ESN_TEST_VENDOR . '/sabre/http/tests/HTTP/ClientTest.php';
        $authBackend = new MockAuthBackend();
        parent::__construct($apiroot, $authBackend, $db);
        $this->initialize($server);
        $this->httpClient = new \Sabre\HTTP\ClientMock();
    }

    function setApiRoot($val) {
        $this->apiroot = $val;
    }

    function getClient() {
        return $this->httpClient;
    }

    function getServer() {
        return $this->server;
    }
}