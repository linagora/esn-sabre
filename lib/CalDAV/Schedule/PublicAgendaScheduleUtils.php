<?php

namespace ESN\CalDAV\Schedule;

use Sabre\VObject\Component\VCalendar;

class PublicAgendaScheduleUtils {
    private static string $PUBLICLY_CREATED_HEADER = 'X-PUBLICLY-CREATED';
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

            $partstat = strtoupper((string) ($attendee['PARTSTAT'] ?? ''));
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
