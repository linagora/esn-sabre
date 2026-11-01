<?php
namespace ESN\CalDAV;

use ESN\Utils\Utils;
use Sabre\CalDAV\CalendarQueryValidator;
use Sabre\CalDAV\ICalendarObjectContainer;
use Sabre\VObject\Reader;

/**
 * Evaluates calendar-query filters on what the current user can read of a calendar.
 *
 * A calendar hides the details of its PRIVATE / CONFIDENTIAL events from some users (delegates,
 * sharees, public readers, subscribers: see Utils::hidesPrivateEventsFrom), who only ever get
 * their sanitized form. The calendar-query matching is done by the backend on the stored objects:
 * matching a hidden field there would tell such a user, through the set of hrefs returned, what the
 * hidden fields contain. This class makes the result depend on the sanitized form only:
 * - private objects matched on their stored form are dropped,
 * - every private object of the calendar is then matched again on its sanitized form, which can
 *   match where the stored one does not (is-not-defined, a text-match on the placeholder SUMMARY).
 */
class PrivateEventCalendarQuery {

    /** @var ICalendarObjectContainer */
    private $calendar;

    /** @var string */
    private $currentUser;

    private function __construct(ICalendarObjectContainer $calendar, $currentUser) {
        $this->calendar = $calendar;
        $this->currentUser = $currentUser;
    }

    /**
     * @param mixed $calendar The calendar the query runs against
     * @param string|null $currentUser
     * @return PrivateEventCalendarQuery|null null when the calendar shows its private events as they
     *         are to this user, so the stored objects can be matched directly
     */
    static function forCalendar($calendar, $currentUser) {
        if (!self::hidesPrivateEvents($calendar, $currentUser)) {
            return null;
        }

        return new self($calendar, $currentUser);
    }

    /**
     * Whether the PRIVATE / CONFIDENTIAL events of this calendar are sanitized for this user.
     *
     * @param mixed $calendar
     * @param string|null $currentUser
     * @return bool
     */
    static function hidesPrivateEvents($calendar, $currentUser) {
        if (!$currentUser || !($calendar instanceof ICalendarObjectContainer)) {
            return false;
        }
        if (!method_exists($calendar, 'getOwner') && !method_exists($calendar, 'getSourceOwner')) {
            return false;
        }

        return Utils::hidesPrivateEventsFrom($calendar, $currentUser);
    }

    /**
     * Fixes the URIs the backend matched on the stored objects so that private objects are matched on
     * their sanitized form instead.
     *
     * @param string[] $matchedUris URIs matched on the stored objects
     * @param array $filters calendar-query filters
     * @return string[]
     */
    function filterUris(array $matchedUris, array $filters) {
        $privateObjects = $this->getPrivateObjects();

        $result = [];
        foreach ($matchedUris as $uri) {
            if (!array_key_exists($uri, $privateObjects)) {
                $result[] = $uri;
            }
        }
        foreach ($privateObjects as $uri => $calendarData) {
            if ($this->matches($calendarData, $filters)) {
                $result[] = $uri;
            }
        }

        return $result;
    }

    /**
     * Whether the sanitized form of this calendar object matches the filters.
     *
     * @param string $calendarData The stored calendar object
     * @param array $filters calendar-query filters
     * @return bool
     */
    function matches($calendarData, array $filters) {
        try {
            $vObject = Reader::read($calendarData);
        } catch (\Exception $e) {
            return false;
        }

        $sanitized = Utils::hidePrivateEventInfoForUser($vObject, $this->calendar, $this->currentUser);
        try {
            return (new CalendarQueryValidator())->validate($sanitized, $filters);
        } catch (\Exception $e) {
            // Same leniency as the backend: a malformed object does not fail the whole REPORT
            return false;
        } finally {
            $sanitized->destroy();
            $vObject->destroy();
        }
    }

    /**
     * @return array calendar data of the PRIVATE / CONFIDENTIAL objects, indexed by URI
     */
    private function getPrivateObjects() {
        $result = [];

        if (method_exists($this->calendar, 'getPrivateCalendarObjects')) {
            $objects = $this->calendar->getPrivateCalendarObjects();
            if ($objects !== null) {
                foreach ($objects as $object) {
                    $result[$object['uri']] = $object['calendardata'];
                }

                return $result;
            }
        }

        // Backends that cannot select on the classification: look at every object
        foreach ($this->calendar->getChildren() as $child) {
            $calendarData = $child->get();
            if (is_resource($calendarData)) {
                $calendarData = stream_get_contents($calendarData);
            }
            if ($this->isPrivate($calendarData)) {
                $result[$child->getName()] = $calendarData;
            }
        }

        return $result;
    }

    private function isPrivate($calendarData) {
        try {
            $vObject = Reader::read($calendarData);
        } catch (\Exception $e) {
            return false;
        }

        // Any private VEVENT: matching a non-private object on its sanitized form changes nothing
        $isPrivate = false;
        foreach ($vObject->select('VEVENT') as $vevent) {
            $isPrivate = $isPrivate || Utils::isPrivateClassification((string) $vevent->CLASS);
        }
        $vObject->destroy();

        return $isPrivate;
    }
}
