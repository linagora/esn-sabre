<?php

namespace ESN\CalDAV;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Reader;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

/**
 * @medium
 */
class ImportPluginTest extends \ESN\DAV\ServerMock {

    protected $userTestId = '5aa1f6639751b711008b4567';
    protected $plugin;

    function setUp(): void {
        parent::setUp();

        $plugin = new ImportPlugin("import");
        $this->server->addPlugin($plugin);
    }

    function testReturnFalseOnImport() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'HTTP_ACCEPT'       => 'text/calendar',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json?import',
        ));

        $response = $this->request($request);

        $this->assertFalse($this->server->emit('schedule', [$this->newItipMessage('')]));
    }

    function testReturnTrueOnCreation() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'HTTP_ACCEPT'       => 'text/calendar',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json',
        ));

        $response = $this->request($request);

        $this->assertTrue($this->server->emit('schedule', [$this->newItipMessage('')]));
    }

    const HOME = '/calendars/54b64eadf6d7d8e41d263e0f';

    function testImportWithCurrentIfMatchUpdatesTheEvent() {
        $etag = $this->getEtag('calendar1', 'event1.ics');

        $response = $this->importEvent('calendar1', 'event1.ics', $this->newEvent('event1', 'v2'), ['HTTP_IF_MATCH' => $etag]);

        $this->assertEquals(204, $response->getStatus());
        $this->assertStringContainsString('SUMMARY:v2', $this->getCalendarData('calendar1', 'event1.ics'));
        $this->assertNotEquals($etag, $this->getEtag('calendar1', 'event1.ics'));
    }

    function testImportWithStaleIfMatchFailsAndKeepsTheEvent() {
        $before = $this->getCalendarData('calendar1', 'event1.ics');

        $response = $this->importEvent('calendar1', 'event1.ics', $this->newEvent('event1', 'v2'), ['HTTP_IF_MATCH' => '"stale"']);

        $this->assertEquals(412, $response->getStatus());
        $this->assertEquals($before, $this->getCalendarData('calendar1', 'event1.ics'));
    }

    function testImportWithIfNoneMatchStarOnExistingEventFailsAndKeepsTheEvent() {
        $before = $this->getCalendarData('calendar1', 'event1.ics');

        $response = $this->importEvent('calendar1', 'event1.ics', $this->newEvent('event1', 'v2'), ['HTTP_IF_NONE_MATCH' => '*']);

        $this->assertEquals(412, $response->getStatus());
        $this->assertEquals($before, $this->getCalendarData('calendar1', 'event1.ics'));
    }

    function testImportWithoutPreconditionOnExistingEventIsAnUpdate() {
        $unbound = $this->recordUnbinds();

        $response = $this->importEvent('calendar1', 'event1.ics', $this->newEvent('event1', 'v2'));

        $this->assertEquals(204, $response->getStatus());
        $this->assertStringContainsString('SUMMARY:v2', $this->getCalendarData('calendar1', 'event1.ics'));
        $this->assertEquals([], $unbound->getArrayCopy());
    }

    function testImportOfANewEventIsACreation() {
        $response = $this->importEvent('calendar1', 'new.ics', $this->newEvent('new', 'v1'));

        $this->assertEquals(201, $response->getStatus());
        $this->assertStringContainsString('SUMMARY:v1', $this->getCalendarData('calendar1', 'new.ics'));
    }

    function testImportRemovesTheCopiesOfTheEventFromTheOtherCalendarsOfTheHome() {
        $unbound = $this->recordUnbinds();

        $response = $this->importEvent('user1Calendar2', 'event1.ics', $this->newEvent('event1', 'moved'));

        $this->assertEquals(201, $response->getStatus());
        $this->assertStringContainsString('SUMMARY:moved', $this->getCalendarData('user1Calendar2', 'event1.ics'));
        $this->assertNull($this->getCalendarObject('calendar1', 'event1.ics'));
        $this->assertEquals([self::HOME . '/calendar1/event1.ics'], $unbound->getArrayCopy());
    }

    function testImportRemovesCopiesHavingTheSameUidUnderAnotherUri() {
        $response = $this->importEvent('user1Calendar2', 'other.ics', $this->newEvent('event1', 'moved'));

        $this->assertEquals(201, $response->getStatus());
        $this->assertNotNull($this->getCalendarObject('user1Calendar2', 'other.ics'));
        $this->assertNull($this->getCalendarObject('calendar1', 'event1.ics'));
    }

    function testImportDoesNotRemoveEventsHavingAnotherUid() {
        $response = $this->importEvent('user1Calendar2', 'event1.ics', $this->newEvent('another-uid', 'v1'));

        $this->assertEquals(201, $response->getStatus());
        $this->assertNotNull($this->getCalendarObject('calendar1', 'event1.ics'));
        $this->assertNotNull($this->getCalendarObject('user1Calendar2', 'event1.ics'));
    }

    function testImportWithKeepDuplicatesLetsTheSameUidLiveInTwoCalendars() {
        $response = $this->importEvent('user1Calendar2', 'event1.ics', $this->newEvent('event1', 'copy'), [], '?import&keepDuplicates');

        $this->assertEquals(201, $response->getStatus());
        $this->assertStringContainsString('SUMMARY:Monday 0h', $this->getCalendarData('calendar1', 'event1.ics'));
        $this->assertStringContainsString('SUMMARY:copy', $this->getCalendarData('user1Calendar2', 'event1.ics'));
    }

    function testFailedImportDoesNotRemoveDuplicates() {
        $unbound = $this->recordUnbinds();

        $response = $this->importEvent('user1Calendar2', 'event1.ics', $this->newEvent('event1', 'moved'), ['HTTP_IF_MATCH' => '"stale"']);

        $this->assertEquals(412, $response->getStatus());
        $this->assertNotNull($this->getCalendarObject('calendar1', 'event1.ics'));
        $this->assertNull($this->getCalendarObject('user1Calendar2', 'event1.ics'));
        $this->assertEquals([], $unbound->getArrayCopy());
    }

    function testPutWithoutImportDoesNotRemoveDuplicates() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'REQUEST_URI'       => self::HOME . '/user1Calendar2/event1.ics',
        ));
        $request->setBody($this->newEvent('event1', 'copy'));

        $response = $this->request($request);

        $this->assertEquals(201, $response->getStatus());
        $this->assertNotNull($this->getCalendarObject('calendar1', 'event1.ics'));
    }

    private function importEvent($calendarUri, $objectUri, $ics, array $headers = [], $query = '?import') {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array_merge([
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'REQUEST_URI'       => self::HOME . '/' . $calendarUri . '/' . $objectUri . $query,
        ], $headers));
        $request->setBody($ics);

        return $this->request($request);
    }

    private function recordUnbinds() {
        $unbound = new \ArrayObject();
        $this->server->on('afterUnbind', function($path) use ($unbound) {
            $unbound[] = '/' . ltrim($path, '/');
        });

        return $unbound;
    }

    private function getCalendarObject($calendarUri, $objectUri) {
        $calendarId = $calendarUri === 'calendar1' ? $this->cal['id'] : $this->user1Cal2['id'];

        return $this->caldavBackend->getCalendarObject($calendarId, $objectUri);
    }

    private function getCalendarData($calendarUri, $objectUri) {
        return $this->getCalendarObject($calendarUri, $objectUri)['calendardata'];
    }

    private function getEtag($calendarUri, $objectUri) {
        return $this->getCalendarObject($calendarUri, $objectUri)['etag'];
    }

    private function newEvent($uid, $summary) {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//probe//EN\r\nBEGIN:VEVENT\r\nUID:$uid\r\n" .
            "DTSTAMP:20260101T090000Z\r\nDTSTART:20261102T090000Z\r\nDTEND:20261102T100000Z\r\n" .
            "SUMMARY:$summary\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    private function newItipMessage($sequence) {
        $message = new Message();
        $ical = "BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20120313T142342Z
UID:event1
DTEND;TZID=Europe/Berlin:20120227T000000
TRANSP:OPAQUE
SUMMARY:Monday 0h
DTSTART;TZID=Europe/Berlin:20120227T000000
DTSTAMP:20120313T142416Z
SEQUENCE:$sequence
END:VEVENT
END:VCALENDAR
";

        $message->component = 'VEVENT';
        $message->uid = 'UID';
        $message->sequence = (int) $sequence;
        $message->method = 'REQUEST';
        $message->sender = 'mailto:a@a.com';
        $message->recipient = 'mailto:b@b.com';
        $message->message = Reader::read($ical);

        return $message;
    }
}
