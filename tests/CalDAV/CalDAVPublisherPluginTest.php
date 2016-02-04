<?php

namespace ESN\CalDAV;
require_once ESN_TEST_BASE . '/CalDAV/MockUtils.php';

class CalDAVPublisherPluginTest extends \PHPUnit_Framework_TestCase {

    private function getPlugin($server = null) {
        $plugin = new CalDAVPublisherPluginMock($server);
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

        $this->assertTrue($server->emit('beforeCreateFile', [$path, &$data, $parent, &$modified]));
        $this->assertTrue($server->emit('afterCreateFile', [$path, $parent]));

        $jsondata = json_decode($client->message);
        $this->assertEquals($client->topic, 'calendar:event:updated');
        $this->assertEquals($jsondata->{'event_id'}, "/" . $path);
        $this->assertEquals($jsondata->{'type'}, 'created');
        $this->assertEquals($jsondata->{'event'}, $data);
        $this->assertEquals($jsondata->{'websocketEvent'}, 'calendar:ws:event:created');
    }

    function testCreateFileNonCalendarHome() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $parent = new \Sabre\DAV\SimpleCollection("root", []);

        $this->assertTrue($server->emit('beforeCreateFile', ["test", &$data, $parent, &$modified]));
        $this->assertTrue($server->emit('afterCreateFile', ["test", $parent]));
        $this->assertNull($client->message);
    }

    function testWriteContent() {
        $plugin = $this->getPlugin();
        $client = $plugin->getClient();
        $server = $plugin->getServer();

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $path = "calendars/123123/uid.ics";

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

        $jsondata = json_decode($client->message);
        $this->assertEquals($client->topic, 'calendar:event:updated');
        $this->assertEquals($jsondata->{'event_id'}, "/" . $path);
        $this->assertEquals($jsondata->{'type'}, 'updated');
        $this->assertEquals($jsondata->{'old_event'}, "olddata");
        $this->assertEquals($jsondata->{'event'}, $data);
        $this->assertEquals($jsondata->{'websocketEvent'}, 'calendar:ws:event:updated');
    }

    function testWriteContentNonACL() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $modified = false;
        $data = "BEGIN:VCALENDAR";
        $node = new \Sabre\DAV\SimpleFile("filename", "contents");

        $path = "calendars/123123/uid.ics";
        $this->assertTrue($server->emit('beforeWriteContent', [$path, $node, &$data, &$modified]));
        $this->assertTrue($server->emit('afterWriteContent', [$path, $node]));
        $this->assertNull($client->message);
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

        $this->assertTrue($server->emit('beforeUnbind', [$path]));
        $this->assertTrue($server->emit('afterUnbind', [$path]));

        $jsondata = json_decode($client->message);
        $this->assertEquals($client->topic, 'calendar:event:updated');
        $this->assertEquals($jsondata->{'event_id'}, "/" . $path);
        $this->assertEquals($jsondata->{'type'}, 'deleted');
        $this->assertEquals($jsondata->{'event'}, $data);
        $this->assertEquals($jsondata->{'websocketEvent'}, 'calendar:ws:event:deleted');
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
        $client = $plugin->getClient();
        $this->assertTrue($server->emit('beforeUnbind', [$path]));
        $this->assertTrue($server->emit('afterUnbind', [$path]));
        $this->assertNull($client->message);
    }
}

class PublisherMock implements \ESN\Utils\Publisher {
    public $topic;
    public $message;

    function publish($topic, $message) {
        $this->topic = $topic;
        $this->message = $message;
    }
}

class CalDAVPublisherPluginMock extends CalDAVPublisherPlugin {

    function __construct($server) {
        if (!$server) $server = new \Sabre\DAV\Server([]);
        $this->initialize($server);
        $this->client = new PublisherMock();
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
}
