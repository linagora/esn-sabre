<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * PUT on a calendar object honours If-Match / If-None-Match (RFC 7232, RFC 4791 §5.3.2),
 * for iCalendar as well as for the jCal payloads sent by the web application.
 *
 * @medium
 */
class ConditionalPutTest extends \ESN\DAV\ServerMock {

    const EVENT_URI = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event1.ics';

    function setUp(): void {
        parent::setUp();

        $this->server->addPlugin(new \ESN\JSON\Plugin('json'));
    }

    function testPutWithCurrentIfMatchUpdatesTheEvent() {
        $etag = $this->currentETag();

        $response = $this->put($this->icsWithSummary('Edit me v3'), 'text/calendar', ['HTTP_IF_MATCH' => $etag]);

        $this->assertEquals(204, $response->getStatus());
        $this->assertNotEquals($etag, $this->currentETag());
        $this->assertEquals('Edit me v3', $this->storedSummary());
    }

    function testPutWithStaleIfMatchIsRejected() {
        $staleETag = $this->currentETag();
        $this->put($this->icsWithSummary('Edit me v3'), 'text/calendar', ['HTTP_IF_MATCH' => $staleETag]);

        $response = $this->put($this->icsWithSummary('Edit me stale'), 'text/calendar', ['HTTP_IF_MATCH' => $staleETag]);

        $this->assertEquals(412, $response->getStatus());
        $this->assertEquals('Edit me v3', $this->storedSummary());
    }

    function testJsonPutWithStaleIfMatchIsRejected() {
        $staleETag = $this->currentETag();
        $this->put($this->jcalWithSummary('Edit me v3'), 'application/calendar+json', ['HTTP_IF_MATCH' => $staleETag]);

        $response = $this->put($this->jcalWithSummary('Edit me stale'), 'application/calendar+json', ['HTTP_IF_MATCH' => $staleETag]);

        $this->assertEquals(412, $response->getStatus());
        $this->assertEquals('Edit me v3', $this->storedSummary());
    }

    function testPutWithIfNoneMatchStarOnExistingEventIsRejected() {
        $response = $this->put($this->icsWithSummary('Overwritten'), 'text/calendar', ['HTTP_IF_NONE_MATCH' => '*']);

        $this->assertEquals(412, $response->getStatus());
        $this->assertEquals('Monday 0h', $this->storedSummary());
    }

    function testJsonPutWithIfNoneMatchStarOnExistingEventIsRejected() {
        $response = $this->put($this->jcalWithSummary('Overwritten'), 'application/calendar+json', ['HTTP_IF_NONE_MATCH' => '*']);

        $this->assertEquals(412, $response->getStatus());
        $this->assertEquals('Monday 0h', $this->storedSummary());
    }

    private function put($body, $contentType, array $preconditions) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array_merge([
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => $contentType,
            'REQUEST_URI'       => self::EVENT_URI,
        ], $preconditions));
        $request->setBody($body);

        return $this->request($request);
    }

    private function currentETag() {
        $response = $this->request([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => self::EVENT_URI,
        ]);
        $this->assertEquals(200, $response->getStatus());

        return $response->getHeader('ETag');
    }

    private function storedSummary() {
        $object = $this->caldavBackend->getCalendarObject($this->cal['id'], 'event1.ics');

        return (string) \Sabre\VObject\Reader::read($object['calendardata'])->VEVENT->SUMMARY;
    }

    private function icsWithSummary($summary) {
        return str_replace('SUMMARY:Monday 0h', 'SUMMARY:' . $summary, $this->caldavCalendarObjects['event1.ics']);
    }

    private function jcalWithSummary($summary) {
        return json_encode(\Sabre\VObject\Reader::read($this->icsWithSummary($summary))->jsonSerialize());
    }
}
