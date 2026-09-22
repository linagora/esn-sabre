<?php

namespace ESN\CalDAV;

use PHPUnit\Framework\TestCase;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Sabre\VObject;

/**
 * @medium
 */
class BinaryAttachmentPluginTest extends TestCase {

    private function calendarWithBinaryAttach() {
        return <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Linagora//Twake-Calendar//EN
BEGIN:VEVENT
UID:dcde83f3-fed0-4214-b603-17aefc193a78
DTSTART:20260613T063000Z
DTEND:20260613T073000Z
SUMMARY:toto
ATTACH;FMTTYPE=application/pdf;ENCODING=BASE64;VALUE=BINARY:dGVzdA==
ATTACH;FMTTYPE=application/pdf:https://example.com/files/agenda.pdf
END:VEVENT
END:VCALENDAR
ICS;
    }

    function testConstructorRejectsUnknownMode() {
        $this->expectException(\InvalidArgumentException::class);

        new BinaryAttachmentPlugin('nope');
    }

    function testFilterStripsBinaryAttachmentButKeepsUri() {
        $vcal = VObject\Reader::read($this->calendarWithBinaryAttach());

        $modified = $this->emitCalendarObjectChange(BinaryAttachmentPlugin::MODE_FILTER, $vcal);

        $this->assertTrue($modified);

        $attachments = $vcal->VEVENT->select('ATTACH');

        $this->assertCount(1, $attachments);

        $remaining = reset($attachments);
        $this->assertEquals('https://example.com/files/agenda.pdf', $remaining->getValue());
    }

    function testRejectThrowsOnBinaryAttachment() {
        $vcal = VObject\Reader::read($this->calendarWithBinaryAttach());

        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);

        $this->emitCalendarObjectChange(BinaryAttachmentPlugin::MODE_REJECT, $vcal);
    }

    function testAllowLeavesDataUntouched() {
        $vcal = VObject\Reader::read($this->calendarWithBinaryAttach());
        $original = $vcal->serialize();

        $modified = $this->emitCalendarObjectChange(BinaryAttachmentPlugin::MODE_ALLOW, $vcal);

        $this->assertFalse($modified);
        $this->assertEquals($original, $vcal->serialize());
    }

    function testFilterIgnoresCalendarWithoutBinaryAttachment() {
        $data = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:abc
DTSTART:20260613T063000Z
SUMMARY:no attach
ATTACH;FMTTYPE=application/pdf:https://example.com/files/agenda.pdf
END:VEVENT
END:VCALENDAR
ICS;
        $vcal = VObject\Reader::read($data);
        $original = $vcal->serialize();

        $modified = $this->emitCalendarObjectChange(BinaryAttachmentPlugin::MODE_FILTER, $vcal);

        $this->assertFalse($modified);
        $this->assertEquals($original, $vcal->serialize());
    }

    /**
     * Sabre converts a jCal body to a VCalendar before anyone is notified, so
     * the plugin only ever sees the converted object.
     */
    function testFilterAcceptsJCalInput() {
        $jcal = json_encode([
            'vcalendar',
            [['version', new \stdClass(), 'text', '2.0']],
            [[
                'vevent',
                [
                    ['uid', new \stdClass(), 'text', 'jcal-uid'],
                    ['dtstart', new \stdClass(), 'date-time', '2026-06-13T06:30:00Z'],
                    ['summary', new \stdClass(), 'text', 'jcal'],
                    ['attach', ['encoding' => 'BASE64', 'value' => 'BINARY'], 'binary', 'dGVzdA=='],
                ],
                []
            ]]
        ]);

        $vcal = VObject\Reader::readJson($jcal);

        $modified = $this->emitCalendarObjectChange(BinaryAttachmentPlugin::MODE_FILTER, $vcal);

        $this->assertTrue($modified);
        $this->assertCount(0, $vcal->VEVENT->select('ATTACH'));
    }

    /**
     * Drives the plugin the way Sabre does: through calendarObjectChange, with
     * the object it has already parsed.
     */
    private function emitCalendarObjectChange($mode, $vcal): bool {
        $server = new \Sabre\DAV\Server([]);
        $server->addPlugin(new BinaryAttachmentPlugin($mode));

        $modified = false;
        $server->emit('calendarObjectChange', [
            new Request('PUT', '/calendars/user/cal/event.ics'),
            new Response(),
            $vcal,
            'calendars/user/cal',
            &$modified,
            false
        ]);

        return $modified;
    }
}
