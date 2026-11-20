<?php

namespace ESN\CalDAV\Backend\Service;

use ESN\Utils\Utils;
use ESN\Utils\VObjectCache;
use \Sabre\DAV\Exception\BadRequest;
use \Sabre\VObject;
use \Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;

/**
 * Calendar Data Normalizer
 *
 * Handles normalization and extraction of calendar data metadata including:
 * - Component type detection
 * - UID extraction
 * - Occurrence date calculations (first/last)
 * - Recurring event handling
 * - ETags and size calculation
 */
class CalendarDataNormalizer {
    const MAX_DATE = '2038-01-01';

    /** @var VObjectCache */
    private $vObjectCache;

    /**
     * @param VObjectCache|null $vObjectCache Shared with the plugins handling the
     *                                        request, so denormalizing the payload
     *                                        a plugin just parsed costs nothing.
     */
    public function __construct(?VObjectCache $vObjectCache = null) {
        $this->vObjectCache = $vObjectCache ?: new VObjectCache();
    }

    /**
     * Extract denormalized metadata from calendar data
     *
     * @param string $calendarData iCalendar data
     * @return array Array with etag, size, componentType, firstOccurence, lastOccurence, uid, classification, recurring
     * @throws \Sabre\DAV\Exception\BadRequest If no valid component found
     */
    public function getDenormalizedData($calendarData) {
        // Read only: every helper below inspects the tree without touching it,
        // so the shared document can be used as is.
        $vObject = $this->vObjectCache->read($calendarData);

        $component = $this->findMainComponent($vObject);
        $this->validateComponent($component);

        $componentType = $component->name;
        $uid = (string) $component->UID;
        $classification = $this->calculateClassification($vObject);

        list($firstOccurence, $lastOccurence) = $this->calculateOccurrences($component, $vObject);

        return [
            'etag' => md5($calendarData),
            'size' => strlen($calendarData),
            'componentType' => $componentType,
            'firstOccurence' => $firstOccurence,
            'lastOccurence'  => $lastOccurence,
            'uid' => $uid,
            'classification' => $classification,
            'recurring' => $this->isRecurring($vObject),
        ];
    }

    /**
     * Whether the occurrence range may be a hull rather than the exact bounds
     * of a single event: a time-range calendar-query can then only be answered
     * by expanding the object.
     *
     * A lone VEVENT without RRULE nor RDATE has a single instance whose bounds
     * are exactly the stored firstoccurence / lastoccurence, computed as
     * VEvent::isInTimeRange computes them.
     *
     * @param VObject\Component\VCalendar $vObject
     * @return bool
     */
    private function isRecurring($vObject) {
        $events = array_values($vObject->select('VEVENT'));

        return count($events) !== 1
            || isset($events[0]->RRULE)
            || isset($events[0]->RDATE);
    }

    /**
     * Every read-side privacy gate trusts the stored classification: an object
     * is private as soon as one of its components is, typically a private
     * override of a public series, whatever the component order.
     *
     * @param VObject\Component\VCalendar $vObject
     * @return string|null The first private CLASS, else the first CLASS
     */
    private function calculateClassification($vObject) {
        $classification = null;
        foreach ($vObject->getComponents() as $component) {
            if ($component->name === 'VTIMEZONE' || !isset($component->CLASS)) {
                continue;
            }
            $value = strtoupper((string) $component->CLASS);
            if ($classification === null
                || (Utils::isPrivateClassification($value) && !Utils::isPrivateClassification($classification))) {
                $classification = $value;
            }
        }
        return $classification;
    }

    /**
     * Find the main component (non-VTIMEZONE)
     *
     * @param VObject\Component\VCalendar $vObject
     * @return VObject\Component|null Main component or null
     */
    private function findMainComponent($vObject) {
        foreach($vObject->getComponents() as $component) {
            if ($component->name !== 'VTIMEZONE') {
                return $component;
            }
        }
        return null;
    }

    /**
     * Validate that a valid component was found
     *
     * @param VObject\Component|null $component
     * @throws \Sabre\DAV\Exception\BadRequest If component is null
     */
    private function validateComponent($component) {
        if (!$component) {
            throw new \Sabre\DAV\Exception\BadRequest('Calendar objects must have a VJOURNAL, VEVENT or VTODO component');
        }
    }

    /**
     * Calculate first and last occurrence timestamps
     *
     * @param VObject\Component $component
     * @param VObject\Component\VCalendar $vObject
     * @return array [firstOccurence, lastOccurence] or [null, null] for non-VEVENT
     */
    private function calculateOccurrences($component, $vObject) {
        if ($component->name !== 'VEVENT') {
            return [null, null];
        }

        $firstOccurence = null;
        $lastOccurence = null;

        // An attendee calendar object may contain detached instances without a
        // recurring master. Include every stored VEVENT in the denormalized
        // range so MongoDB does not discard later instances before post-filtering.
        foreach ($vObject->select('VEVENT') as $event) {
            $this->validateEventDates($event);

            list($start, $end) = $this->calculateEventWindow($event);
            // A negative DURATION, or an infinite rule starting after the
            // MAX_DATE fallback, must never yield a window ending before it starts.
            $end = max($start, $end);

            $firstOccurence = $firstOccurence === null ? $start : min($firstOccurence, $start);
            $lastOccurence = $lastOccurence === null ? $end : max($lastOccurence, $end);
        }

        return [$firstOccurence, $lastOccurence];
    }

    /**
     * Ensure the dates the occurrence range is computed from can be read:
     * neither the CalDAV write pipeline nor iTIP delivery guarantee that
     * DTSTART/DTEND hold date-times (VALUE=PERIOD is legal syntax) nor that
     * DURATION is non empty.
     *
     * @param VObject\Component $event
     * @throws BadRequest If a date property cannot be read
     */
    private function validateEventDates($event) {
        if (!$event->DTSTART instanceof DateTimeProperty) {
            throw new BadRequest('VEVENT must have a DTSTART holding a DATE or DATE-TIME value');
        }

        if (isset($event->DTEND) && !$event->DTEND instanceof DateTimeProperty) {
            throw new BadRequest('VEVENT DTEND must hold a DATE or DATE-TIME value');
        }

        if (isset($event->DURATION)) {
            $this->parseDuration($event->DURATION);
        }
    }

    /**
     * @param VObject\Property $duration
     * @return \DateInterval
     * @throws BadRequest If the value is not a valid DURATION
     */
    private function parseDuration($duration) {
        $value = (string) $duration->getValue();

        try {
            // An empty value is not rejected by the parser but read out of bounds.
            if ($value !== '') {
                return VObject\DateTimeParser::parseDuration($value);
            }
        } catch (VObject\InvalidDataException $e) {
            // Rejected below, like an empty value
        }

        throw new BadRequest('VEVENT DURATION must hold a valid DURATION value');
    }

    /**
     * Calculate the first and last occurrence timestamps of a VEVENT
     *
     * A recurring event (RRULE or RDATE: only the iterator sees the instances
     * an RDATE generates, possibly before DTSTART) is expanded by a single
     * iterator: its first position gives the start, and the end walk goes on
     * from there.
     *
     * @param VObject\Component $component
     * @return int[] [first occurrence, last occurrence]
     */
    private function calculateEventWindow($component) {
        $start = $component->DTSTART->getDateTime()->getTimeStamp();

        if (!isset($component->RRULE) && !isset($component->RDATE)) {
            return [$start, $this->calculateSimpleEndDate($component)];
        }

        try {
            $it = new VObject\Recur\EventIterator($component);

            // The iterator yields instances in chronological order, RDATE values
            // included, so its first instance is the earliest one.
            $first = $it->valid() ? $it->getDtStart()->getTimeStamp() : $start;
        } catch (\Exception $e) {
            // The start falls back to DTSTART, the end to an open-ended window.
            return [$start, $this->openEndedFallback($component, $e)];
        }

        return [min($start, $first), $this->calculateRecurringEndDate($component, $it)];
    }

    /**
     * Calculate end date for non-recurring event
     *
     * @param VObject\Component $component
     * @return int End timestamp
     */
    private function calculateSimpleEndDate($component) {
        // Has explicit DTEND
        if (isset($component->DTEND)) {
            return $component->DTEND->getDateTime()->getTimeStamp();
        }

        // Has DURATION
        if (isset($component->DURATION)) {
            $endDate = $component->DTSTART->getDateTime()->add(
                $this->parseDuration($component->DURATION)
            );
            return $endDate->getTimeStamp();
        }

        // All-day event (no time)
        if (!$component->DTSTART->hasTime()) {
            $endDate = $component->DTSTART->getDateTime()->modify('+1 day');
            return $endDate->getTimeStamp();
        }

        // Instantaneous event (same as start)
        return $component->DTSTART->getDateTime()->getTimeStamp();
    }

    /**
     * Calculate end date for recurring event
     *
     * The event is expanded on its own, as the calendar-query post-filter
     * does (VEvent::isInTimeRange). An override is a single instance: the
     * iterator ignores an RRULE or RDATE it carries. Instances moved by an
     * override are covered by that override's own entry.
     *
     * @param VObject\Component $component
     * @param VObject\Recur\EventIterator $it rewound on the first instance
     * @return int End timestamp
     */
    private function calculateRecurringEndDate($component, $it) {
        $maxDate = new \DateTime(self::MAX_DATE);

        try {
            if ($it->isInfinite()) {
                return $maxDate->getTimeStamp();
            }

            $bound = $this->recurrenceRuleBound($component, $it, $maxDate);
            if ($bound !== null) {
                return $bound;
            }

            return $this->findLastRecurrenceDate($it, $maxDate);
        } catch (\Exception $e) {
            return $this->openEndedFallback($component, $e);
        }
    }

    /**
     * Be lenient: a malformed recurrence (e.g. an invalid RRULE UNTIL value) must not
     * crash the whole request. We log the problem and fall back to treating the event as
     * open-ended so it stays discoverable by time-range queries instead of being lost.
     *
     * @param VObject\Component $component
     * @param \Exception $e
     * @return int MAX_DATE
     */
    private function openEndedFallback($component, \Exception $e) {
        error_log('CalendarDataNormalizer: could not expand recurrence for UID '
            . (string) $component->UID . ', falling back to open-ended: ' . $e->getMessage());

        return (new \DateTime(self::MAX_DATE))->getTimeStamp();
    }

    /**
     * The end of the window of a series bounded by its RRULE alone, without
     * walking its instances, or null when the instances must be walked.
     *
     * The window of a recurring row is a hull, always post-filtered: it must
     * never end before an instance does, and may end later.
     * - UNTIL, without COUNT nor RDATE: no instance starts after UNTIL
     *   (RFC 5545 3.3.10), and each one lasts as long as the master (EXDATE
     *   only removes instances). A day is added when UNTIL is a DATE or the
     *   times are floating or all-day, whose reading depends on a time zone.
     * - COUNT above Settings::$maxRecurrences: the walk would give up at the
     *   cap and fall back to MAX_DATE anyway.
     *
     * @param VObject\Component $component
     * @param VObject\Recur\EventIterator $it rewound on the first instance
     * @param \DateTime $maxDate
     * @return int|null
     */
    private function recurrenceRuleBound($component, $it, $maxDate) {
        // The iterator ignores the RRULE of an override and prefers RDATE to it.
        if (isset($component->{'RECURRENCE-ID'}) || isset($component->RDATE)
            || count($component->select('RRULE')) !== 1) {
            return null;
        }

        $parts = array_change_key_case($component->RRULE->getParts(), CASE_UPPER);

        if (isset($parts['COUNT'])) {
            $cap = VObject\Settings::$maxRecurrences;
            return $cap !== -1 && (int) $parts['COUNT'] > $cap ? $maxDate->getTimeStamp() : null;
        }

        if (!isset($parts['UNTIL']) || !is_string($parts['UNTIL'])) {
            return null;
        }

        $firstStart = $it->getDtStart();
        $duration = max(0, $it->getDtEnd()->getTimeStamp() - $firstStart->getTimeStamp());
        // Read as the iterator reads it.
        $until = VObject\DateTimeParser::parse($parts['UNTIL'], $firstStart->getTimezone());

        $end = max($until->getTimeStamp(), $firstStart->getTimeStamp()) + $duration;
        if (strlen($parts['UNTIL']) === 8 || !$component->DTSTART->hasTime() || $component->DTSTART->isFloating()) {
            $end += 86400;
        }

        return min($end, $maxDate->getTimeStamp());
    }

    /**
     * Find the last recurrence date by iterating through occurrences
     *
     * Each instance contributes the later of its start and end, so that a
     * negative duration cannot hide the instances themselves.
     *
     * @param VObject\Recur\EventIterator $it
     * @param \DateTime $maxDate
     * @return int Last occurrence timestamp
     */
    private function findLastRecurrenceDate($it, $maxDate) {
        $end = max($it->getDtStart(), $it->getDtEnd());

        while($it->valid() && $end < $maxDate) {
            $end = max($end, $it->getDtStart(), $it->getDtEnd());
            $it->next();
        }

        return $end->getTimeStamp();
    }
}
