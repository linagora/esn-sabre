<?php

namespace ESN\CalDAV;

class ESNHookPluginTest extends \PHPUnit_Framework_TestCase {

    private function getPlugin($server = null) {
        $plugin = new ESNHookPluginMock("/", "principals", $server);

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

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $parent = new \Sabre\CalDAV\CalendarHome(new CalDAVBackendMock(), null);
        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, $data, &$requestCalled) {
            $jsondata = json_decode($request->getBodyAsString());
            $self->assertEquals($jsondata->{'event_id'}, "test");
            $self->assertEquals($jsondata->{'type'}, "created");
            $self->assertEquals($jsondata->{'event'}, $data);
            $response = new \Sabre\HTTP\Response(200);
            $requestCalled = true;
        });

        $rv = $plugin->beforeCreateFile("test", $data, $parent, $modified);
        $plugin->afterCreateFile("test", $parent);
        $this->assertTrue($rv);
        $this->assertTrue($requestCalled);
    }

    function testCreateFileNonCalendarHome() {
        $plugin = $this->getPlugin();

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $parent = new \Sabre\DAV\SimpleCollection("root", []);

        $this->assertTrue($plugin->beforeCreateFile("test", $data, $parent, $modified));
        $this->assertTrue($plugin->afterCreateFile("test", $parent));
        $this->assertNull($plugin->getRequest());
    }

    function testWriteContent() {
        $plugin = $this->getPlugin();
        $client = $plugin->getClient();

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, $data, &$requestCalled) {
            $jsondata = json_decode($request->getBodyAsString());
            $self->assertEquals($jsondata->{'event_id'}, "test");
            $self->assertEquals($jsondata->{'type'}, "updated");
            $self->assertEquals($jsondata->{'old_event'}, "olddata");
            $self->assertEquals($jsondata->{'event'}, $data);
            $response = new \Sabre\HTTP\Response(200);
            $requestCalled = true;
        });

        $objectData = [
            'uri' => 'objecturi',
            'calendardata' => 'olddata'
        ];
        $calendarData = [
            'id' => '123123123',
            'principaluri' => 'principalUri',
        ];
        $node = new \Sabre\CalDAV\CalendarObject(new CalDAVBackendMock(), $calendarData, $objectData);

        $rv = $plugin->beforeWriteContent("test", $node, $data, $modified);
        $plugin->afterWriteContent("test", $node);
        $this->assertTrue($rv);
        $this->assertTrue($requestCalled);
    }

    function testWriteContentNonACL() {
        $plugin = $this->getPlugin();

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $node = new \Sabre\DAV\SimpleFile("filename", "contents");

        $this->assertTrue($plugin->beforeWriteContent("test", $node, $data, $modified));
        $this->assertTrue($plugin->afterWriteContent("test", $node));
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
            'principaluri' => 'principalUri',
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
            $self->assertEquals($jsondata->{'event_id'}, $path);
            $self->assertEquals($jsondata->{'type'}, "deleted");
            $self->assertEquals($jsondata->{'event'}, $data);
            $response = new \Sabre\HTTP\Response(200);
            $requestCalled = true;
        });

        $rv = $plugin->beforeUnbind($path);
        $plugin->afterUnbind($path);
        $this->assertTrue($rv);
        $this->assertTrue($requestCalled);
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
        $this->assertTrue($plugin->beforeUnbind($path));
        $this->assertTrue($plugin->afterUnbind($path));
        $this->assertNull($plugin->getRequest());
    }
}

class CalDAVBackendMock extends \Sabre\CalDAV\Backend\AbstractBackend {
    function getCalendarsForUser($principalUri) { return []; }
    function createCalendar($principalUri,$calendarUri,array $properties) {}
    function deleteCalendar($calendarId) {}
    function getCalendarObjects($calendarId) { return []; }
    function getCalendarObject($calendarId,$objectUri) { return null; }
    function getMultipleCalendarObjects($calendarId, array $uris) { return []; }
    function createCalendarObject($calendarId,$objectUri,$calendarData) { return null; }
    function updateCalendarObject($calendarId,$objectUri,$calendarData) { return null; }
    function deleteCalendarObject($calendarId,$objectUri) {}
}

class ESNHookPluginMock extends ESNHookPlugin {

    function __construct($apiroot, $communities_principal, $server = null) {
        require_once '../vendor/sabre/http/tests/HTTP/ClientTest.php';
        if (!$server) $server = new \Sabre\DAV\Server([]);
        parent::__construct($apiroot, $communities_principal);
        $this->initialize($server);
        $this->httpClient = new \Sabre\HTTP\ClientMock();
    }

    function getClient() {
        return $this->httpClient;
    }

    function getRequest() {
        return $this->request;
    }
}
