<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';
require_once ESN_TEST_BASE . '/DAV/MongoFindCounter.php';

/**
 * Exercises ESN\CalDAV\Plugin::freeBusyQueryReport, which overrides the stock
 * Sabre implementation so that the busy events are not read one at a time
 * (linagora/esn-sabre#843).
 *
 * @medium
 */
class FreeBusyQueryReportTest extends \ESN\DAV\ServerMock {

    const CALENDAR_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1';
    const START = '20120101T000000Z';
    const END = '20160101T000000Z';

    private function freeBusyQuery($start, $end) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_DEPTH'        => '1',
            'REQUEST_URI'       => self::CALENDAR_PATH,
        ]);
        $request->setBody('<c:free-busy-query xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<c:time-range start="' . $start . '" end="' . $end . '"/>'
            . '</c:free-busy-query>');

        return $this->request($request);
    }

    private function freeBusyPeriods($icalendar) {
        $vcalendar = \Sabre\VObject\Reader::read($icalendar);
        $periods = array_map(fn($property) => (string) $property, iterator_to_array($vcalendar->VFREEBUSY->select('FREEBUSY'), false));
        $vcalendar->destroy();

        return $periods;
    }

    private function expectedFreeBusyPeriods() {
        $generator = new \Sabre\VObject\FreeBusyGenerator();
        $generator->setObjects(array_values($this->caldavCalendarObjects));
        $generator->setTimeRange(new \DateTime(self::START), new \DateTime(self::END));
        $generator->setTimeZone(new \DateTimeZone('UTC'));

        return $this->freeBusyPeriods($generator->getResult()->serialize());
    }

    function testFreeBusyReadsTheBusyEventsWithoutOneQueryPerEvent() {
        for ($i = 0; $i < 20; $i++) {
            $this->caldavBackend->createCalendarObject($this->cal['id'], 'busy' . $i . '.ics',
                str_replace(
                    ['UID:event2', 'DTSTART:20130401T000000Z', 'DTEND:20130401T010000Z'],
                    ['UID:busy' . $i, sprintf('DTSTART:201304%02dT000000Z', $i + 2), sprintf('DTEND:201304%02dT010000Z', $i + 2)],
                    $this->caldavCalendarObjects['event2.ics']));
        }

        $queries = \ESN\DAV\MongoFindCounter::count('calendarobjects', function () use (&$response) {
            $response = $this->freeBusyQuery('20130401T000000Z', '20130501T000000Z');
        });

        $this->assertEquals(200, $response->status);
        $this->assertCount(21, $this->freeBusyPeriods($response->getBodyAsString()));
        $this->assertLessThanOrEqual(2, $queries);
    }

    function testFreeBusyOfSingleAndRecurringEventsIsTheOneOfTheStockImplementation() {
        $response = $this->freeBusyQuery(self::START, self::END);

        $this->assertEquals(200, $response->status);
        $this->assertEquals('text/calendar', $response->getHeader('Content-Type'));
        $this->assertNotEmpty($this->expectedFreeBusyPeriods());
        $this->assertEquals($this->expectedFreeBusyPeriods(), $this->freeBusyPeriods($response->getBodyAsString()));
    }

    function testFreeBusyOfEmptyRangeHasNoBusyPeriod() {
        $response = $this->freeBusyQuery('20000101T000000Z', '20000102T000000Z');

        $this->assertEquals(200, $response->status);
        $this->assertEmpty($this->freeBusyPeriods($response->getBodyAsString()));
    }
}
