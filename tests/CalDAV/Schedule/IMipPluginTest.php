<?php

namespace ESN\CalDAV\Schedule;

/**
 * @medium
 */
class IMipPluginTest extends \PHPUnit_Framework_TestCase {

    function setUp() {
        $this->ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:daab17fe-fac4-4946-9105-0f2cdb30f5ab',
            'SUMMARY:Hello',
            'END:VEVENT',
            'END:VCALENDAR']);
        $this->calendarId = 'calendarUUID';
    }

    private function getPlugin($sendResult = true, $findCalendarId = true, $server = null) {
        $db = new MongoDBMock($findCalendarId);
        $plugin = new IMipPluginMock("/api", $server, $db);

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

    function testCannotFindCalendarId() {
        $plugin = $this->getPlugin(false, false);
        $client = $plugin->getClient();

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:test2@example.com';
        $this->msg->method = "CANCEL";

        $plugin->schedule($this->msg);
        $this->assertEquals('5.1', $this->msg->scheduleStatus);
    }

    function testSendSuccess() {
        $plugin = $this->getPlugin(true);
        $client = $plugin->getClient();

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:test2@example.com';
        $this->msg->method = "REQUEST";

        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, &$requestCalled) {
            $jsondata = json_decode($request->getBodyAsString());
            $self->assertEquals($jsondata->method, 'REQUEST');
            $self->assertEquals($jsondata->emails, ['test2@example.com']);
            $self->assertEquals($jsondata->event, $self->ical . "\r\n");
            $self->assertEquals($jsondata->calendarId, $self->calendarId);
            $self->assertTrue($jsondata->notify);
            $requestCalled = true;
        });

        $plugin->schedule($this->msg);
        $this->assertEquals('1.2', $this->msg->scheduleStatus);
        $this->assertTrue($requestCalled);
    }

    function testSendFailed() {
        $plugin = $this->getPlugin(false, true);
        $client = $plugin->getClient();

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:test2@example.com';
        $this->msg->method = "CANCEL";

        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, &$requestCalled) {
            $jsondata = json_decode($request->getBodyAsString());
            $self->assertEquals($jsondata->method, 'CANCEL');
            $self->assertEquals($jsondata->emails, ['test2@example.com']);
            $self->assertEquals($jsondata->event, $self->ical . "\r\n");
            $self->assertEquals($jsondata->calendarId, $self->calendarId);
            $self->assertTrue($jsondata->notify);
            $requestCalled = true;
        });


        $plugin->schedule($this->msg);
        $this->assertEquals('5.1', $this->msg->scheduleStatus);
        $this->assertTrue($requestCalled);
    }
}

class MockAuthBackend {
    function getAuthCookies() {
        return "coookies!!!";
    }
}

class IMipPluginMock extends IMipPlugin {
    function __construct($apiroot, $server = null, $db) {
        require_once ESN_TEST_VENDOR . '/sabre/http/tests/HTTP/ClientTest.php';
        if (!$server) $server = new \Sabre\DAV\Server([]);
        $authBackend = new MockAuthBackend();
        parent::__construct($apiroot, $authBackend, $db);
        $this->initialize($server);
        $this->httpClient = new \Sabre\HTTP\ClientMock();
        $this->server = $server;
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

class MongoDBMock extends \MongoDB {
    function __construct($findSuccess) {
        $this->findSuccess = $findSuccess;
    }

    function selectCollection($collectionName) {
        return new CollectionMock($this->findSuccess);
    }
}

class CollectionMock {
    function __construct($findSuccess) {
        $this->findSuccess = $findSuccess;
    }

    function findOne($query) {
        if ($this->findSuccess) {
            return ['calendarid' => 'calendarUUID'];
        } else {
            return [];
        }
    }
}
