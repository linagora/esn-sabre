<?php

namespace ESN\CalDAV;

use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Sabre\VObject\Reader;

/**
 * @medium
 */
class VideoConferencePluginTest extends \PHPUnit\Framework\TestCase {

    private $server;

    function setUp(): void {
        $this->server = new \Sabre\DAV\Server([]);
        $this->server->addPlugin(new VideoConferencePlugin());
    }

    function testShouldFlagTheCalendarObjectAsModifiedWhenAConferenceIsAdded() {
        $vCal = Reader::read($this->eventIcs('X-OPENPAAS-VIDEOCONFERENCE;VALUE=UNKNOWN:https://meet.example.com/room'));

        $modified = $this->emitCalendarObjectChange($vCal);

        $this->assertTrue($modified);
        $this->assertSame('https://meet.example.com/room', (string) $vCal->VEVENT->CONFERENCE);
    }

    function testShouldNotFlagTheCalendarObjectAsModifiedWhenThereIsNothingToDo() {
        $vCal = Reader::read($this->eventIcs('DESCRIPTION:No video conference here'));

        $modified = $this->emitCalendarObjectChange($vCal);

        $this->assertFalse($modified);
    }

    private function emitCalendarObjectChange($vCal): bool {
        $modified = false;
        $this->server->emit('calendarObjectChange', [
            new Request('PUT', '/calendars/user/cal/event.ics'),
            new Response(),
            $vCal,
            'calendars/user/cal',
            &$modified,
            true
        ]);

        return $modified;
    }

    private function eventIcs(string $extraProperty): string {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:simple-event',
            'DTSTART:20260322T090000Z',
            'DTEND:20260322T100000Z',
            'SUMMARY:Meeting',
            $extraProperty,
            'END:VEVENT',
            'END:VCALENDAR'
        ]);
    }
}
