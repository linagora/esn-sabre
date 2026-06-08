<?php

namespace ESN\CalDAV;

use PHPUnit\Framework\TestCase;
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
        $plugin = new BinaryAttachmentPlugin(BinaryAttachmentPlugin::MODE_FILTER);

        $data = $this->calendarWithBinaryAttach();
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertTrue($modified);

        $vcal = VObject\Reader::read($data);
        $attachments = $vcal->VEVENT->select('ATTACH');

        $this->assertCount(1, $attachments);

        $remaining = reset($attachments);
        $this->assertEquals('https://example.com/files/agenda.pdf', $remaining->getValue());
    }

    function testRejectThrowsOnBinaryAttachment() {
        $plugin = new BinaryAttachmentPlugin(BinaryAttachmentPlugin::MODE_REJECT);

        $data = $this->calendarWithBinaryAttach();
        $modified = false;

        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);

        $this->invokeProcess($plugin, $data, $modified);
    }

    function testAllowLeavesDataUntouched() {
        $plugin = new BinaryAttachmentPlugin(BinaryAttachmentPlugin::MODE_ALLOW);

        $data = $this->calendarWithBinaryAttach();
        $original = $data;
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertFalse($modified);
        $this->assertEquals($original, $data);
    }

    function testFilterIgnoresCalendarWithoutBinaryAttachment() {
        $plugin = new BinaryAttachmentPlugin(BinaryAttachmentPlugin::MODE_FILTER);

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
        $original = $data;
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertFalse($modified);
        $this->assertEquals($original, $data);
    }

    function testFilterAcceptsJCalInput() {
        $plugin = new BinaryAttachmentPlugin(BinaryAttachmentPlugin::MODE_FILTER);

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

        $data = $jcal;
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertTrue($modified);

        $vcal = VObject\Reader::read($data);
        $this->assertCount(0, $vcal->VEVENT->select('ATTACH'));
    }

    function testNonCalendarDataIsLeftUntouched() {
        $plugin = new BinaryAttachmentPlugin(BinaryAttachmentPlugin::MODE_FILTER);

        $data = "not a calendar";
        $original = $data;
        $modified = false;

        $this->invokeProcess($plugin, $data, $modified);

        $this->assertFalse($modified);
        $this->assertEquals($original, $data);
    }

    /**
     * Calls the protected process() handler by reference.
     */
    private function invokeProcess(BinaryAttachmentPlugin $plugin, &$data, &$modified) {
        $method = new \ReflectionMethod($plugin, 'process');
        $method->setAccessible(true);

        $args = [&$data, &$modified];
        $method->invokeArgs($plugin, $args);
    }
}
