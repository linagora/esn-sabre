<?php

namespace ESN\CalDAV;

class ESNHookPluginTest extends \PHPUnit_Framework_TestCase {

    private function getPlugin($server = null) {
        $plugin = new ESNHookPluginMock("/", $server);

        $client = $plugin->getClient();
        $client->on('curlExec', function(&$return) {
            $return = "HTTP/1.1 OK\r\n\r\nOk";
        });
        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 200, 'header_size' => 0 ], 0, '' ];
        });

        return $plugin;
    }

    function testCreateFile() {
        $plugin = $this->getPlugin();
        $client = $plugin->getClient();
        $server = $plugin->getServer();

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $calendarInfo = [
            'uri' => 'calendars/123123',
            'id' => '123123',
            'principaluri' => 'principals/communities/456456'
        ];
        $parent = new \Sabre\CalDAV\Calendar(new CalDAVBackendMock(), $calendarInfo);
        $path = "calendars/123123/uid.ics";
        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, $data, &$requestCalled, $path) {
            $jsondata = json_decode($request->getBodyAsString());
            $self->assertEquals($jsondata->{'event_id'}, "/" . $path);
            $self->assertEquals($jsondata->{'type'}, "created");
            $self->assertEquals($jsondata->{'event'}, $data);
            $response = new \Sabre\HTTP\Response(200);
            $response->setBody('{ "_id": "123123" }');
            $requestCalled = true;
        });

        $this->assertTrue($server->emit('beforeCreateFile', [$path, &$data, $parent, &$modified]));
        $this->assertTrue($server->emit('afterCreateFile', [$path, $parent]));

        $this->assertTrue($requestCalled);
        $this->assertEquals($server->httpResponse->getHeader("ESN-Message-Id"), "123123");
    }

    function testCreateFileNonCalendarHome() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $parent = new \Sabre\DAV\SimpleCollection("root", []);

        $this->assertTrue($server->emit('beforeCreateFile', ["test", &$data, $parent, &$modified]));
        $this->assertTrue($server->emit('afterCreateFile', ["test", $parent]));
        $this->assertNull($plugin->getRequest());
    }

    function testWriteContent() {
        $plugin = $this->getPlugin();
        $client = $plugin->getClient();
        $server = $plugin->getServer();

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $path = "calendars/123123/uid.ics";
        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, $data, &$requestCalled, $path) {
            $jsondata = json_decode($request->getBodyAsString());
            $self->assertEquals($jsondata->{'event_id'}, "/" . $path);
            $self->assertEquals($jsondata->{'type'}, "updated");
            $self->assertEquals($jsondata->{'old_event'}, "olddata");
            $self->assertEquals($jsondata->{'event'}, $data);
            $response = new \Sabre\HTTP\Response(200);
            $response->setBody('{ "_id": "123123" }');
            $requestCalled = true;
        });

        $objectData = [
            'uri' => 'objecturi',
            'calendardata' => 'olddata'
        ];
        $calendarData = [
            'id' => '123123123',
            'principaluri' => 'principals/communities/456456456'
        ];
        $node = new \Sabre\CalDAV\CalendarObject(new CalDAVBackendMock(), $calendarData, $objectData);

        $this->assertTrue($server->emit('beforeWriteContent', [$path, $node, &$data, &$modified]));
        $this->assertTrue($server->emit('afterWriteContent', [$path, $node]));
        $this->assertTrue($requestCalled);
    }

    function testWriteContentNonACL() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $node = new \Sabre\DAV\SimpleFile("filename", "contents");

        $path = "calendars/123123/uid.ics";
        $this->assertTrue($server->emit('beforeWriteContent', [$path, $node, &$data, &$modified]));
        $this->assertTrue($server->emit('afterWriteContent', [$path, $node]));
        $this->assertNull($plugin->getRequest());
    }

    function testUnbind() {
        $data = "BEGIN:VCALENDAR";
        $path = "calendars/123123123/uid.ics";

        $objectData = [
            'uri' => 'uid.ics',
            'calendardata' => $data
        ];
        $calendarData = [
            'id' => '123123123',
            'principaluri' => 'principals/communities/456456456'
        ];
        $calendarObject = new \Sabre\CalDAV\CalendarObject(new CalDAVBackendMock(), $calendarData, $objectData);

        $parent = new \Sabre\DAV\SimpleFile("filename", "contents");
        $server = new \Sabre\DAV\Server([
            new \Sabre\DAV\SimpleCollection("calendars", [
                new \Sabre\DAV\SimpleCollection("123123123", [
                    $calendarObject
                ])
            ])
        ]);

        $plugin = $this->getPlugin($server);
        $client = $plugin->getClient();

        $modified = false;
        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, $data, $path, &$requestCalled) {
            $jsondata = json_decode($request->getBodyAsString());
            $self->assertEquals($jsondata->{'event_id'}, '/' . $path);
            $self->assertEquals($jsondata->{'type'}, "deleted");
            $self->assertEquals($jsondata->{'event'}, $data);
            $response = new \Sabre\HTTP\Response(200);
            $response->setBody('{ "_id": "123123" }');
            $requestCalled = true;
        });

        $this->assertTrue($server->emit('beforeUnbind', [$path]));
        $this->assertTrue($server->emit('afterUnbind', [$path]));
        $this->assertTrue($requestCalled);
        $this->assertEquals($server->httpResponse->getHeader("ESN-Message-Id"), "123123");
    }

    function testUnbindNonCalendarObject() {
        $data = "BEGIN:VCALENDAR";
        $path = "calendars/123123123/uid.ics";

        $parent = new \Sabre\DAV\SimpleFile("filename", "contents");
        $server = new \Sabre\DAV\Server([
            new \Sabre\DAV\SimpleCollection("calendars", [
                new \Sabre\DAV\SimpleCollection("123123123", [
                    new \Sabre\DAV\SimpleFile("uid.ics", "content")
                ])
            ])
        ]);

        $plugin = $this->getPlugin($server);
        $this->assertTrue($server->emit('beforeUnbind', [$path]));
        $this->assertTrue($server->emit('afterUnbind', [$path]));
        $this->assertNull($plugin->getRequest());
    }

    function testWithCORS() {
        $server = new \Sabre\DAV\Server([]);
        $corsplugin = new \ESN\DAV\CorsPlugin();
        $server->addPlugin($corsplugin);
        $plugin = $this->getPlugin($server);

        $this->assertContains("ESN-Message-Id",$corsplugin->exposeHeaders);
    }

    function testBogusOwner() {
        $plugin = $this->getPlugin();
        $client = $plugin->getClient();
        $server = $plugin->getServer();

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $path = "calendars/123123/uid.ics";
        $requestCalled = false;
        $self = $this;

        $objectData = [
            'uri' => 'objecturi',
            'calendardata' => 'olddata'
        ];
        $calendarData = [
            'id' => '123123123',
            'principaluri' => 'headmasters/communities/456456456'
        ];
        $node = new \Sabre\CalDAV\CalendarObject(new CalDAVBackendMock(), $calendarData, $objectData);

        $this->assertTrue($server->emit('beforeWriteContent', [$path, $node, &$data, &$modified]));
        $this->assertTrue($server->emit('afterWriteContent', [$path, $node]));
        $this->assertNull($plugin->getRequest());
    }

    function testBogusPath() {
        $plugin = $this->getPlugin();
        $client = $plugin->getClient();
        $server = $plugin->getServer();

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $path = "calendars/bogus";
        $requestCalled = false;
        $self = $this;

        $objectData = [
            'uri' => 'objecturi',
            'calendardata' => 'olddata'
        ];
        $calendarData = [
            'id' => '123123123',
            'principaluri' => 'principals/communities/456456456'
        ];
        $node = new \Sabre\CalDAV\CalendarObject(new CalDAVBackendMock(), $calendarData, $objectData);

        $this->assertTrue($server->emit('beforeWriteContent', [$path, $node, &$data, &$modified]));
        $this->assertTrue($server->emit('afterWriteContent', [$path, $node]));
        $this->assertNull($plugin->getRequest());
    }
}

class CalDAVBackendMock extends \Sabre\CalDAV\Backend\AbstractBackend {
    // @codingStandardsIgnoreStart
    function getCalendarsForUser($principalUri) { return []; }
    function createCalendar($principalUri,$calendarUri,array $properties) {}
    function deleteCalendar($calendarId) {}
    function getCalendarObjects($calendarId) { return []; }
    function getCalendarObject($calendarId,$objectUri) { return null; }
    function getMultipleCalendarObjects($calendarId, array $uris) { return []; }
    function createCalendarObject($calendarId,$objectUri,$calendarData) { return null; }
    function updateCalendarObject($calendarId,$objectUri,$calendarData) { return null; }
    function deleteCalendarObject($calendarId,$objectUri) {}
    // @codingStandardsIgnoreEnd
}


class MockAuthBackend {
    function getAuthCookies() {
        return "coookies!!!";
    }
}

class ESNHookPluginMock extends ESNHookPlugin {

    function __construct($apiroot, $server = null) {
        require_once ESN_TEST_VENDOR . '/sabre/http/tests/HTTP/ClientTest.php';
        if (!$server) $server = new \Sabre\DAV\Server([]);
        $authBackend = new MockAuthBackend();
        parent::__construct($apiroot, $authBackend);
        $this->initialize($server);
        $this->httpClient = new \Sabre\HTTP\ClientMock();
        $this->server = $server;
    }

    function getClient() {
        return $this->httpClient;
    }

    function getRequest() {
        return $this->request;
    }

    function getServer() {
        return $this->server;
    }
}
