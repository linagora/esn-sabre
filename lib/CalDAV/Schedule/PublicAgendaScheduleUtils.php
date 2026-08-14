<?php

namespace ESN\CalDAV\Schedule;

use Sabre\VObject\Component\VCalendar;

class PublicAgendaScheduleUtils {
    private static string $PUBLICLY_CREATED_HEADER = 'X-PUBLICLY-CREATED';
    private static string $PUBLICLY_CREATOR_HEADER = 'X-PUBLICLY-CREATOR';
    private static array $NOT_ACCEPTED_PARTSTATS = ['NEEDS-ACTION', 'DECLINED'];
    private static array $ACCEPTED_PARTSTATS = ['ACCEPTED', 'TENTATIVE'];

    public static function isPubliclyCreatedAndChairOrganizerNotAccepted(VCalendar $vCal): bool {
        if (self::parsePubliclyCreatedFlag($vCal) !== true) {
            return false;
        }

        $partstat = self::getChairOrganizerPartstat($vCal);
        return in_array($partstat, self::$NOT_ACCEPTED_PARTSTATS, true);
    }

    public static function isChairOrganizerAcceptedTransition(VCalendar $formerEvent, VCalendar $currentEvent): bool {
        if (self::parsePubliclyCreatedFlag($currentEvent) !== true) {
            return false;
        }

        $oldPartstat = self::getChairOrganizerPartstat($formerEvent);
        $newPartstat = self::getChairOrganizerPartstat($currentEvent);

        return in_array($oldPartstat, self::$NOT_ACCEPTED_PARTSTATS, true)
            && in_array($newPartstat, self::$ACCEPTED_PARTSTATS, true);
    }

    /**
     * Every scheduling recipient found in the given calendars except the booker
     * (X-PUBLICLY-CREATOR), in the exact normalized form used for
     * ITip\Message::$recipient, so the result can be used as the $ignore list of
     * processICalendarChange() (or compared against with in_array()).
     *
     * Pass every calendar the ITip Broker sees: on a PUT that is the old *and* the
     * new object, because an attendee removed by the write still receives a CANCEL
     * yet only exists in the old copy. On a DELETE the single stored object holds
     * every recipient the Broker can emit.
     *
     * When no booker is present the whole recipient set is returned, which
     * suppresses scheduling entirely.
     *
     * @param (VCalendar|null)[] $calendars
     * @return string[]
     */
    public static function recipientsExceptBooker(array $calendars): array {
        $calendars = array_filter($calendars, fn($calendar) => $calendar !== null);
        $booker = self::findBookerEmail($calendars);

        $ignore = [];
        foreach ($calendars as $calendar) {
            foreach ($calendar->select('VEVENT') as $vevent) {
                $ignore = array_merge($ignore, self::eventRecipientsExcept($vevent, $booker));
            }
        }

        return array_values(array_unique($ignore));
    }

    /**
     * @param VCalendar[] $calendars
     */
    private static function findBookerEmail(array $calendars): ?string {
        foreach ($calendars as $calendar) {
            $booker = self::getBookerEmail($calendar);
            if ($booker !== null) {
                return $booker;
            }
        }

        return null;
    }

    /**
     * Both ends are compared through canonicalizeCalendarAddress() because
     * getNormalizedValue() lowercases the scheme only, but the recipient is collected
     * verbatim so it stays byte-comparable with ITip\Message::$recipient.
     *
     * @return string[]
     */
    private static function eventRecipientsExcept($vevent, ?string $excluded): array {
        $properties = array_merge($vevent->select('ATTENDEE'), $vevent->select('ORGANIZER'));

        $recipients = [];
        foreach ($properties as $property) {
            $recipient = $property->getNormalizedValue();
            if ($excluded === null || self::canonicalizeCalendarAddress($recipient) !== $excluded) {
                $recipients[] = $recipient;
            }
        }

        return $recipients;
    }

    private static function getBookerEmail(VCalendar $vCal): ?string {
        foreach ($vCal->select('VEVENT') as $vevent) {
            $email = isset($vevent->{self::$PUBLICLY_CREATOR_HEADER})
                ? self::canonicalizeCalendarAddress($vevent->{self::$PUBLICLY_CREATOR_HEADER})
                : '';

            if ($email !== '') {
                return $email;
            }
        }

        return null;
    }

    private static function parsePubliclyCreatedFlag(VCalendar $vCal): ?bool {
        $vevent = $vCal->VEVENT;
        if ($vevent === null || !isset($vevent->{self::$PUBLICLY_CREATED_HEADER})) {
            return null;
        }

        return filter_var(trim((string) $vevent->{self::$PUBLICLY_CREATED_HEADER}), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }

    private static function getChairOrganizerPartstat(VCalendar $vCal): ?string {
        $vevent = $vCal->VEVENT;
        if ($vevent === null || $vevent->ORGANIZER === null) {
            return null;
        }

        $organizerEmail = self::canonicalizeCalendarAddress($vevent->ORGANIZER);
        foreach ($vevent->select('ATTENDEE') as $attendee) {
            if (self::canonicalizeCalendarAddress($attendee) !== $organizerEmail) {
                continue;
            }

            $role = strtoupper((string) ($attendee['ROLE'] ?? ''));
            if ($role !== 'CHAIR') {
                continue;
            }

            $partstat = strtoupper((string) ($attendee['PARTSTAT'] ?? 'NEEDS-ACTION'));
            $partstat = str_replace('_', '-', $partstat);

            return $partstat;
        }

        return null;
    }

    private static function canonicalizeCalendarAddress($value): string {
        $value = strtolower(trim((string) $value));

        return strncmp($value, 'mailto:', 7) === 0
            ? substr($value, 7)
            : $value;
    }
}
