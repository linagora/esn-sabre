<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * Pins down how many times a single request parses calendar data.
 *
 * Handling a PUT involves several plugins that each need the previous revision
 * of the event, and the backend then needs the new one to denormalize it. They
 * used to call Reader::read() independently, so one write parsed the same bytes
 * four times over. They now go through the shared \ESN\Utils\VObjectCache.
 *
 * The counters below only see parses that went through that cache: a plugin
 * calling Reader::read() directly stays invisible here. What the budget does
 * catch is the regression that matters in practice, a call site drifting back
 * off the cache and the sharing silently stopping.
 *
 * @medium
 */
class VObjectParseBudgetTest extends \ESN\DAV\ServerMock {

    const EVENT_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event1.ics';

    function setUp(): void {
        parent::setUp();

        // The plugins that used to re-read the node for themselves.
        $this->server->addPlugin(new ParticipationPlugin());
        $this->server->addPlugin(new Schedule\Plugin($this->principalBackend));
        $this->server->addPlugin(new \ESN\JSON\Plugin('json'));
    }

    function testShouldParseThePreviousRevisionOnlyOncePerWrite() {
        $response = $this->request($this->putRequest($this->updatedEvent()));
        $this->assertContains($response->status, [201, 204], 'PUT should succeed');

        $stats = $this->vObjectCache->getStats();

        // Participation, scheduling and the privacy check all want the previous
        // revision; between them they must trigger a single parse.
        $this->assertGreaterThanOrEqual(
            2,
            $stats['hits'],
            'the previous revision should be parsed once and reused'
        );

        // Old revision + new revision. Anything beyond that means a payload is
        // being parsed twice somewhere in the pipeline.
        $this->assertLessThanOrEqual(
            2,
            $stats['parses'],
            'a write should parse at most the old and the new revision'
        );
    }

    function testShouldNotCarryParsedDataOverFromOneRequestToTheNext() {
        $this->request($this->putRequest($this->updatedEvent()));
        $this->assertGreaterThan(0, $this->vObjectCache->getStats()['parses']);

        $this->request($this->putRequest($this->updatedEvent('Rewritten again')));

        // A fresh request starts from an empty cache, so it cannot be served a
        // document parsed while handling someone else's request.
        $this->assertGreaterThan(0, $this->vObjectCache->getStats()['parses']);
    }

    function testShouldStoreWhatTheClientSent() {
        $this->request($this->putRequest($this->updatedEvent()));

        $stored = $this->caldavBackend->getCalendarObject([$this->cal['id'][0], ''], 'event1.ics');

        // Sharing parsed documents must not leak one caller's edits into the
        // bytes another caller writes out.
        $this->assertStringContainsString('SUMMARY:Rewritten', $stored['calendardata']);
        $this->assertStringContainsString('UID:event1', $stored['calendardata']);
    }

    private function putRequest($body) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD' => 'PUT',
            'REQUEST_URI'    => self::EVENT_PATH,
            'CONTENT_TYPE'   => 'text/calendar'
        ]);
        $request->setBody($body);

        return $request;
    }

    private function updatedEvent($summary = 'Rewritten') {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'CREATED:20120313T142342Z',
            'UID:event1',
            'DTEND;TZID=Europe/Berlin:20120227T010000',
            'TRANSP:OPAQUE',
            'SUMMARY:' . $summary,
            'DTSTART;TZID=Europe/Berlin:20120227T000000',
            'DTSTAMP:20120313T142416Z',
            'SEQUENCE:5',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);
    }
}
