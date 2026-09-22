<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * Pins down how many times a single request parses calendar data.
 *
 * Handling a PUT involves several plugins that each need the previous revision
 * of the event, and the backend then needs the new one to denormalize it. They
 * used to call Reader::read() independently, so one write parsed the same bytes
 * up to four times over. They now share \ESN\Utils\VObjectCache: the previous
 * revision is parsed once, and the new one is parsed once by Sabre and handed
 * to the cache by \ESN\CalDAV\Plugin::validateICalendar.
 *
 * The counters below only see parses that went through that cache: Sabre's own
 * parse of the request body, and any plugin calling Reader::read() directly,
 * stay invisible here. What the budget does catch is the regression that
 * matters in practice, a call site drifting back off the cache and the sharing
 * silently stopping.
 *
 * @medium
 */
class VObjectParseBudgetTest extends \ESN\DAV\ServerMock {

    const EVENT_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event1.ics';

    function setUp(): void {
        parent::setUp();

        // The plugins that used to re-read the node, or re-parse the payload,
        // for themselves.
        $this->server->addPlugin(new ParticipationPlugin());
        $this->server->addPlugin(new Schedule\Plugin($this->principalBackend));
        $this->server->addPlugin(new \ESN\JSON\Plugin('json'));
        $this->server->addPlugin(new BinaryAttachmentPlugin(BinaryAttachmentPlugin::MODE_FILTER));
    }

    function testShouldParseEachRevisionOnlyOncePerWrite() {
        $response = $this->request($this->putRequest($this->updatedEvent()));
        $this->assertContains($response->status, [201, 204], 'PUT should succeed');

        $stats = $this->vObjectCache->getStats();

        // The previous revision, and nothing else. The new one is parsed by
        // Sabre and handed over, so it never reaches the reader again.
        $this->assertSame(
            1,
            $stats['parses'],
            'only the previous revision should need parsing'
        );

        // Participation, scheduling and the privacy check share the previous
        // revision; the backend denormalizes the new one from the handed-over
        // copy.
        $this->assertGreaterThanOrEqual(
            3,
            $stats['hits'],
            'both revisions should be reused rather than parsed again'
        );
    }

    /**
     * The handed-over copy has to describe the bytes it is filed under, or the
     * backend denormalizes an event that is not the one it stores. A jCal body
     * is the sharpest case: Sabre converts it, so the stored bytes are the
     * serialization of the object rather than the request body.
     */
    function testHandedOverObjectShouldDescribeWhatIsStored() {
        $jCal = json_encode(\Sabre\VObject\Reader::read($this->updatedEvent('From jCal'))->jsonSerialize());

        $response = $this->request($this->putRequest($jCal, 'application/calendar+json'));
        $this->assertContains($response->status, [201, 204], 'PUT should succeed');

        $this->assertHandedOverObjectMatchesStoredData('event1.ics');
    }

    /**
     * Same invariant, for an object the pipeline rewrites on its way through:
     * the binary attachment is stripped during calendarObjectChange, after
     * which Sabre re-serializes the object.
     */
    function testStrippedAttachmentShouldNotDesynchroniseTheStoredObject() {
        $response = $this->request($this->putRequest($this->eventWithBinaryAttachment()));
        $this->assertContains($response->status, [201, 204], 'PUT should succeed');

        $stored = $this->storedObject('event1.ics');

        $this->assertStringNotContainsString('ENCODING=BASE64', $stored['calendardata']);
        $this->assertStringContainsString('https://example.com/agenda.pdf', $stored['calendardata']);

        $this->assertHandedOverObjectMatchesStoredData('event1.ics');
    }

    function testShouldStoreWhatTheClientSent() {
        $this->request($this->putRequest($this->updatedEvent()));

        $stored = $this->storedObject('event1.ics');

        // Sharing parsed documents must not leak one caller's edits into the
        // bytes another caller writes out.
        $this->assertStringContainsString('SUMMARY:Rewritten', $stored['calendardata']);
        $this->assertStringContainsString('UID:event1', $stored['calendardata']);
    }

    function testShouldNotCarryParsedDataOverFromOneRequestToTheNext() {
        $this->request($this->putRequest($this->updatedEvent()));
        $this->assertGreaterThan(0, $this->vObjectCache->getStats()['parses']);

        $this->request($this->putRequest($this->updatedEvent('Rewritten again')));

        // A fresh request starts from an empty cache, so it cannot be served a
        // document parsed while handling someone else's request.
        $this->assertGreaterThan(0, $this->vObjectCache->getStats()['parses']);
    }

    /**
     * Checks the invariant the hand-over rests on: the copy filed under the
     * written bytes has to be a copy of the object those bytes came from.
     *
     * Only valid for a write the pipeline modified, where the stored bytes are
     * the serialization of the object rather than the untouched request body.
     */
    private function assertHandedOverObjectMatchesStoredData($uri) {
        $stored = $this->storedObject($uri);

        $parsesBefore = $this->vObjectCache->getStats()['parses'];
        $handedOver = $this->vObjectCache->read($stored['calendardata']);

        $this->assertSame(
            $parsesBefore,
            $this->vObjectCache->getStats()['parses'],
            'the written object should still be in the cache, not parsed afresh'
        );
        $this->assertSame(
            $stored['calendardata'],
            $handedOver->serialize(),
            'the handed-over object must be the one the stored bytes describe'
        );

        $expected = (new Backend\Service\CalendarDataNormalizer())
            ->getDenormalizedData($stored['calendardata']);

        $this->assertSame($expected['uid'], $stored['uid']);
        $this->assertSame($expected['componentType'], $stored['componenttype']);
        $this->assertSame($expected['firstOccurence'], $stored['firstoccurence']);
        $this->assertSame($expected['lastOccurence'], $stored['lastoccurence']);
        $this->assertSame($expected['size'], $stored['size']);
    }

    private function storedObject($uri) {
        return $this->sabredb->calendarobjects->findOne([
            'calendarid' => $this->cal['id'][0],
            'uri' => $uri
        ]);
    }

    private function putRequest($body, $contentType = 'text/calendar') {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI'    => self::EVENT_PATH,
            'CONTENT_TYPE'   => $contentType
        ]);
        $request->setBody($body);

        return $request;
    }

    private function updatedEvent($summary = 'Rewritten') {
        return $this->calendar([
            'SUMMARY:' . $summary
        ]);
    }

    private function eventWithBinaryAttachment() {
        return $this->calendar([
            'SUMMARY:With attachment',
            'ATTACH;FMTTYPE=application/pdf;ENCODING=BASE64;VALUE=BINARY:dGVzdA==',
            'ATTACH;FMTTYPE=application/pdf:https://example.com/agenda.pdf'
        ]);
    }

    private function calendar(array $extraProperties) {
        return implode("\r\n", array_merge([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Linagora//Twake-Calendar//EN',
            'BEGIN:VEVENT',
            'CREATED:20120313T142342Z',
            'UID:event1',
            'DTSTAMP:20120313T142416Z',
            'DTSTART:20120227T000000Z',
            'DTEND:20120227T010000Z',
            'TRANSP:OPAQUE',
            'SEQUENCE:5'
        ], $extraProperties, [
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]));
    }
}
