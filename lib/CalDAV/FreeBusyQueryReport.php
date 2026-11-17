<?php
namespace ESN\CalDAV;

use DateTimeZone;
use Sabre\CalDAV\ICalendar;
use Sabre\DAV\Exception\NotImplemented;
use Sabre\DAV\IMultiGet;
use Sabre\DAV\Server;
use Sabre\VObject\FreeBusyGenerator;
use Sabre\VObject\Reader;

/**
 * Answers the CALDAV:free-busy-query REPORT.
 *
 * The stock Sabre implementation runs the time-range query to get the matching
 * URIs, then reads every matching event one at a time through getChild(): one
 * Mongo find per busy event (linagora/esn-sabre#843). Here the events come with
 * their data straight from the time-range query on a Mongo calendar, or from a
 * single batched read on any other multi-get calendar.
 */
class FreeBusyQueryReport {
    const NS_CALDAV = \Sabre\CalDAV\Plugin::NS_CALDAV;
    const CALENDAR_TIMEZONE = '{' . self::NS_CALDAV . '}calendar-timezone';

    private $server;

    function __construct(Server $server) {
        $this->server = $server;
    }

    /**
     * @param \Sabre\CalDAV\Xml\Request\FreeBusyQueryReport $report
     * @return void
     */
    function freeBusyQueryReport($report) {
        $uri = $this->server->getRequestUri();

        $this->checkReadFreeBusyPrivilege($uri);
        $calendar = $this->calendarAt($uri);

        $generator = new FreeBusyGenerator();
        $generator->setObjects($this->eventsInRange($calendar, $this->timeRangeFilters($report)));
        $generator->setTimeRange($report->start, $report->end);
        $generator->setTimeZone($this->calendarTimeZone($uri));

        $this->sendFreeBusy($generator->getResult()->serialize());
    }

    private function checkReadFreeBusyPrivilege($uri) {
        $acl = $this->server->getPlugin('acl');
        if ($acl) {
            $acl->checkPrivileges($uri, '{' . self::NS_CALDAV . '}read-free-busy');
        }
    }

    private function calendarAt($uri): ICalendar {
        $calendar = $this->server->tree->getNodeForPath($uri);
        if (!$calendar instanceof ICalendar) {
            throw new NotImplemented('The free-busy-query REPORT is only implemented on calendars');
        }

        return $calendar;
    }

    /**
     * The timezone of the calendar, used for floating times.
     */
    private function calendarTimeZone($uri): DateTimeZone {
        $calendarProps = $this->server->getProperties($uri, [self::CALENDAR_TIMEZONE]);
        if (!isset($calendarProps[self::CALENDAR_TIMEZONE])) {
            return new DateTimeZone('UTC');
        }

        $vtimezoneObj = Reader::read($calendarProps[self::CALENDAR_TIMEZONE]);
        $timeZone = $vtimezoneObj->VTIMEZONE->getTimeZone();
        // Destroy circular references so PHP will garbage collect the object.
        $vtimezoneObj->destroy();

        return $timeZone;
    }

    /**
     * The calendar-query filters Sabre uses: the VEVENTs overlapping the report time range.
     *
     * @param \Sabre\CalDAV\Xml\Request\FreeBusyQueryReport $report
     */
    private function timeRangeFilters($report): array {
        return [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => $report->start,
                        'end' => $report->end,
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];
    }

    /**
     * The events matching $filters, either parsed or as their iCalendar data.
     */
    private function eventsInRange(ICalendar $calendar, array $filters): array {
        if ($this->isMongoSharedCalendar($calendar)) {
            return $this->eventsFromSingleQuery($calendar, $filters);
        }

        return array_map(fn($child) => $child->get(), $this->childrenOf($calendar, $calendar->calendarQuery($filters)));
    }

    private function isMongoSharedCalendar(ICalendar $calendar): bool {
        return $calendar instanceof SharedCalendar && $calendar->getBackend() instanceof Backend\Mongo;
    }

    /**
     * The time-range query already returns the data of the matching events (and
     * the events it had to parse to apply the filter), so no further read is needed.
     */
    private function eventsFromSingleQuery(SharedCalendar $calendar, array $filters): array {
        return array_map(
            fn($object) => $object['vObject'] ?? $object['calendardata'],
            iterator_to_array($calendar->getBackend()->calendarQueryWithAllData($calendar->getFullCalendarId(), $filters), false)
        );
    }

    private function childrenOf(ICalendar $calendar, array $uris): array {
        if ($calendar instanceof IMultiGet) {
            return $calendar->getMultipleChildren($uris);
        }

        return array_map(fn($uri) => $calendar->getChild($uri), $uris);
    }

    private function sendFreeBusy($result) {
        $this->server->httpResponse->setStatus(200);
        $this->server->httpResponse->setHeader('Content-Type', 'text/calendar');
        $this->server->httpResponse->setHeader('Content-Length', strlen($result));
        $this->server->httpResponse->setBody($result);
    }
}
