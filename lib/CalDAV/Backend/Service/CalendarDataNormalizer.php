<?php

namespace ESN\CalDAV\Backend\Service;

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
     * @return array Array with etag, size, componentType, firstOccurence, lastOccurence, uid
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
        $classification = isset($component->CLASS) ? strtoupper((string) $component->CLASS) : null;

        list($firstOccurence, $lastOccurence) = $this->calculateOccurrences($component, $vObject);

        return [
            'etag' => md5($calendarData),
            'size' => strlen($calendarData),
            'componentType' => $componentType,
            'firstOccurence' => $firstOccurence,
            'lastOccurence'  => $lastOccurence,
            'uid' => $uid,
            'classification' => $classification,
        ];
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

            $start = $event->DTSTART->getDateTime()->getTimeStamp();
            // A negative DURATION, or an infinite rule starting after the
            // MAX_DATE fallback, must never yield a window ending before it starts.
            $end = max($start, $this->calculateLastOccurrence($event, $vObject));

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
     * Calculate last occurrence timestamp for VEVENT
     *
     * @param VObject\Component $component
     * @param VObject\Component\VCalendar $vObject
     * @return int Last occurrence timestamp
     */
    private function calculateLastOccurrence($component, $vObject) {
        // RDATE generates instances just like RRULE does: only the iterator sees them.
        if (!isset($component->RRULE) && !isset($component->RDATE)) {
            return $this->calculateSimpleEndDate($component);
        }

        return $this->calculateRecurringEndDate($component, $vObject);
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
     * @param VObject\Component $component
     * @param VObject\Component\VCalendar $vObject
     * @return int End timestamp
     */
    private function calculateRecurringEndDate($component, $vObject) {
        $maxDate = new \DateTime(self::MAX_DATE);

        try {
            $it = new VObject\Recur\EventIterator($vObject, (string) $component->UID);

            if ($it->isInfinite()) {
                return $maxDate->getTimeStamp();
            }

            return $this->findLastRecurrenceDate($it, $maxDate);
        } catch (\Exception $e) {
            // Be lenient: a malformed recurrence (e.g. an invalid RRULE UNTIL value) must not
            // crash the whole request. We log the problem and fall back to treating the event as
            // open-ended so it stays discoverable by time-range queries instead of being lost.
            error_log('CalendarDataNormalizer: could not expand recurrence for UID '
                . (string) $component->UID . ', falling back to open-ended: ' . $e->getMessage());

            return $maxDate->getTimeStamp();
        }
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
