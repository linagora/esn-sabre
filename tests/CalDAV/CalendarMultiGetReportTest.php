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

    private function multiget(array $hrefs, array $headers = [], $prop = '<d:getetag/>') {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array_merge([
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_DEPTH'        => '1',
            'REQUEST_URI'       => self::CALENDAR,
        ], $headers));
        $request->setBody('<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop>' . $prop . '</d:prop>'
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

    const EXPANDED_WEEK = '<d:getetag/><c:calendar-data><c:expand start="20261207T000000Z" end="20261214T000000Z"/></c:calendar-data>';

    private function storeEvent($uri, array $lines) {
        $this->caldavBackend->createCalendarObject($this->cal['id'], $uri, implode("\r\n", array_merge(
            ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//test//EN', 'BEGIN:VEVENT'],
            $lines,
            ['END:VEVENT', 'END:VCALENDAR', '']
        )));
    }

    /**
     * @return array href => [propstat status => [property local names]]
     */
    private function propstats($body) {
        $xml = simplexml_load_string($body);
        $xml->registerXPathNamespace('d', 'DAV:');

        $result = [];
        foreach ($xml->xpath('//d:response') as $response) {
            $response->registerXPathNamespace('d', 'DAV:');
            $href = (string) $response->xpath('d:href')[0];
            $result[$href] = [];
            foreach ($response->xpath('d:propstat') as $propstat) {
                $propstat->registerXPathNamespace('d', 'DAV:');
                $status = (string) $propstat->xpath('d:status')[0];
                foreach ($propstat->xpath('d:prop/*') as $property) {
                    $result[$href][$status][] = $property->getName();
                }
            }
        }

        return $result;
    }

    function testExpandedMultiGetKeepsTheOtherObjectsWhenOneCannotBeExpanded() {
        $this->storeEvent('every-second.ics', ['UID:every-second', 'DTSTART:20261210T100000Z', 'DTEND:20261210T100001Z', 'RRULE:FREQ=SECONDLY']);
        $this->storeEvent('daily.ics', ['UID:daily', 'DTSTART:20261208T100000Z', 'DTEND:20261208T110000Z', 'RRULE:FREQ=DAILY;COUNT=3', 'SUMMARY:Daily']);

        $response = $this->multiget([self::CALENDAR . 'every-second.ics', self::CALENDAR . 'daily.ics'], [], self::EXPANDED_WEEK);

        $this->assertEquals(207, $response->status);
        $body = $response->getBodyAsString();
        $this->assertEquals([
            self::CALENDAR . 'every-second.ics' => [
                'HTTP/1.1 200 OK' => ['getetag'],
                'HTTP/1.1 422 Unprocessable Entity' => ['calendar-data'],
            ],
            self::CALENDAR . 'daily.ics' => ['HTTP/1.1 200 OK' => ['getetag', 'calendar-data']],
        ], $this->propstats($body));
        // The series is expanded: three occurrences, no RRULE
        $this->assertSame(3, substr_count($body, 'RECURRENCE-ID'));
        $this->assertStringNotContainsString('FREQ=DAILY', $body);
    }

    function testExpandedMultiGetStillAnswers404ForAnUnknownHref() {
        $this->storeEvent('every-second.ics', ['UID:every-second', 'DTSTART:20261210T100000Z', 'DTEND:20261210T100001Z', 'RRULE:FREQ=SECONDLY']);

        $response = $this->multiget([self::CALENDAR . 'every-second.ics', self::CALENDAR . 'unknown.ics'], [], self::EXPANDED_WEEK);

        $this->assertEquals(207, $response->status);
        $this->assertEquals(['HTTP/1.1 404 Not Found', null], $this->parseMultiStatus($response->getBodyAsString())[self::CALENDAR . 'unknown.ics']);
    }

    function testJsonMultiGetRendersTheCalendarDataAsJCal() {
        $response = $this->multiget([self::CALENDAR . 'event1.ics'], [],
            '<c:calendar-data content-type="application/calendar+json"/>');

        $this->assertEquals(207, $response->status);
        $xml = simplexml_load_string($response->getBodyAsString());
        $xml->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:caldav');
        $jcal = json_decode((string) $xml->xpath('//c:calendar-data')[0], true);
        $this->assertSame('vcalendar', $jcal[0]);
    }
}
