<?php

namespace ESN\CalDAV;

use ESN\Utils\Env;
use PHPUnit\Framework\Attributes\DataProvider;
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

    function testUnconfiguredPluginKeepsAnUntrustedVideoLink() {
        $vCal = Reader::read($this->eventIcs('X-OPENPAAS-VIDEOCONFERENCE:https://evil.test/room'));

        $this->emitCalendarObjectChange($vCal);

        $this->assertSame('https://evil.test/room', (string) $vCal->VEVENT->{'X-OPENPAAS-VIDEOCONFERENCE'});
    }

    static function unconfiguredVideoBases() {
        return [
            'missing key' => [[]],
            'null value' => [['TRUSTED_VIDEO_URL_BASE' => null]],
            'empty string' => [['TRUSTED_VIDEO_URL_BASE' => '']]
        ];
    }

    #[DataProvider('unconfiguredVideoBases')]
    function testMissingNullAndEmptyVideoBasesLeaveLinksUntouched(array $settings) {
        $previous = getenv('TRUSTED_VIDEO_URL_BASE');
        putenv('TRUSTED_VIDEO_URL_BASE');
        Env::init($settings);

        try {
            $this->assertNull(Env::getString('TRUSTED_VIDEO_URL_BASE'));
            $this->server = new \Sabre\DAV\Server([]);
            $this->server->addPlugin(new VideoConferencePlugin(Env::getString('TRUSTED_VIDEO_URL_BASE')));
            $vCal = Reader::read($this->eventIcs('X-OPENPAAS-VIDEOCONFERENCE:https://evil.test/room'));

            $this->emitCalendarObjectChange($vCal);

            $this->assertSame('https://evil.test/room', (string) $vCal->VEVENT->{'X-OPENPAAS-VIDEOCONFERENCE'});
            $this->assertSame('https://evil.test/room', (string) $vCal->VEVENT->CONFERENCE);
        } finally {
            Env::reset();
            if ($previous === false) {
                putenv('TRUSTED_VIDEO_URL_BASE');
            } else {
                putenv('TRUSTED_VIDEO_URL_BASE=' . $previous);
            }
        }
    }

    function testConfiguredPluginKeepsTrustedAndRemovesUntrustedVideoLinks() {
        $trusted = Reader::read($this->eventIcs('X-OPENPAAS-VIDEOCONFERENCE:https://meet.linagora.com/room'));
        $untrusted = Reader::read($this->eventIcs('X-OPENPAAS-VIDEOCONFERENCE:https://meet.linagora.com.evil.test/room'));

        $this->emitCalendarObjectChange($trusted, 'https://meet.linagora.com');
        $modified = $this->emitCalendarObjectChange($untrusted, 'https://meet.linagora.com');

        $this->assertSame('https://meet.linagora.com/room', (string) $trusted->VEVENT->CONFERENCE);
        $this->assertTrue($modified);
        $this->assertCount(0, $untrusted->VEVENT->select('X-OPENPAAS-VIDEOCONFERENCE'));
        $this->assertCount(0, $untrusted->VEVENT->select('CONFERENCE'));
    }

    function testConfiguredPluginFiltersVideoConferenceBeforeItCanRecreateOpenPaasLink() {
        $vCal = Reader::read($this->eventIcs('CONFERENCE;VALUE=URI;FEATURE=VIDEO:https://evil.test/room'));

        $this->emitCalendarObjectChange($vCal, 'https://meet.linagora.com');

        $this->assertCount(0, $vCal->VEVENT->select('CONFERENCE'));
        $this->assertCount(0, $vCal->VEVENT->select('X-OPENPAAS-VIDEOCONFERENCE'));
    }

    private function emitCalendarObjectChange($vCal, ?string $trustedBase = null): bool {
        if ($trustedBase !== null) {
            $this->server = new \Sabre\DAV\Server([]);
            $this->server->addPlugin(new VideoConferencePlugin($trustedBase));
        }
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
