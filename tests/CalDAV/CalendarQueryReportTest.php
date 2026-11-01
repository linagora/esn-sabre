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

    function setUp(): void {
        parent::setUp();

        // Mirrors esn.php: calendar-data of hidden private events is sanitized
        $this->server->addPlugin(new PrivateEventPlugin());
    }

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

    function testTimeRangeFilterReportReturnsLaterDetachedOccurrenceWithoutMaster() {
        $calendarData = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:detached-instances',
            'RECURRENCE-ID:20260901T090000Z',
            'DTSTART:20260901T090000Z',
            'DTEND:20260901T100000Z',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:detached-instances',
            'RECURRENCE-ID:20260915T090000Z',
            'DTSTART:20260915T090000Z',
            'DTEND:20260915T100000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ]) . "\r\n";
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'detached-instances.ics', $calendarData);

        $body = implode("\n", [
            '<?xml version="1.0" encoding="utf-8" ?>',
            '<c:calendar-query xmlns:d="DAV:" xmlns:c="' . self::CALDAV_NS . '">',
            '  <d:prop><c:calendar-data/></d:prop>',
            '  <c:filter>',
            '    <c:comp-filter name="VCALENDAR">',
            '      <c:comp-filter name="VEVENT">',
            '        <c:time-range start="20260915T080000Z" end="20260915T110000Z"/>',
            '      </c:comp-filter>',
            '    </c:comp-filter>',
            '  </c:filter>',
            '</c:calendar-query>'
        ]);

        $response = $this->reportRequest('/calendars/54b64eadf6d7d8e41d263e0f/calendar1', $body);

        $this->assertEquals(207, $response->status);

        $items = $this->parseMultiStatus($response->getBodyAsString());
        $href = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/detached-instances.ics';
        $this->assertArrayHasKey($href, $items);

        $vObject = \Sabre\VObject\Reader::read($items[$href]['calendar-data']);
        $recurrenceIds = array_map(function ($event) {
            return (string) $event->{'RECURRENCE-ID'};
        }, $vObject->select('VEVENT'));
        $this->assertContains('20260915T090000Z', $recurrenceIds);
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

    function testTimeRangeFilterReportOmitsLongRunningSeriesBeyondTheDefaultRecurrenceLimit() {
        $this->withRecurrenceLimit(null, function () {
            $items = $this->queryJanuary2026WithLongRunningDailySeries();

            $this->assertArrayNotHasKey(self::LONG_RUNNING_DAILY_HREF, $items);
        });
    }

    function testTimeRangeFilterReportReturnsLongRunningSeriesWithARaisedRecurrenceLimit() {
        $this->withRecurrenceLimit(10000, function () {
            $items = $this->queryJanuary2026WithLongRunningDailySeries();

            $this->assertArrayHasKey(self::LONG_RUNNING_DAILY_HREF, $items);
        });
    }

    function testTimeRangeFilterReportReturnsLongRunningSeriesWithADisabledRecurrenceLimit() {
        $this->withRecurrenceLimit(-1, function () {
            $items = $this->queryJanuary2026WithLongRunningDailySeries();

            $this->assertArrayHasKey(self::LONG_RUNNING_DAILY_HREF, $items);
        });
    }

    const LONG_RUNNING_DAILY_HREF = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/long-running-daily.ics';

    private function withRecurrenceLimit($configuredLimit, callable $assertions) {
        $originalLimit = \Sabre\VObject\Settings::$maxRecurrences;
        \ESN\Utils\Env::init($configuredLimit === null ? [] : [RecurrenceExpansionLimit::SETTING => $configuredLimit]);
        try {
            RecurrenceExpansionLimit::apply();
            $assertions();
        } finally {
            \Sabre\VObject\Settings::$maxRecurrences = $originalLimit;
            \ESN\Utils\Env::reset();
        }
    }

    /**
     * A daily series started in 2010 needs ~5800 occurrences to reach 2026.
     */
    private function queryJanuary2026WithLongRunningDailySeries() {
        $calendarData = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:long-running-daily',
            'DTSTART:20100101T090000Z',
            'DTEND:20100101T100000Z',
            'RRULE:FREQ=DAILY',
            'SUMMARY:Daily stand-up',
            'END:VEVENT',
            'END:VCALENDAR'
        ]) . "\r\n";
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'long-running-daily.ics', $calendarData);

        $body = implode("\n", [
            '<?xml version="1.0" encoding="utf-8" ?>',
            '<c:calendar-query xmlns:d="DAV:" xmlns:c="' . self::CALDAV_NS . '">',
            '  <d:prop><d:getetag/></d:prop>',
            '  <c:filter>',
            '    <c:comp-filter name="VCALENDAR">',
            '      <c:comp-filter name="VEVENT">',
            '        <c:time-range start="20260115T000000Z" end="20260116T000000Z"/>',
            '      </c:comp-filter>',
            '    </c:comp-filter>',
            '  </c:filter>',
            '</c:calendar-query>'
        ]);

        $response = $this->reportRequest('/calendars/54b64eadf6d7d8e41d263e0f/calendar1', $body);

        $this->assertEquals(207, $response->status);

        return $this->parseMultiStatus($response->getBodyAsString());
    }

    private function createHiddenPrivateEvent($calendarId, $uri, array $extraLines = []) {
        $this->caldavBackend->createCalendarObject($calendarId, $uri, implode("\r\n", array_merge([
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//test//EN',
            'BEGIN:VEVENT', 'UID:' . $uri, 'DTSTAMP:20260101T000000Z',
            'DTSTART:20260101T090000Z', 'DTEND:20260101T100000Z',
            'CLASS:PRIVATE', 'SUMMARY:Doctor', 'LOCATION:Hospital',
            'ATTENDEE;CN=Alice:mailto:alice@example.com'
        ], $extraLines, ['END:VEVENT', 'END:VCALENDAR', ''])));
    }

    private function vEventQuery($vEventFilter) {
        return '<?xml version="1.0"?><c:calendar-query xmlns:d="DAV:" xmlns:c="' . self::CALDAV_NS . '">'
            . '<d:prop><d:getetag/></d:prop><c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT">'
            . $vEventFilter
            . '</c:comp-filter></c:comp-filter></c:filter></c:calendar-query>';
    }

    private function locationQuery($needle) {
        return $this->vEventQuery('<c:prop-filter name="LOCATION"><c:text-match>' . $needle . '</c:text-match></c:prop-filter>');
    }

    private function reportHrefs($uri, $body, $depth = '1') {
        $response = $this->reportRequest($uri, $body, $depth);
        $this->assertEquals(207, $response->status);

        return array_keys($this->parseMultiStatus($response->getBodyAsString()));
    }

    function testFilterOnHiddenFieldDoesNotMatchPrivateEventForNonOwner() {
        $this->createHiddenPrivateEvent($this->publicCal['id'], 'secret.ics');
        $calendar = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1';
        $href = $calendar . '/secret.ics';

        $this->assertNotContains($href, $this->reportHrefs($calendar, $this->locationQuery('Hospital')));
        $this->assertNotContains($href, $this->reportHrefs($calendar, $this->locationQuery('Office')));
    }

    function testFilterOnHiddenFieldDoesNotMatchPrivateEventThroughSubscription() {
        $this->createHiddenPrivateEvent($this->publicCal['id'], 'secret.ics');
        $subscription = '/calendars/54b64eadf6d7d8e41d263e0f/subscription1';

        $this->assertNotContains($subscription . '/secret.ics', $this->reportHrefs($subscription, $this->locationQuery('Hospital')));
    }

    function testParamFilterOnHiddenAttendeeDoesNotMatchPrivateEventForNonOwner() {
        $this->createHiddenPrivateEvent($this->publicCal['id'], 'secret.ics');
        $calendar = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1';

        $hrefs = $this->reportHrefs($calendar, $this->vEventQuery(
            '<c:prop-filter name="ATTENDEE"><c:param-filter name="CN"><c:text-match>Alice</c:text-match></c:param-filter></c:prop-filter>'
        ));

        $this->assertNotContains($calendar . '/secret.ics', $hrefs);
    }

    function testHiddenAlarmDoesNotMatchPrivateEventForNonOwner() {
        $this->createHiddenPrivateEvent($this->publicCal['id'], 'secret.ics', [
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:Reminder', 'TRIGGER:-PT15M', 'END:VALARM'
        ]);
        $calendar = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1';

        $hrefs = $this->reportHrefs($calendar, $this->vEventQuery('<c:comp-filter name="VALARM"/>'));

        $this->assertNotContains($calendar . '/secret.ics', $hrefs);
    }

    function testIsNotDefinedOnHiddenFieldMatchesPrivateEventForNonOwner() {
        $this->createHiddenPrivateEvent($this->publicCal['id'], 'secret.ics', ['DESCRIPTION:Room 101']);
        $calendar = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1';

        $hrefs = $this->reportHrefs($calendar, $this->vEventQuery(
            '<c:prop-filter name="DESCRIPTION"><c:is-not-defined/></c:prop-filter>'
        ));

        // The sanitized event has no DESCRIPTION, whatever the stored one holds
        $this->assertContains($calendar . '/secret.ics', $hrefs);
    }

    function testFilterMatchesPlaceholderSummaryOfPrivateEventForNonOwner() {
        $this->createHiddenPrivateEvent($this->publicCal['id'], 'secret.ics');
        $calendar = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1';

        $busy = $this->reportHrefs($calendar, $this->vEventQuery(
            '<c:prop-filter name="SUMMARY"><c:text-match>Busy</c:text-match></c:prop-filter>'
        ));
        $doctor = $this->reportHrefs($calendar, $this->vEventQuery(
            '<c:prop-filter name="SUMMARY"><c:text-match>Doctor</c:text-match></c:prop-filter>'
        ));

        $this->assertContains($calendar . '/secret.ics', $busy);
        $this->assertNotContains($calendar . '/secret.ics', $doctor);
    }

    function testFilterOnVisibleFieldStillMatchesPrivateEventForNonOwner() {
        $this->createHiddenPrivateEvent($this->publicCal['id'], 'secret.ics');
        $calendar = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1';

        $hrefs = $this->reportHrefs($calendar, $this->vEventQuery(
            '<c:time-range start="20260101T000000Z" end="20260102T000000Z"/>'
        ));

        $this->assertContains($calendar . '/secret.ics', $hrefs);
    }

    function testFilterOnHiddenFieldStillMatchesPrivateEventForOwner() {
        $this->createHiddenPrivateEvent($this->cal['id'], 'secret.ics');
        $calendar = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1';

        $this->assertContains($calendar . '/secret.ics', $this->reportHrefs($calendar, $this->locationQuery('Hospital')));
        $this->assertNotContains($calendar . '/secret.ics', $this->reportHrefs($calendar, $this->locationQuery('Office')));
    }

    function testMicrosoftDepthZeroDoesNotMatchHiddenFieldOfPrivateEvent() {
        $this->createHiddenPrivateEvent($this->publicCal['id'], 'secret.ics');
        $calendar = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1';

        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_DEPTH'        => '0',
            'HTTP_USER_AGENT'   => 'MSFT-WIN-3/10.0',
            'REQUEST_URI'       => $calendar,
        ]);
        $request->setBody($this->locationQuery('Hospital'));
        $response = $this->request($request);

        $this->assertEquals(207, $response->status);
        $this->assertArrayNotHasKey($calendar . '/secret.ics', $this->parseMultiStatus($response->getBodyAsString()));
    }

    function testDepthZeroOnPrivateObjectDoesNotMatchHiddenField() {
        $this->createHiddenPrivateEvent($this->publicCal['id'], 'secret.ics');
        $href = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1/secret.ics';

        $this->assertNotContains($href, $this->reportHrefs($href, $this->locationQuery('Hospital'), '0'));
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
