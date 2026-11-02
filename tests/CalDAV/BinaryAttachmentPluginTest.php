<?php

namespace ESN\CalDAV;

use ESN\Utils\AuthTenant;
use ESN\Utils\TenantType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sabre\DAV\Server;
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

    function testTrustedBaseFiltersOnlyUntrustedUriAttachments() {
        $vcal = VObject\Reader::read(str_replace(
            'https://example.com/files/agenda.pdf',
            "https://tung-drive.linagora.com/files/agenda.pdf\nATTACH:https://evil.test/file.pdf",
            $this->calendarWithBinaryAttach()
        ));

        $modified = $this->emitCalendarObjectChange(BinaryAttachmentPlugin::MODE_FILTER, $vcal,
            'https://{fdqn}-drive.linagora.com');

        $this->assertTrue($modified);
        $attachments = $vcal->VEVENT->select('ATTACH');
        $this->assertCount(1, $attachments);
        $this->assertSame('https://tung-drive.linagora.com/files/agenda.pdf', (string) reset($attachments));
    }

    static function attachmentsWhoseNameSabreRepairs() {
        return [
            ['..ATTACH:https://evil.test/a.pdf'],
            ['..ATTACH;VALUE=BINARY;ENCODING=BASE64:dGVzdA=='],
        ];
    }

    #[DataProvider('attachmentsWhoseNameSabreRepairs')]
    function testFilterRemovesAnAttachmentWhoseNameSabreRepaired(string $attachment) {
        $vcal = VObject\Reader::read(implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:repaired-attach',
            'DTSTART:20260322T090000Z',
            'DTEND:20260322T100000Z',
            $attachment,
            'END:VEVENT',
            'END:VCALENDAR'
        ]));
        // what Sabre\CalDAV\Plugin::validateICalendar does before emitting calendarObjectChange
        $vcal->validate(VObject\Node::PROFILE_CALDAV | VObject\Node::REPAIR);

        $modified = $this->emitCalendarObjectChange(BinaryAttachmentPlugin::MODE_FILTER, $vcal,
            'https://files.linagora.com');

        $this->assertTrue($modified);
        $stored = VObject\Reader::read($vcal->serialize());
        $this->assertCount(0, $stored->VEVENT->select('ATTACH'));
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
    private function emitCalendarObjectChange($mode, $vcal, ?string $trustedBase = null): bool {
        $server = new \Sabre\DAV\Server([]);
        $server->addPlugin(new BinaryAttachmentPlugin($mode, $trustedBase));

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

    private function calendarForOffload(string $extra = '') {
        return VObject\Reader::read(implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//test//EN', 'BEGIN:VEVENT',
            'UID:upload-test', 'DTSTAMP:20261022T000000Z', 'DTSTART:20261022T090000Z',
            'ORGANIZER:mailto:someone-else@example.test',
            'ATTACH;VALUE=BINARY;ENCODING=BASE64;FILENAME=report.bin;FMTTYPE=application/octet-stream:AAEC/w0K',
            $extra, 'END:VEVENT', 'END:VCALENDAR', ''
        ]));
    }

    private function offloadServer($client, ?AuthTenant $tenant = null, ?string $trusted = null) {
        $server = new Server([]);
        $server->addPlugin(new BinaryAttachmentPlugin(BinaryAttachmentPlugin::MODE_OFFLOAD, $trusted, $client));
        if ($tenant !== null) $server->emit('auth:success', [$tenant]);
        return $server;
    }

    private function emitOffloadChange($server, $calendar) {
        $modified = false;
        $server->emit('calendarObjectChange', [new Request('PUT', '/calendars/bob/default/test.ics', ['Authorization' => 'Bearer user-token']), new Response(),
            $calendar, 'calendars/bob/default', &$modified, true]);
        return $modified;
    }

    function testConvertsMultipleAttachmentsBeforeSchedulingAndUsesAuthenticatedUploader() {
        $client = $this->createMock(AttachmentUploadClient::class);
        $client->expects($this->exactly(2))->method('upload')->willReturnCallback(function ($authorization, $name, $type, $content) {
            $this->assertSame('Bearer user-token', $authorization);
            $this->assertContains($content, ["\x00\x01\x02\xff\r\n", 'second']);
            return 'https://bob-drive.example.test/public?sharecode=' . $name . '#/';
        });
        $server = $this->offloadServer($client, new AuthTenant('bob-id', 'domain-id'));
        $calendar = $this->calendarForOffload('ATTACH;VALUE=BINARY;ENCODING=BASE64;X-FILENAME=second.txt;FMTTYPE=text/plain:c2Vjb25k');
        $server->on('calendarObjectChange', function ($request, $response, $calendar) {
            foreach ($calendar->VEVENT->select('ATTACH') as $attachment) {
                $this->assertSame('BINARY', $attachment->getValueType());
            }
        }, 80);
        $server->on('calendarObjectChange', function ($request, $response, $calendar) {
            foreach ($calendar->VEVENT->select('ATTACH') as $attachment) {
                $this->assertSame('URI', $attachment->getValueType());
                $this->assertNotEmpty((string) $attachment['X-FILENAME']);
                $this->assertFalse(isset($attachment['ENCODING']));
            }
        }, 100);
        $this->assertTrue($this->emitOffloadChange($server, $calendar));
        $this->assertStringNotContainsString('VALUE=BINARY', $calendar->serialize());
        $roundTrip = VObject\Reader::read($calendar->serialize());
        $this->assertCount(2, $roundTrip->VEVENT->select('ATTACH'));
        $this->assertSame('report.bin', (string) $roundTrip->VEVENT->ATTACH['X-FILENAME']);
    }

    function testValidatesAllAttachmentsBeforeAnyUpload() {
        $client = $this->createMock(AttachmentUploadClient::class);
        $client->expects($this->never())->method('upload');
        $calendar = $this->calendarForOffload('ATTACH;VALUE=BINARY;ENCODING=BASE64;FILENAME=../bad.txt:Zm9v');
        $this->expectException(\Sabre\DAV\Exception\BadRequest::class);
        $this->emitOffloadChange($this->offloadServer($client, new AuthTenant('bob-id', 'domain-id')), $calendar);
    }

    function testFailedUploadDoesNotReplaceAnyAttachment() {
        $client = $this->createMock(AttachmentUploadClient::class);
        $calls = 0;
        $client->expects($this->exactly(2))->method('upload')->willReturnCallback(function () use (&$calls) {
            if (++$calls === 2) throw new \Sabre\DAV\Exception\ServiceUnavailable('unavailable');
            return 'https://bob-drive.example.test/public?sharecode=test#/';
        });
        $calendar = $this->calendarForOffload('ATTACH;VALUE=BINARY;ENCODING=BASE64;FILENAME=second.txt:c2Vjb25k');
        $original = $calendar->serialize();
        try {
            $this->emitOffloadChange($this->offloadServer($client, new AuthTenant('bob-id', 'domain-id')), $calendar);
            $this->fail('Expected upload failure');
        } catch (\Sabre\DAV\Exception\ServiceUnavailable $error) {
            $this->assertSame($original, $calendar->serialize());
        }
    }

    function testNoUploadAfterValidationRejectsEvent() {
        $client = $this->createMock(AttachmentUploadClient::class);
        $client->expects($this->never())->method('upload');
        $server = $this->offloadServer($client, new AuthTenant('bob-id', 'domain-id'));
        $server->on('calendarObjectChange', function () { throw new \Sabre\DAV\Exception\BadRequest('invalid event'); }, 80);
        $this->expectException(\Sabre\DAV\Exception\BadRequest::class);
        $this->emitOffloadChange($server, $this->calendarForOffload());
    }

    function testTechnicalUserCannotSelectAnotherUsersDrive() {
        $client = $this->createMock(AttachmentUploadClient::class);
        $client->expects($this->never())->method('upload');
        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);
        $this->emitOffloadChange($this->offloadServer($client, new AuthTenant('bob-id', 'domain-id', TenantType::Technical)), $this->calendarForOffload());
    }

    function testItipRejectsUnprocessedBinaryRatherThanUploadingForRecipient() {
        $client = $this->createMock(AttachmentUploadClient::class);
        $client->expects($this->never())->method('upload');
        $plugin = new BinaryAttachmentPlugin(BinaryAttachmentPlugin::MODE_OFFLOAD, null, $client);
        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);
        $plugin->filterCalendar($this->calendarForOffload());
    }

    function testRejectsUploadWithoutForwardableCredentials() {
        $client = $this->createMock(AttachmentUploadClient::class);
        $client->expects($this->never())->method('upload');
        $server = $this->offloadServer($client, new AuthTenant('bob-id', 'domain-id'));
        $modified = false;
        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);
        $server->emit('calendarObjectChange', [new Request('PUT', '/calendars/bob/default/test.ics'), new Response(),
            $this->calendarForOffload(), 'calendars/bob/default', &$modified, true]);
    }

    function testUriOnlyCalendarDoesNotRequireProvisionedDrive() {
        $client = $this->createMock(AttachmentUploadClient::class);
        $client->expects($this->never())->method('upload');
        $calendar = $this->calendarForOffload();
        unset($calendar->VEVENT->ATTACH);
        $calendar->VEVENT->add('ATTACH', 'https://drive.example.test/public?sharecode=existing#/');
        $this->assertFalse($this->emitOffloadChange($this->offloadServer($client), $calendar));
    }
}
