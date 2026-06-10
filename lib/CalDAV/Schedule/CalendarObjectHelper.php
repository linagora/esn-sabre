<?php

namespace ESN\CalDAV\Schedule;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ITip;
use Sabre\VObject\Reader;

/**
 * Stateless helpers to inspect and compare iCalendar objects (VCALENDAR /
 * VEVENT components) used by the scheduling plugins.
 */
class CalendarObjectHelper {
    const MASTER_EVENT = 'master';

    private const OCCURRENCE_COMPARED_PROPERTIES = ['DTSTART', 'DTEND', 'SUMMARY', 'LOCATION', 'DESCRIPTION', 'STATUS', 'EXDATE'];

    /**
     * Parses raw iCalendar data into a VCalendar, returning null when the
     * input cannot be parsed or is not a VCALENDAR.
     */
    public static function readCalendarObject($calendarObject): ?VCalendar {
        if ($calendarObject instanceof VCalendar) {
            return $calendarObject;
        }

        try {
            $parsedObject = \Sabre\VObject\Reader::read($calendarObject);
        } catch (\Throwable) {
            return null;
        }

        return $parsedObject instanceof VCalendar ? $parsedObject : null;
    }

    /**
     * A calendar object is recurring when any VEVENT carries an RRULE or a
     * RECURRENCE-ID.
     */
    public static function isRecurringCalendar($calendarObject): bool {
        foreach ($calendarObject->VEVENT as $vevent) {
            if (isset($vevent->RRULE) || isset($vevent->{'RECURRENCE-ID'})) {
                return true;
            }
        }

        return false;
    }

    /**
     * Timezone-safe recurrence key: the RECURRENCE-ID timestamp, falling back
     * to its raw value, or MASTER_EVENT for the master VEVENT.
     */
    public static function recurrenceKey($vevent): string {
        if (!isset($vevent->{'RECURRENCE-ID'})) {
            return self::MASTER_EVENT;
        }

        try {
            return (string)$vevent->{'RECURRENCE-ID'}->getDateTime()->getTimestamp();
        } catch (\Throwable) {
            return (string)$vevent->{'RECURRENCE-ID'}->getValue();
        }
    }

    public static function indexEventsByRecurrenceKey(VCalendar $calendarObject): array {
        $events = [];
        foreach ($calendarObject->select('VEVENT') as $vevent) {
            $events[self::recurrenceKey($vevent)] = $vevent;
        }

        return $events;
    }

    /**
     * Raw RECURRENCE-ID value of a VEVENT, or MASTER_EVENT for the master.
     */
    public static function recurrenceIdValue($vevent): string {
        return isset($vevent->{'RECURRENCE-ID'})
            ? $vevent->{'RECURRENCE-ID'}->getValue()
            : self::MASTER_EVENT;
    }

    public static function findEventByRecurrenceIdValue($calendarObject, string $recurrenceId) {
        foreach ($calendarObject->VEVENT as $vevent) {
            if (self::recurrenceIdValue($vevent) === $recurrenceId) {
                return $vevent;
            }
        }

        return null;
    }

    /**
     * Returns the master VEVENT (no RECURRENCE-ID), falling back to the first
     * VEVENT when the object only contains overrides.
     */
    public static function findMasterEvent(VCalendar $calendarObject) {
        $firstEvent = null;
        foreach ($calendarObject->select('VEVENT') as $vevent) {
            $firstEvent = $firstEvent ?? $vevent;
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                return $vevent;
            }
        }

        return $firstEvent;
    }

    public static function hasMasterEvent($calendarObject): bool {
        foreach ($calendarObject->VEVENT as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exact (case-sensitive) attendee membership check, matching the broker's
     * recipient comparison semantics.
     */
    public static function hasAttendee($vevent, string $recipient): bool {
        if (!isset($vevent->ATTENDEE)) {
            return false;
        }

        foreach ($vevent->ATTENDEE as $attendee) {
            if ($attendee->getNormalizedValue() === $recipient) {
                return true;
            }
        }

        return false;
    }

    /**
     * Case-insensitive PARTSTAT lookup: returns the uppercased PARTSTAT of the
     * given attendee (NEEDS-ACTION when absent), or null when the recipient is
     * not an attendee of the VEVENT.
     */
    public static function attendeePartStat($vevent, string $recipient): ?string {
        if (!isset($vevent->ATTENDEE)) {
            return null;
        }

        $recipient = strtolower($recipient);
        foreach ($vevent->ATTENDEE as $attendee) {
            if (strtolower($attendee->getNormalizedValue()) === $recipient) {
                return isset($attendee['PARTSTAT']) ? strtoupper((string)$attendee['PARTSTAT']) : 'NEEDS-ACTION';
            }
        }

        return null;
    }

    /**
     * Maps each attendee (lowercased email) to its PARTSTAT, sorted by email so
     * two maps can be compared directly.
     */
    public static function attendeePartStats($vevent): array {
        $map = [];
        if (isset($vevent->ATTENDEE)) {
            foreach ($vevent->ATTENDEE as $attendee) {
                $email = strtolower($attendee->getNormalizedValue());
                $map[$email] = $attendee['PARTSTAT'] ? strtoupper((string)$attendee['PARTSTAT']) : 'NEEDS-ACTION';
            }
        }
        ksort($map);

        return $map;
    }

    /**
     * Counts the RECURRENCE-ID overrides the recipient is an attendee of.
     */
    public static function countExceptionsWithAttendee($calendarObject, string $recipient): int {
        $count = 0;
        foreach ($calendarObject->VEVENT as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'}) && self::hasAttendee($vevent, $recipient)) {
                $count++;
            }
        }

        return $count;
    }

    public static function sequenceOf($vevent) {
        return isset($vevent->SEQUENCE) ? $vevent->SEQUENCE->getValue() : 0;
    }

    public static function stringPropertyValue($vevent, string $property): string {
        return isset($vevent->$property) ? (string)$vevent->$property : '';
    }

    /**
     * Sorted, deduplicated timestamps of every EXDATE entry of a VEVENT.
     */
    public static function exDateTimestamps($event): array {
        $timestamps = [];

        if (!isset($event->EXDATE)) {
            return $timestamps;
        }

        foreach ($event->select('EXDATE') as $exDate) {
            foreach ($exDate->getDateTimes() as $dateTime) {
                $timestamps[] = $dateTime->getTimestamp();
            }
        }

        sort($timestamps);

        return array_values(array_unique($timestamps));
    }

    public static function exDatesDiffer($leftEvent, $rightEvent): bool {
        return self::exDateTimestamps($leftEvent) !== self::exDateTimestamps($rightEvent);
    }

    public static function countUniqueAttendees($attendees): int {
        $attendeeMap = [];
        foreach ($attendees as $attendee) {
            $attendeeMap[strtolower($attendee->getNormalizedValue())] = true;
        }

        return count($attendeeMap);
    }

    /**
     * Sorted serialized representations of every instance of a property,
     * usable to detect any change on that property between two VEVENTs.
     */
    public static function propertySignatures($event, string $propertyName): array {
        $signatures = [];
        foreach ($event->select($propertyName) as $property) {
            $signatures[] = $property->serialize();
        }
        sort($signatures);

        return $signatures;
    }

    /**
     * Parses raw iCalendar data when needed and returns the calendar object
     * only when the unchanged-occurrence filter applies to it: recurring
     * events only (single-day events must always be delivered).
     */
    public static function asFilterableRecurringCalendar($oldObject) {
        // Parse oldObject if it's a string (raw iCalendar data)
        if (is_string($oldObject)) {
            $oldObject = Reader::read($oldObject);
        }

        // Only apply this filter to recurring events (must have RRULE or RECURRENCE-ID)
        if (!isset($oldObject->VEVENT) || !self::isRecurringCalendar($oldObject)) {
            return null;
        }

        return $oldObject;
    }

    /**
     * Locates the occurrence the message is about, both in the old and the new
     * calendar object.
     *
     * @return array|null [$recurrenceId, $oldVEvent, $newVEvent], or null when
     *                    the message is not about a single existing occurrence.
     */
    public static function findMessageOccurrencePair(ITip\Message $message, $oldObject, VCalendar $newObject): ?array {
        // Only filter messages with a single VEVENT (single occurrence)
        // Messages with multiple VEVENTs (bundled occurrences) should not be filtered
        // as they represent legitimate multi-occurrence invitations
        if (count($message->message->VEVENT) !== 1) {
            return null;
        }

        // Get the VEVENT from the message to identify which occurrence this is about
        $messageEvent = $message->message->VEVENT;
        if (!$messageEvent) {
            return null;
        }

        // Find the corresponding VEVENTs in old and new objects
        $recurrenceId = self::recurrenceIdValue($messageEvent);
        $oldVEvent = self::findEventByRecurrenceIdValue($oldObject, $recurrenceId);
        $newVEvent = self::findEventByRecurrenceIdValue($newObject, $recurrenceId);

        // If this is a new occurrence (wasn't in oldObject), don't skip
        if (!$oldVEvent || !$newVEvent) {
            return null;
        }

        return [$recurrenceId, $oldVEvent, $newVEvent];
    }

    /**
     * If recipient wasn't and isn't attending, skip (already handled by broker)
     * If recipient was attending but isn't now, don't skip (it's a removal)
     * If recipient wasn't attending but is now, don't skip (it's an addition)
     * Only skip if recipient was AND is still attending
     */
    public static function recipientAttendanceChanged(string $recipient, $oldVEvent, $newVEvent): bool {
        return !self::hasAttendee($oldVEvent, $recipient)
            || !self::hasAttendee($newVEvent, $recipient);
    }

    /**
     * Checks if the number of occurrences (exceptions) the recipient is
     * invited to has changed.
     */
    public static function invitedExceptionCountChanged(string $recipient, $oldObject, VCalendar $newObject): bool {
        return self::countExceptionsWithAttendee($oldObject, $recipient)
            !== self::countExceptionsWithAttendee($newObject, $recipient);
    }

    public static function occurrenceContentChanged($oldVEvent, $newVEvent): bool {
        // Compare SEQUENCE, then key properties (including EXDATE for occurrence
        // exclusion detection)
        if (self::sequenceOf($oldVEvent) != self::sequenceOf($newVEvent)) {
            return true;
        }

        foreach (self::OCCURRENCE_COMPARED_PROPERTIES as $prop) {
            if (self::stringPropertyValue($oldVEvent, $prop) !== self::stringPropertyValue($newVEvent, $prop)) {
                return true;
            }
        }

        // Compare PARTSTAT for all attendees. A PARTSTAT-only change (e.g. an attendee
        // accepting/declining) is invisible to the checks above because PARTSTAT is not in
        // significantChangeProperties. Without this check, recurring-event attendees never see
        // co-attendee PARTSTAT updates — inconsistent with the single-day-event behaviour
        // (single-day events short-circuit at the recurrence guard and always deliver).
        return self::attendeePartStats($oldVEvent) !== self::attendeePartStats($newVEvent);
    }

    public static function hasAttendeeInAddresses($event, array $normalizedAddresses): bool {
        foreach ($event->select('ATTENDEE') as $attendee) {
            if (in_array(strtolower($attendee->getNormalizedValue()), $normalizedAddresses, true)) {
                return true;
            }
        }

        return false;
    }

    public static function hasExceptionWithRecurrenceTimestamp(VCalendar $calendarObject, int $timestamp): bool {
        foreach ($calendarObject->VEVENT as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'}) &&
                $vevent->{'RECURRENCE-ID'}->getDateTime()->getTimestamp() === $timestamp) {
                return true;
            }
        }

        return false;
    }

    public static function findExceptionByStartTimestamp(VCalendar $calendarObject, int $timestamp) {
        foreach ($calendarObject->VEVENT as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                continue; // Skip master VEVENT.
            }
            if ($vevent->DTSTART->getDateTime()->getTimestamp() === $timestamp) {
                return $vevent;
            }
        }

        return null;
    }

    /**
     * Strips the invalid RRULE (RFC 5545 §3.8.5.3) from override VEVENTs only.
     */
    public static function stripRruleFromOverrides($calendarObject): void {
        foreach ($calendarObject->VEVENT as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'}) && isset($vevent->RRULE)) {
                unset($vevent->RRULE);
            }
        }
    }

    /**
     * Reflects the delivery status of a message on the organizer or attendee
     * SCHEDULE-STATUS parameter of the calendar object.
     */
    public static function updateScheduleStatus(VCalendar $newObject, ITip\Message $message): void {
        if (isset($newObject->VEVENT->ORGANIZER) && ($newObject->VEVENT->ORGANIZER->getNormalizedValue() === $message->recipient)) {
            self::reflectScheduleStatusOn($newObject->VEVENT->ORGANIZER, $message);
            return;
        }

        if (!isset($newObject->VEVENT->ATTENDEE)) {
            return;
        }

        foreach ($newObject->VEVENT->ATTENDEE as $attendee) {
            if ($attendee->getNormalizedValue() === $message->recipient) {
                self::reflectScheduleStatusOn($attendee, $message);
                break;
            }
        }
    }

    private static function reflectScheduleStatusOn($property, ITip\Message $message): void {
        if ($message->scheduleStatus) {
            $property['SCHEDULE-STATUS'] = $message->getScheduleStatus();
        }
        unset($property['SCHEDULE-FORCE-SEND']);
    }
}
