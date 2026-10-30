<?php
namespace ESN\JSON;

use \Sabre\VObject;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use DateTimeZone;
use ESN\Utils\Utils;

#[\AllowDynamicProperties]
class FreeBusyPlugin extends \ESN\JSON\BasePlugin {

    /**
     * This is the official CalDAV namespace
     */
    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    function initialize(Server $server) {
        parent::initialize($server);

        // Priority 75 so this runs before the handlers that resolve the request
        // path in the DAV tree: 'calendars/freebusy' is a virtual route, they
        // would 404 on it.
        $server->on('method:POST', [$this, 'httpPost'], 75);
    }

    /**
     * Returns a plugin name.
     *
     * Using this name other plugins will be able to access other plugins
     * using DAV\Server::getPlugin
     *
     * @return string
     */
    function getPluginName() {
        return 'caldav-freebusy';
    }

    /**
     * Returns a bunch of meta-data about the plugin.
     *
     * Providing this information is optional, and is mainly displayed by the
     * Browser plugin.
     *
     * The description key in the returned array may contain html and will not
     * be sanitized.
     *
     * @return array
     */
    function getPluginInfo() {
        return [
            'name'        => $this->getPluginName(),
            'description' => 'Adds Freebusy support for CalDAV',
            'link'        => 'http://sabre.io/dav/caldav/',
        ];
    }

    function httpPost($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $code = null;
        $body = null;

        if ($path == 'calendars/freebusy') {
            list($code, $body) = $this->getBulkFreeBusy(
                json_decode($request->getBodyAsString())
            );
        } else {
            return true;
        }

        return $this->send($code, $body);
    }

    function getBulkFreeBusy($params) {
        if (!$this->isValidBulkRequest($params)) {
            return [400, null];
        }

        $body = (object) [
            'start' => $params->start,
            'end' => $params->end,
            'users' => []
        ];

        foreach ($params->users as $userId) {
            $node = $this->getCalendarHome($userId);
            if (!is_null($node)) {
                $nodePath = 'calendars/' . $userId;
                $email = Utils::getPrincipalEmail($node->getOwner(), $this->server);
                $calendars = $this->getFreeBusyCalendars($nodePath, $node, $params, $email);

                array_push($body->users, (object) [
                    'id' => $userId,
                    'calendars' => $calendars
                ]);
            }
        }

        return [200, $body];
    }

    /**
     * Tells whether the body of a bulk free/busy request can be answered: an
     * object carrying a parsable start and end, the latter not before the
     * former, an array of users and, when given, a known time zone and an
     * array of uids.
     *
     * @return bool
     */
    private function isValidBulkRequest($params) {
        if (!is_object($params) || !isset($params->users) || !is_array($params->users)) {
            return false;
        }

        if (isset($params->timezone) && !$this->isValidTimeZone($params->timezone)) {
            return false;
        }

        if (isset($params->uids) && !is_array($params->uids)) {
            return false;
        }

        $start = $this->parseDateTime($params->start ?? null);
        $end = $this->parseDateTime($params->end ?? null);

        return !is_null($start) && !is_null($end) && $start <= $end;
    }

    /**
     * @return \DateTime|null the parsed date, null when it is not a non-empty
     *                        string \DateTime understands
     */
    private function parseDateTime($date) {
        if (!is_string($date) || trim($date) === '') {
            return null;
        }

        try {
            return new \DateTime($date);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Returns the calendar home of a user, null when the given id does not
     * designate one: not a string, an unknown user, or a path that would
     * reach some other node of the calendars tree.
     *
     * @return \Sabre\CalDAV\CalendarHome|null
     */
    private function getCalendarHome($userId) {
        if (!is_string($userId) || $userId === '' || strpos($userId, '/') !== false) {
            return null;
        }

        $node = $this->server->tree->getNodeForPath('calendars/' . $userId);

        return $node instanceof \Sabre\CalDAV\CalendarHome ? $node : null;
    }

    function getFreeBusyCalendars($nodePath, $node, $params, $email) {
        // Floating dates and times, such as those of all-day events, are read
        // in the requested time zone: an all-day event is a whole day there,
        // from its midnight to the next. UTC when none is given.
        $timeZone = new DateTimeZone(isset($params->timezone) ? $params->timezone : 'UTC');
        $start = (new \DateTime($params->start))->setTimezone($timeZone);
        $end = (new \DateTime($params->end))->setTimezone($timeZone);

        $items = [];
        foreach ($node->getChildren() as $calendar) {
            if (!$this->isCalendar($calendar) || !$this->hasFreebusyRight($nodePath, $calendar)) {
                continue;
            }

            $items[] = (object) [
                'id' => $calendar->getName(),
                'busy' => $this->getCalendarBusyPeriods($calendar, $params, $start, $end, $timeZone, $email)
            ];
        }

        return $items;
    }

    /**
     * Returns the busy periods of one calendar over the requested window.
     *
     * @return array the busy periods, possibly empty
     */
    private function getCalendarBusyPeriods($calendar, $params, $start, $end, $timeZone, $email) {
        // The occurrences the calendar backend indexes resolve floating dates
        // and times in UTC: the window is widened by a day, more than any UTC
        // offset, so that an all-day event of the requested time zone is not
        // missed. The occurrences outside of the actual window are then dropped
        // by the expansion.
        $busyEventUris = $calendar->calendarQuery([
            'name'         => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name'           => 'VEVENT',
                    'comp-filters'   => [],
                    'prop-filters'   => [],
                    'is-not-defined' => false,
                    'time-range'     => [
                        'start' => (clone $start)->modify('-1 day'),
                        'end'   => (clone $end)->modify('+1 day'),
                    ],
                ],
            ],
            'prop-filters'   => [],
            'is-not-defined' => false,
            'time-range'     => null,
        ]);

        $busyPeriods = [];
        foreach ($busyEventUris as $eventUri) {
            $busyPeriods = array_merge(
                $busyPeriods,
                $this->getEventBusyPeriods($calendar, $eventUri, $start, $end, $timeZone, $email)
            );
        }

        if (isset($params->uids)) {
            // The uid of an occurrence is the one of its master event, so this
            // excludes every occurrence of a filtered out event.
            $busyPeriods = array_filter($busyPeriods, function($busy) use ($params) {
                return !in_array($busy->uid, $params->uids);
            });
        }

        // array_values() unconditionally: array_filter() preserves keys, and
        // json_encode() turns an array with a hole in its keys into an object.
        // 'busy' must always serialize as a JSON array.
        return array_values($busyPeriods);
    }

    /**
     * Returns the busy periods a single calendar object occupies within the
     * requested window, one per occurrence.
     *
     * A recurring event has to be expanded: its master VEVENT only carries the
     * dates of the first occurrence, which are usually nowhere near the window
     * the caller asked about. VCalendar::expand() returns one VEVENT per
     * occurrence, converted to UTC, with the RECURRENCE-ID overrides and the
     * EXDATEs applied.
     *
     * @return array the busy periods, possibly empty
     */
    private function getEventBusyPeriods($calendar, $eventUri, $start, $end, $timeZone, $email) {
        $vObject = VObject\Reader::read($calendar->getChild($eventUri)->get());

        try {
            $expandedVObject = $vObject->expand($start, $end, $timeZone);
        } catch (VObject\InvalidDataException $e) {
            // A recurring VEVENT without a UID cannot be expanded. Drop that one
            // calendar object rather than failing the whole bulk request.
            $this->server->getLogger()->error(
                'Free/busy: ignoring ' . $calendar->getName() . '/' . $eventUri . ': ' . $e->getMessage()
            );

            return [];
        }

        $busyPeriods = [];
        foreach ($expandedVObject->select('VEVENT') as $vevent) {
            // Both checks are per occurrence: an override may carry a different
            // PARTSTAT, TRANSP or STATUS than its master event.
            if ($this->doesNotOccupyTime($vevent)) {
                continue;
            }

            if ($vevent->ATTENDEE && Utils::hasPrincipalDeclinedEvent($vevent, $email)) {
                continue;
            }

            $busyPeriods[] = $this->buildBusyPeriod($vevent, $timeZone);
        }

        return $busyPeriods;
    }

    /**
     * Tells whether an occurrence should be left out of the busy periods
     * because it does not consume any time, the way RFC 4791 section 7.10
     * builds a free-busy report: an event marked TRANSPARENT is explicitly
     * excluded from free/busy, and a CANCELLED one no longer takes place.
     *
     * @return bool
     */
    private function doesNotOccupyTime($vevent) {
        if (isset($vevent->TRANSP) && strtoupper(trim((string) $vevent->TRANSP)) === 'TRANSPARENT') {
            return true;
        }

        return isset($vevent->STATUS) && strtoupper(trim((string) $vevent->STATUS)) === 'CANCELLED';
    }

    /**
     * Builds the busy period of a single, already expanded, occurrence.
     *
     * Both dates are converted to UTC before being formatted, so that the
     * trailing Z of the response is not a lie for an event carrying a TZID.
     * The expansion converts every DATE-TIME to UTC but leaves DATE values
     * floating: those are read in the requested time zone.
     *
     * A VEVENT with neither DTEND nor DURATION is given the duration RFC 5545
     * section 3.6.1 defines for it: none when DTSTART is a DATE-TIME, one full
     * day when DTSTART is a DATE.
     *
     * @return object the busy period, as {uid, start, end}
     */
    private function buildBusyPeriod($vevent, $timeZone) {
        $utc = new DateTimeZone('UTC');
        $startDate = $vevent->DTSTART->getDateTime($timeZone);

        if (isset($vevent->DTEND)) {
            $endDate = $vevent->DTEND->getDateTime($timeZone);
        } elseif (isset($vevent->DURATION)) {
            $endDate = (clone $startDate)->add(VObject\DateTimeParser::parse($vevent->DURATION->getValue()));
        } elseif (!$vevent->DTSTART->hasTime()) {
            $endDate = (clone $startDate)->modify('+1 day');
        } else {
            $endDate = clone $startDate;
        }

        return (object) [
            'uid' => (string) $vevent->UID,
            'start' => $startDate->setTimezone($utc)->format('Ymd\\THis\\Z'),
            'end' => $endDate->setTimezone($utc)->format('Ymd\\THis\\Z')
        ];
    }

    private function isValidTimeZone($timeZone) {
        if (!is_string($timeZone)) {
            return false;
        }

        try {
            new DateTimeZone($timeZone);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    function isCalendar($calendar) {
        return ($calendar instanceof \ESN\CalDAV\SharedCalendar) && !$calendar->isSharedInstance();
    }

    function hasFreebusyRight($nodePath, $calendar) {
        $right = '{' . Plugin::NS_CALDAV . '}read-free-busy';

        return $this->server->getPlugin('acl')->checkPrivileges($nodePath . '/' . $calendar->getName(), $right, \Sabre\DAVACL\Plugin::R_PARENT, false);
    }
}
