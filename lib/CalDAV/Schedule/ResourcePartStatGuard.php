<?php

namespace ESN\CalDAV\Schedule;

use ESN\CalDAV\Principal\PrincipalResource;
use Sabre\DAV\Server;
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
 *
 * A resource answers to its <id>@<domain> address and to its custom address, and the
 * broker delivers one REQUEST per ATTENDEE line: every line whose address resolves to
 * the resource principal is the resource, whatever address the delivery was made for.
 * They all take the stored PARTSTAT of the <id>@<domain> line, the one the side
 * service writes, otherwise of any line of the resource.
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
     * Whether a calendar address is one of the resource's: its <id>@<domain> address, its
     * custom address, or the address the resource principal was resolved from. The resource
     * principal is read once, rather than every attendee address being looked up.
     *
     * @param string $resolvedAddress the address the resource principal was resolved from
     * @return callable(string): bool
     */
    public static function resourceAddressMatcher(string $resourcePrincipalUri, string $resolvedAddress, Server $server): callable {
        $addresses = array_merge([$resolvedAddress], self::principalAddresses($resourcePrincipalUri, $server));
        $addresses = array_flip(array_map([self::class, 'normalizedAddress'], $addresses));

        return fn(string $address): bool => isset($addresses[self::normalizedAddress($address)]);
    }

    private static function principalAddresses(string $resourcePrincipalUri, Server $server): array {
        try {
            $principal = $server->tree->getNodeForPath($resourcePrincipalUri);
        } catch (\Sabre\DAV\Exception $e) {
            return [];
        }

        return $principal instanceof PrincipalResource ? $principal->getCalendarAddresses() : [];
    }

    private static function normalizedAddress(string $address): string {
        $address = strtolower(trim($address));

        return strpos($address, 'mailto:') === 0 ? substr($address, 7) : $address;
    }

    /**
     * @param callable(string): bool $isResourceAddress see resourceAddressMatcher()
     * @param VCalendar|null $storedCopy the resource's copy before the delivery, null on creation
     */
    public static function keepStoredPartStats(callable $isResourceAddress, string $resourcePrincipalUri, ?VCalendar $storedCopy, VCalendar $newObject): void {
        $storedEvents = $storedCopy ? CalendarObjectHelper::indexEventsByRecurrenceKey($storedCopy) : [];

        foreach ($newObject->select('VEVENT') as $vevent) {
            $storedEvent = $storedEvents[CalendarObjectHelper::recurrenceKey($vevent)]
                ?? $storedEvents[CalendarObjectHelper::MASTER_EVENT]
                ?? null;
            $partstat = ($storedEvent ? self::resourcePartStat($storedEvent, $isResourceAddress, $resourcePrincipalUri) : null)
                ?? self::DEFAULT_PARTSTAT;

            foreach (self::resourceAttendees($vevent, $isResourceAddress) as $attendee) {
                $attendee['PARTSTAT'] = $partstat;
            }
        }
    }

    /**
     * The uppercased PARTSTAT the resource holds in a VEVENT: the one of its <id>@<domain> line,
     * otherwise of its first line, or null when the resource is not an attendee of it.
     */
    public static function resourcePartStat($vevent, callable $isResourceAddress, string $resourcePrincipalUri): ?string {
        $attendees = self::resourceAttendees($vevent, $isResourceAddress);
        if (!$attendees) {
            return null;
        }

        $idAddressPrefix = 'mailto:' . strtolower(basename($resourcePrincipalUri)) . '@';
        $idAttendees = array_filter($attendees,
            fn($attendee) => strpos(strtolower((string) CalendarObjectHelper::calendarAddress($attendee)), $idAddressPrefix) === 0);
        $attendee = $idAttendees ? reset($idAttendees) : reset($attendees);

        return isset($attendee['PARTSTAT']) ? strtoupper(trim((string) $attendee['PARTSTAT'])) : self::DEFAULT_PARTSTAT;
    }

    private static function resourceAttendees($vevent, callable $isResourceAddress): array {
        return array_values(array_filter($vevent->select('ATTENDEE'), function ($attendee) use ($isResourceAddress) {
            $address = (string) CalendarObjectHelper::calendarAddress($attendee);

            return $address !== '' && $isResourceAddress($address);
        }));
    }
}
