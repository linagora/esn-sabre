<?php

namespace ESN\CalDAV\Schedule;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ITip;

/**
 * Keeps a resource's participation out of the organizer's hands.
 *
 * A REQUEST carries the organizer's ATTENDEE lines, PARTSTAT included, and the
 * broker copies them into the recipient's copy as they are. For a resource,
 * that PARTSTAT is the administrators' decision: the side service writes it
 * into the resource's own copy (auto-accept, or the answer from the validation
 * mail). Taking it from the REQUEST would let any organizer book a room as
 * ACCEPTED, or turn a decline back into an acceptance with any later edit.
 *
 * So the resource's PARTSTAT of a delivered REQUEST is the one of its stored
 * copy: the same occurrence, otherwise the master, otherwise NEEDS-ACTION.
 */
final class ResourcePartStatGuard {
    private const RESOURCE_PRINCIPAL_PREFIX = 'principals/resources/';
    private const DEFAULT_PARTSTAT = 'NEEDS-ACTION';

    public static function appliesTo(ITip\Message $iTipMessage, ?string $recipientPrincipalUri): bool {
        return $iTipMessage->method === 'REQUEST'
            && $recipientPrincipalUri !== null
            && strpos($recipientPrincipalUri, self::RESOURCE_PRINCIPAL_PREFIX) === 0;
    }

    /**
     * @param VCalendar|null $storedCopy the resource's copy before the delivery, null on creation
     */
    public static function keepStoredPartStats(string $resourceAddress, ?VCalendar $storedCopy, VCalendar $newObject): void {
        $storedEvents = $storedCopy ? CalendarObjectHelper::indexEventsByRecurrenceKey($storedCopy) : [];

        foreach ($newObject->select('VEVENT') as $vevent) {
            self::setPartStat($vevent, $resourceAddress, self::storedPartStat($vevent, $resourceAddress, $storedEvents));
        }
    }

    private static function storedPartStat($vevent, string $resourceAddress, array $storedEvents): string {
        $storedEvent = $storedEvents[CalendarObjectHelper::recurrenceKey($vevent)]
            ?? $storedEvents[CalendarObjectHelper::MASTER_EVENT]
            ?? null;

        return ($storedEvent ? CalendarObjectHelper::attendeePartStat($storedEvent, $resourceAddress) : null)
            ?? self::DEFAULT_PARTSTAT;
    }

    private static function setPartStat($vevent, string $resourceAddress, string $partstat): void {
        $resourceAddress = strtolower($resourceAddress);

        foreach ($vevent->select('ATTENDEE') as $attendee) {
            if (strtolower((string) CalendarObjectHelper::calendarAddress($attendee)) === $resourceAddress) {
                $attendee['PARTSTAT'] = $partstat;
            }
        }
    }
}
