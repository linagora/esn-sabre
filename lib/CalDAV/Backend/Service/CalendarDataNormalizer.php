<?php

namespace ESN\CalDAV\Backend\Service;

use \Sabre\VObject;

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

    /**
     * Extract denormalized metadata from calendar data
     *
     * @param string $calendarData iCalendar data
     * @return array Array with etag, size, componentType, firstOccurence, lastOccurence, uid
     * @throws \Sabre\DAV\Exception\BadRequest If no valid component found
     */
    public function getDenormalizedData($calendarData) {
        $vObject = VObject\Reader::read($calendarData);

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

        $firstOccurence = $component->DTSTART->getDateTime()->getTimeStamp();
        $lastOccurence = $this->calculateLastOccurrence($component, $vObject);

        return [$firstOccurence, $lastOccurence];
    }

    /**
     * Calculate last occurrence timestamp for VEVENT
     *
     * @param VObject\Component $component
     * @param VObject\Component\VCalendar $vObject
     * @return int Last occurrence timestamp
     */
    private function calculateLastOccurrence($component, $vObject) {
        if (!isset($component->RRULE)) {
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
                VObject\DateTimeParser::parse($component->DURATION->getValue())
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
        $it = new VObject\Recur\EventIterator($vObject, (string) $component->UID);
        $maxDate = new \DateTime(self::MAX_DATE);

        if ($it->isInfinite()) {
            return $maxDate->getTimeStamp();
        }

        return $this->findLastRecurrenceDate($it, $maxDate);
    }

    /**
     * Find the last recurrence date by iterating through occurrences
     *
     * @param VObject\Recur\EventIterator $it
     * @param \DateTime $maxDate
     * @return int Last occurrence timestamp
     */
    private function findLastRecurrenceDate($it, $maxDate) {
        $end = $it->getDtEnd();

        while($it->valid() && $end < $maxDate) {
            $end = $it->getDtEnd();
            $it->next();
        }

        return $end->getTimeStamp();
    }
}
