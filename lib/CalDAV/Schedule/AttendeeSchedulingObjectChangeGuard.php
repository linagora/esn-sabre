<?php

namespace ESN\CalDAV\Schedule;

use ESN\CalDAV\Schedule\Exception\ForbiddenAttendeeSchedulingObjectChange;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Recur\EventIterator;

/**
 * RFC 6638 guard: an attendee must not change the organizer-owned fields of
 * any instance of their copy of a scheduling object.
 *
 * Instances are compared by effective identity (RECURRENCE-ID instant), not by
 * the VEVENT that happens to carry them: an occurrence generated from the
 * master in the old copy is compared against the override describing it in the
 * new copy, and the other way around. Otherwise an attendee could rewrite an
 * occurrence by moving it to another recurrence key (master turned into an
 * override, new override of an implicit occurrence, RECURRENCE-ID in another
 * form, override dropped in favour of the master...).
 */
class AttendeeSchedulingObjectChangeGuard {
    /**
     * @param string[] $forbiddenProperties
     * @throws ForbiddenAttendeeSchedulingObjectChange
     */
    public static function assertAllowed(VCalendar $oldObject, VCalendar $newObject, array $forbiddenProperties): void {
        $oldMaster = self::findMaster($oldObject);
        $newMaster = self::findMaster($newObject);
        // Floating RECURRENCE-IDs are resolved like EventIterator does, so equivalent forms share a key.
        $timeZone = self::referenceTimeZone($oldMaster ?? $newMaster);

        $oldEvents = self::indexEvents($oldObject, $timeZone);
        $newEvents = self::indexEvents($newObject, $timeZone);

        self::assertSameMaster($oldMaster, $newMaster, $forbiddenProperties);

        $oldGeneratedInstances = self::generatedInstances($oldMaster, $timeZone);
        $newGeneratedInstances = self::generatedInstances($newMaster, $timeZone);

        // Cursors only move forward, so instances must be visited in chronological order.
        $instanceKeys = self::chronological(array_unique(array_merge(array_keys($oldEvents), array_keys($newEvents))));
        foreach ($instanceKeys as $key) {
            $oldEvent = $oldEvents[$key] ?? null;
            $newEvent = $newEvents[$key] ?? null;

            if ($oldEvent && $newEvent) {
                self::assertSameExplicitEvent($oldEvent, $newEvent, $forbiddenProperties);
                continue;
            }

            self::assertSameInstance(
                self::instanceAt($oldEvent, $oldGeneratedInstances, $key, $timeZone),
                self::instanceAt($newEvent, $newGeneratedInstances, $key, $timeZone),
                $forbiddenProperties
            );
        }
    }

    private static function assertSameMaster($oldMaster, $newMaster, array $forbiddenProperties): void {
        if ($oldMaster && $newMaster) {
            self::assertSameExplicitEvent($oldMaster, $newMaster, $forbiddenProperties);
        } elseif ($newMaster) {
            // A master the old copy did not have generates instances the organizer never sent.
            throw new ForbiddenAttendeeSchedulingObjectChange('DTSTART');
        }
    }

    /**
     * Organizer-owned fields of the instance at $key, carried by $event when
     * there is one, generated from the master otherwise.
     */
    private static function instanceAt($event, \Closure $generatedInstances, string $key, \DateTimeZone $timeZone): ?array {
        return $event
            ? self::explicitInstance($event, $timeZone)
            : $generatedInstances($key);
    }

    private static function assertSameInstance(?array $oldInstance, ?array $newInstance, array $forbiddenProperties): void {
        if (!$newInstance) {
            // The attendee removed the instance from their copy (declined / deleted occurrence).
            return;
        }
        if (!$oldInstance) {
            // The attendee invented an instance the organizer never scheduled.
            throw new ForbiddenAttendeeSchedulingObjectChange('DTSTART');
        }

        foreach ($forbiddenProperties as $propertyName) {
            if ($oldInstance[$propertyName] !== $newInstance[$propertyName]) {
                throw new ForbiddenAttendeeSchedulingObjectChange($propertyName);
            }
        }
    }

    private static function assertSameExplicitEvent($oldEvent, $newEvent, array $forbiddenProperties): void {
        foreach ($forbiddenProperties as $propertyName) {
            if (CalendarObjectHelper::propertySignatures($oldEvent, $propertyName) !== CalendarObjectHelper::propertySignatures($newEvent, $propertyName)) {
                throw new ForbiddenAttendeeSchedulingObjectChange($propertyName);
            }
        }
    }

    private static function findMaster(VCalendar $calendarObject) {
        foreach ($calendarObject->select('VEVENT') as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                return $vevent;
            }
        }

        return null;
    }

    private static function referenceTimeZone($master): \DateTimeZone {
        try {
            if ($master && isset($master->DTSTART)) {
                return $master->DTSTART->getDateTime()->getTimezone();
            }
        } catch (\Throwable) {
        }

        return new \DateTimeZone('UTC');
    }

    /**
     * Overrides keyed by their RECURRENCE-ID instant ("at:<timestamp>"), or by
     * their raw value ("raw:<value>") when it cannot be parsed.
     */
    private static function indexEvents(VCalendar $calendarObject, \DateTimeZone $timeZone): array {
        $events = [];
        foreach ($calendarObject->select('VEVENT') as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'})) {
                $events[self::recurrenceKey($vevent, $timeZone)] = $vevent;
            }
        }

        return $events;
    }

    private static function recurrenceKey($vevent, \DateTimeZone $timeZone): string {
        try {
            return 'at:' . $vevent->{'RECURRENCE-ID'}->getDateTime($timeZone)->getTimestamp();
        } catch (\Throwable) {
            return 'raw:' . $vevent->{'RECURRENCE-ID'}->getValue();
        }
    }

    /**
     * Instant keys in increasing order, unparsable ("raw:") keys last: those
     * never need a generated instance.
     */
    private static function chronological(array $keys): array {
        usort($keys, function (string $a, string $b): int {
            return self::sortKey($a) <=> self::sortKey($b);
        });

        return $keys;
    }

    private static function sortKey(string $key): array {
        return str_starts_with($key, 'at:') ? [0, (int)substr($key, 3)] : [1, 0];
    }

    /**
     * Looks up the instances a master generates, see generatedInstance(): keys
     * must be passed in chronological order.
     */
    private static function generatedInstances($master, \DateTimeZone $timeZone): \Closure {
        $occurrences = self::occurrenceCursor($master, $timeZone);

        return fn(string $key): ?array => self::generatedInstance($master, $occurrences, $key, $timeZone);
    }

    /**
     * Looks up the occurrence a master generates at a given instant, returning
     * its start or null when there is none. The iterator is created on first
     * use and only moves forward, so the series is expanded at most once
     * whatever the number of instances looked up: calls must be made with
     * increasing timestamps.
     */
    private static function occurrenceCursor($master, \DateTimeZone $timeZone): \Closure {
        $iterator = null;

        return function (int $timestamp) use (&$iterator, $master, $timeZone): ?\DateTimeInterface {
            $iterator ??= new EventIterator([$master], null, $timeZone);
            while ($iterator->valid() && $iterator->current()->getTimestamp() < $timestamp) {
                $iterator->next();
            }

            return $iterator->valid() && $iterator->current()->getTimestamp() === $timestamp
                ? $iterator->current()
                : null;
        };
    }

    /**
     * Organizer-owned fields of the instance the master generates at the
     * instant of $key, or null when the master generates no instance there.
     *
     * @throws ForbiddenAttendeeSchedulingObjectChange when the recurrence cannot be expanded up to $key
     */
    private static function generatedInstance($master, \Closure $occurrences, string $key, \DateTimeZone $timeZone): ?array {
        if (!$master || !str_starts_with($key, 'at:')) {
            return null;
        }

        try {
            $start = $occurrences((int)substr($key, 3));
            if (!$start) {
                return null;
            }

            [$masterStart, $masterEnd] = self::eventBounds($master, $timeZone);

            return self::instanceFields(
                $master,
                $start,
                $start->getTimestamp() + $masterEnd->getTimestamp() - $masterStart->getTimestamp()
            );
        } catch (\Sabre\VObject\Recur\NoInstancesException) {
            return null;
        } catch (\Throwable) {
            // Fail closed: an instance we cannot evaluate cannot be proven unchanged.
            throw new ForbiddenAttendeeSchedulingObjectChange('DTSTART');
        }
    }

    private static function explicitInstance($vevent, \DateTimeZone $timeZone): array {
        try {
            [$start, $end] = self::eventBounds($vevent, $timeZone);
        } catch (\Throwable) {
            throw new ForbiddenAttendeeSchedulingObjectChange('DTSTART');
        }

        return self::instanceFields($vevent, $start, $end->getTimestamp());
    }

    /**
     * Start and end of a VEVENT, with the same DTEND / DURATION / all-day
     * defaults EventIterator applies.
     */
    private static function eventBounds($vevent, \DateTimeZone $timeZone): array {
        $start = $vevent->DTSTART->getDateTime($timeZone);
        if (isset($vevent->DTEND)) {
            $end = $vevent->DTEND->getDateTime($timeZone);
        } elseif (isset($vevent->DURATION)) {
            $end = $start->add($vevent->DURATION->getDateInterval());
        } elseif (!$vevent->DTSTART->hasTime()) {
            $end = $start->modify('+1 day');
        } else {
            $end = $start;
        }

        return [$start, $end];
    }

    private static function instanceFields($vevent, \DateTimeInterface $start, int $end): array {
        return [
            'DTSTART' => [$start->getTimestamp(), $vevent->DTSTART->hasTime()],
            'DTEND' => $end,
            'SUMMARY' => self::sortedValues($vevent, 'SUMMARY', false),
            'LOCATION' => self::sortedValues($vevent, 'LOCATION', false),
            'ORGANIZER' => self::sortedValues($vevent, 'ORGANIZER', true),
        ];
    }

    private static function sortedValues($vevent, string $propertyName, bool $isAddress): array {
        $values = [];
        foreach ($vevent->select($propertyName) as $property) {
            $values[] = $isAddress ? strtolower($property->getNormalizedValue()) : (string)$property->getValue();
        }
        sort($values);

        return $values;
    }
}
