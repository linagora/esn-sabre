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
        $masterIsFloating = self::isFloatingStart($oldMaster ?? $newMaster);

        $oldEvents = self::indexEvents($oldObject, $timeZone);
        $newEvents = self::indexEvents($newObject, $timeZone);

        self::assertSameMaster($oldMaster, $newMaster, $forbiddenProperties, $timeZone);
        self::assertNoOccurrenceAdded($oldMaster, $newMaster, $oldEvents, $newEvents, $timeZone, $forbiddenProperties);

        $oldGeneratedInstances = self::generatedInstances($oldMaster, $timeZone);
        $newGeneratedInstances = self::generatedInstances($newMaster, $timeZone);

        // Cursors only move forward, so instances must be visited in chronological order.
        $instanceKeys = self::chronological(array_unique(array_merge(array_keys($oldEvents), array_keys($newEvents))));
        foreach ($instanceKeys as $key) {
            $oldOverrides = $oldEvents[$key] ?? [];

            // Several overrides may describe the same instance (the strict-mode
            // validator refuses it, a default PUT does not), and readers apply
            // one of them: each of them must leave the instance unchanged.
            foreach ($newEvents[$key] ?? [null] as $position => $newEvent) {
                $oldEvent = self::counterpart($oldOverrides, $position);

                if ($oldEvent && $newEvent) {
                    self::assertSameExplicitEvent($oldEvent, $newEvent, $forbiddenProperties);
                } else {
                    self::assertSameInstance(
                        self::instanceAt($oldEvent, $oldGeneratedInstances, $key, $timeZone),
                        self::instanceAt($newEvent, $newGeneratedInstances, $key, $timeZone),
                        $forbiddenProperties
                    );
                }

                self::assertSameRecurrenceIdForm($oldEvent, $newEvent, $masterIsFloating);
            }
        }
    }

    /**
     * The old override a new one at the same instance is compared with: the
     * one at the same position, so that duplicates the organizer sent can be
     * kept as they are, or else the first one, which is the one EventIterator
     * applies. A new override can thus neither replace the instance readers
     * saw nor slip an edited copy next to it.
     */
    private static function counterpart(array $oldOverrides, int $position) {
        return $oldOverrides[$position] ?? $oldOverrides[0] ?? null;
    }

    /**
     * The key only matches a floating RECURRENCE-ID with a zoned one because it is
     * resolved in the master's time zone. Readers resolving it elsewhere (expansion
     * in UTC, clients in other zones) see another instant, and so an override that
     * no longer replaces the organizer's one. A RECURRENCE-ID must therefore keep
     * the organizer's form: an override may only be floating when the organizer's
     * one, or else the master's DTSTART, is.
     *
     * @throws ForbiddenAttendeeSchedulingObjectChange
     */
    private static function assertSameRecurrenceIdForm($oldEvent, $newEvent, ?bool $masterIsFloating): void {
        if (!$newEvent) {
            return;
        }

        $expected = $oldEvent ? self::isFloatingRecurrenceId($oldEvent) : $masterIsFloating;
        $actual = self::isFloatingRecurrenceId($newEvent);
        if ($expected !== null && $actual !== null && $expected !== $actual) {
            throw new ForbiddenAttendeeSchedulingObjectChange('DTSTART');
        }
    }

    private static function isFloatingRecurrenceId($vevent): ?bool {
        $recurrenceId = $vevent->{'RECURRENCE-ID'};

        return $recurrenceId instanceof \Sabre\VObject\Property\ICalendar\DateTime ? $recurrenceId->isFloating() : null;
    }

    private static function isFloatingStart($master): ?bool {
        $start = $master ? $master->DTSTART : null;

        return $start instanceof \Sabre\VObject\Property\ICalendar\DateTime ? $start->isFloating() : null;
    }

    private static function assertSameMaster($oldMaster, $newMaster, array $forbiddenProperties, \DateTimeZone $timeZone): void {
        if ($oldMaster && $newMaster) {
            self::assertSameExplicitEvent($oldMaster, $newMaster, $forbiddenProperties);
            self::assertSameDuration($oldMaster, $newMaster, $timeZone);
        } elseif ($newMaster) {
            // A master the old copy did not have generates instances the organizer never sent.
            throw new ForbiddenAttendeeSchedulingObjectChange('DTSTART');
        }
    }

    /**
     * DTEND alone does not tell the length of the occurrences: a master with a
     * DURATION has none, and so would compare equal whatever its DURATION.
     *
     * @throws ForbiddenAttendeeSchedulingObjectChange
     */
    private static function assertSameDuration($oldMaster, $newMaster, \DateTimeZone $timeZone): void {
        if (self::duration($oldMaster, $timeZone) !== self::duration($newMaster, $timeZone)) {
            throw new ForbiddenAttendeeSchedulingObjectChange('DTEND');
        }
    }

    private static function duration($vevent, \DateTimeZone $timeZone): ?int {
        try {
            [$start, $end] = self::eventBounds($vevent, $timeZone);

            return $end->getTimestamp() - $start->getTimestamp();
        } catch (\Throwable) {
            // DTSTART and DTEND signatures are compared anyway.
            return null;
        }
    }

    /**
     * The instance-by-instance comparison only visits the keys of explicit
     * overrides: the occurrences only the new master generates (changed
     * RRULE, RDATE, EXRULE, removed EXDATE) must be looked up too. Each of
     * them must be one the old master generated, which allows the removal of
     * occurrences (lower COUNT, earlier UNTIL, added EXDATE) and nothing else.
     *
     * @throws ForbiddenAttendeeSchedulingObjectChange
     */
    private static function assertNoOccurrenceAdded($oldMaster, $newMaster, array $oldEvents, array $newEvents, \DateTimeZone $timeZone, array $forbiddenProperties): void {
        if (!$oldMaster || !$newMaster) {
            return;
        }

        $oldGeneratedInstances = self::generatedInstances($oldMaster, $timeZone);
        $newGeneratedInstances = self::generatedInstances($newMaster, $timeZone);
        $uncheckedKeys = array_filter(
            self::occurrenceKeysTheNewMasterMayAdd($oldMaster, $newMaster, $timeZone),
            fn(string $key): bool => !isset($oldEvents[$key]) && !isset($newEvents[$key])
        );
        foreach ($uncheckedKeys as $key) {
            self::assertSameInstance($oldGeneratedInstances($key), $newGeneratedInstances($key), $forbiddenProperties);
        }
    }

    /**
     * Chronological keys of the occurrences the new master may generate while
     * the old one did not. With the same recurrence rule (DTSTART is compared
     * already), only the old EXDATEs may be brought back. Otherwise the whole
     * new series is expanded, which an endless one cannot be.
     *
     * @throws ForbiddenAttendeeSchedulingObjectChange
     */
    private static function occurrenceKeysTheNewMasterMayAdd($oldMaster, $newMaster, \DateTimeZone $timeZone): array {
        try {
            return self::hasSameRecurrenceRule($oldMaster, $newMaster)
                ? self::chronological(array_values(array_unique(self::excludedOccurrenceKeys($oldMaster, $timeZone))))
                : self::expandedOccurrenceKeys($newMaster, $timeZone);
        } catch (\Sabre\VObject\Recur\NoInstancesException) {
            return [];
        } catch (ForbiddenAttendeeSchedulingObjectChange $e) {
            throw $e;
        } catch (\Throwable) {
            // Fail closed: occurrences we cannot enumerate cannot be proven scheduled by the organizer.
            throw new ForbiddenAttendeeSchedulingObjectChange('DTSTART');
        }
    }

    private static function hasSameRecurrenceRule($oldMaster, $newMaster): bool {
        $recurrenceProperties = ['RRULE', 'RDATE', 'EXRULE'];

        return array_reduce(
            $recurrenceProperties,
            fn(bool $same, string $propertyName): bool => $same
                && CalendarObjectHelper::propertySignatures($oldMaster, $propertyName) === CalendarObjectHelper::propertySignatures($newMaster, $propertyName),
            true
        );
    }

    private static function excludedOccurrenceKeys($master, \DateTimeZone $timeZone): array {
        $keys = [];
        foreach ($master->select('EXDATE') as $exdate) {
            foreach ($exdate->getDateTimes($timeZone) as $excluded) {
                $keys[] = 'at:' . $excluded->getTimestamp();
            }
        }

        return $keys;
    }

    private static function expandedOccurrenceKeys($master, \DateTimeZone $timeZone): array {
        $iterator = new EventIterator([$master], null, $timeZone);
        if ($iterator->isInfinite()) {
            throw new ForbiddenAttendeeSchedulingObjectChange('DTSTART');
        }

        $keys = [];
        for (; $iterator->valid(); $iterator->next()) {
            $keys[] = 'at:' . $iterator->current()->getTimestamp();
        }

        return $keys;
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
     * Overrides grouped by their RECURRENCE-ID instant ("at:<timestamp>"), or
     * by their raw value ("raw:<value>") when it cannot be parsed, in document
     * order. Duplicates are kept: dropping all but one would leave the others
     * unchecked.
     */
    private static function indexEvents(VCalendar $calendarObject, \DateTimeZone $timeZone): array {
        $events = [];
        foreach ($calendarObject->select('VEVENT') as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'})) {
                $events[self::recurrenceKey($vevent, $timeZone)][] = $vevent;
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

            return self::instanceFields($vevent, $start, $end->getTimestamp());
        } catch (\Throwable) {
            // Fail closed: an instance we cannot evaluate cannot be proven unchanged.
            throw new ForbiddenAttendeeSchedulingObjectChange('DTSTART');
        }
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
            // ORGANIZER;VALUE=TEXT is not a CalAddress and has no normalized form.
            $values[] = $isAddress && $property instanceof \Sabre\VObject\Property\ICalendar\CalAddress
                ? strtolower($property->getNormalizedValue())
                : (string)$property->getValue();
        }
        sort($values);

        return $values;
    }
}
