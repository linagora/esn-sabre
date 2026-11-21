<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * Exercises ESN\CalDAV\Plugin::calendarMultiGetReport, which answers 404 for
 * an href that names no object (RFC 4791 section 7.9).
 *
 * @medium
 */
class CalendarMultiGetReportTest extends \ESN\DAV\ServerMock {

    const CALENDAR = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/';

    private function multiget(array $hrefs, array $headers = []) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array_merge([
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_DEPTH'        => '1',
            'REQUEST_URI'       => self::CALENDAR,
        ], $headers));
        $request->setBody('<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/></d:prop>'
            . implode('', array_map(function ($href) { return '<d:href>' . $href . '</d:href>'; }, $hrefs))
            . '</c:calendar-multiget>');

        return $this->request($request);
    }

    /**
     * @return array href => [status of the response or null, etag or null]
     */
    private function parseMultiStatus($body) {
        $xml = simplexml_load_string($body);
        $xml->registerXPathNamespace('d', 'DAV:');

        $result = [];
        foreach ($xml->xpath('//d:response') as $response) {
            $response->registerXPathNamespace('d', 'DAV:');
            $status = $response->xpath('d:status');
            $etag = $response->xpath('.//d:getetag');
            $result[(string) $response->xpath('d:href')[0]] = [
                $status ? (string) $status[0] : null,
                $etag ? (string) $etag[0] : null,
            ];
        }

        return $result;
    }

    function testUnknownHrefGetsA404Response() {
        $response = $this->multiget([self::CALENDAR . 'event1.ics', self::CALENDAR . 'unknown.ics']);

        $this->assertEquals(207, $response->status);
        $responses = $this->parseMultiStatus($response->getBodyAsString());
        $this->assertCount(2, $responses);
        $this->assertNull($responses[self::CALENDAR . 'event1.ics'][0]);
        $this->assertNotEmpty($responses[self::CALENDAR . 'event1.ics'][1]);
        $this->assertEquals(['HTTP/1.1 404 Not Found', null], $responses[self::CALENDAR . 'unknown.ics']);
    }

    function testOnlyUnknownHrefsGetOneResponseEach() {
        $response = $this->multiget([self::CALENDAR . 'unknown1.ics', self::CALENDAR . 'unknown2.ics', self::CALENDAR . 'unknown1.ics']);

        $this->assertEquals(207, $response->status);
        $this->assertEquals([
            self::CALENDAR . 'unknown1.ics' => ['HTTP/1.1 404 Not Found', null],
            self::CALENDAR . 'unknown2.ics' => ['HTTP/1.1 404 Not Found', null],
        ], $this->parseMultiStatus($response->getBodyAsString()));
    }

    function testUnknownHrefIsKeptWithMinimalPrefer() {
        $response = $this->multiget([self::CALENDAR . 'event1.ics', self::CALENDAR . 'unknown.ics'], ['HTTP_PREFER' => 'return=minimal']);

        $this->assertEquals(207, $response->status);
        $responses = $this->parseMultiStatus($response->getBodyAsString());
        $this->assertCount(2, $responses);
        $this->assertEquals('HTTP/1.1 404 Not Found', $responses[self::CALENDAR . 'unknown.ics'][0]);
    }

    function testKnownHrefsAreAnsweredAsBefore() {
        $response = $this->multiget([self::CALENDAR . 'event1.ics']);

        $this->assertEquals(207, $response->status);
        $responses = $this->parseMultiStatus($response->getBodyAsString());
        $this->assertEquals([self::CALENDAR . 'event1.ics'], array_keys($responses));
    }
}
