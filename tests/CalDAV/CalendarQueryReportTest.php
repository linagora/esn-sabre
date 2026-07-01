<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * Exercises ESN\CalDAV\Plugin::calendarQueryReport, which overrides the stock
 * Sabre implementation to batch the calendar-data retrieval instead of
 * reloading every matching object one at a time (linagora/esn-sabre#403).
 *
 * @medium
 */
class CalendarQueryReportTest extends \ESN\DAV\ServerMock {

    use \Sabre\VObject\PHPUnitAssertions;

    const CALDAV_NS = 'urn:ietf:params:xml:ns:caldav';

    private function reportRequest($uri, $body, $depth = '1', $contentType = 'application/xml') {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => $contentType,
            'HTTP_DEPTH'        => $depth,
            'REQUEST_URI'       => $uri,
        ]);
        $request->setBody($body);

        return $this->request($request);
    }

    /**
     * Parses a WebDAV multistatus body into a map of href => [calendar-data, etag].
     */
    private function parseMultiStatus($body) {
        $xml = simplexml_load_string($body);
        $xml->registerXPathNamespace('d', 'DAV:');
        $xml->registerXPathNamespace('cal', self::CALDAV_NS);

        $result = [];
        foreach ($xml->xpath('//d:response') as $response) {
            $response->registerXPathNamespace('d', 'DAV:');
            $response->registerXPathNamespace('cal', self::CALDAV_NS);

            $href = (string) $response->xpath('d:href')[0];
            $calendarData = $response->xpath('.//cal:calendar-data');
            $etag = $response->xpath('.//d:getetag');

            $result[$href] = [
                'calendar-data' => $calendarData ? (string) $calendarData[0] : null,
                'etag'          => $etag ? (string) $etag[0] : null,
            ];
        }

        return $result;
    }

    private function filterlessQuery() {
        return implode("\n", [
            '<?xml version="1.0" encoding="utf-8" ?>',
            '<c:calendar-query xmlns:d="DAV:" xmlns:c="' . self::CALDAV_NS . '">',
            '  <d:prop>',
            '    <d:getetag/>',
            '    <c:calendar-data/>',
            '  </d:prop>',
            '  <c:filter>',
            '    <c:comp-filter name="VCALENDAR"/>',
            '  </c:filter>',
            '</c:calendar-query>'
        ]);
    }

    function testFilterlessReportReturnsEveryObjectWithData() {
        $response = $this->reportRequest(
            '/calendars/54b64eadf6d7d8e41d263e0f/calendar1',
            $this->filterlessQuery()
        );

        $this->assertEquals(207, $response->status);

        $items = $this->parseMultiStatus($response->getBodyAsString());
        $hrefs = array_keys($items);

        $this->assertCount(count($this->caldavCalendarObjects), $hrefs);

        foreach (array_keys($this->caldavCalendarObjects) as $uri) {
            $href = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/' . $uri;
            $this->assertArrayHasKey($href, $items);
            $this->assertNotEmpty($items[$href]['etag']);
            $this->assertVObjectEqualsVObject(
                \Sabre\VObject\Reader::read($this->caldavCalendarObjects[$uri]),
                \Sabre\VObject\Reader::read($items[$href]['calendar-data'])
            );
        }
    }

    function testFilterlessReportAsCalendarJson() {
        // Request the calendar-data as calendar+json through the report body prop.
        $body = implode("\n", [
            '<?xml version="1.0" encoding="utf-8" ?>',
            '<c:calendar-query xmlns:d="DAV:" xmlns:c="' . self::CALDAV_NS . '">',
            '  <d:prop>',
            '    <d:getetag/>',
            '    <c:calendar-data content-type="application/calendar+json"/>',
            '  </d:prop>',
            '  <c:filter>',
            '    <c:comp-filter name="VCALENDAR"/>',
            '  </c:filter>',
            '</c:calendar-query>'
        ]);

        $response = $this->reportRequest('/calendars/54b64eadf6d7d8e41d263e0f/calendar1', $body);

        $this->assertEquals(207, $response->status);

        $items = $this->parseMultiStatus($response->getBodyAsString());
        $href = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event1.ics';

        $this->assertArrayHasKey($href, $items);
        $decoded = json_decode($items[$href]['calendar-data'], true);
        $this->assertIsArray($decoded);
        $this->assertEquals('vcalendar', $decoded[0]);
    }

    function testTimeRangeFilterReportReturnsMatchingObjectsOnly() {
        $body = implode("\n", [
            '<?xml version="1.0" encoding="utf-8" ?>',
            '<c:calendar-query xmlns:d="DAV:" xmlns:c="' . self::CALDAV_NS . '">',
            '  <d:prop>',
            '    <d:getetag/>',
            '    <c:calendar-data/>',
            '  </d:prop>',
            '  <c:filter>',
            '    <c:comp-filter name="VCALENDAR">',
            '      <c:comp-filter name="VEVENT">',
            '        <c:time-range start="20130101T000000Z" end="20130501T000000Z"/>',
            '      </c:comp-filter>',
            '    </c:comp-filter>',
            '  </c:filter>',
            '</c:calendar-query>'
        ]);

        $response = $this->reportRequest('/calendars/54b64eadf6d7d8e41d263e0f/calendar1', $body);

        $this->assertEquals(207, $response->status);

        $items = $this->parseMultiStatus($response->getBodyAsString());

        // Only event2 (2013-04-01) falls inside the requested window.
        $this->assertCount(1, $items);
        $this->assertArrayHasKey('/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event2.ics', $items);
    }

    function testFilterlessReportReturnsFullPrivateDataToOwner() {
        $privateEvent = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:owner-private',
            'SUMMARY:Secret meeting',
            'LOCATION:Paris',
            'CLASS:PRIVATE',
            'DTSTART:20130401T090000Z',
            'DTEND:20130401T100000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ]) . "\r\n";
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'owner-private.ics', $privateEvent);

        $response = $this->reportRequest(
            '/calendars/54b64eadf6d7d8e41d263e0f/calendar1',
            $this->filterlessQuery()
        );

        $this->assertEquals(207, $response->status);

        $items = $this->parseMultiStatus($response->getBodyAsString());
        $href = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/owner-private.ics';

        $this->assertArrayHasKey($href, $items);

        // The authenticated user owns the calendar, so private details are
        // returned untouched (no "Busy" sanitization).
        $vObject = \Sabre\VObject\Reader::read($items[$href]['calendar-data']);
        $this->assertEquals('Secret meeting', (string) $vObject->VEVENT->SUMMARY);
        $this->assertEquals('Paris', (string) $vObject->VEVENT->LOCATION);
    }

    function testDepthZeroOnCalendarIsRejected() {
        $response = $this->reportRequest(
            '/calendars/54b64eadf6d7d8e41d263e0f/calendar1',
            $this->filterlessQuery(),
            '0'
        );

        $this->assertEquals(400, $response->status);
    }
}
