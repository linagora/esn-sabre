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
            'UID:123123',
            'SUMMARY:Hello',
            'END:VEVENT',
            'END:VCALENDAR']);
    }

    private function getPlugin($sendResult = true, $server = null) {
        $plugin = new IMipPluginMock("/api", $server);

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
        $this->msg->recipient = 'mailto:test2@example.com';
        $this->msg->method = "CANCEL";

        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, &$requestCalled) {
            $jsondata = json_decode($request->getBodyAsString());
            $self->assertEquals($jsondata->method, 'CANCEL');
            $self->assertEquals($jsondata->emails, ['test2@example.com']);
            $self->assertEquals($jsondata->event, $self->ical . "\r\n");
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
    function __construct($apiroot, $server = null) {
        require_once ESN_TEST_VENDOR . '/sabre/http/tests/HTTP/ClientTest.php';
        if (!$server) $server = new \Sabre\DAV\Server([]);
        $authBackend = new MockAuthBackend();
        parent::__construct($apiroot, $authBackend);
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
