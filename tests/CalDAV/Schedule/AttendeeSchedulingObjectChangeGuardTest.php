<?php

namespace ESN\CalDAV\Schedule;

use ESN\CalDAV\Schedule\Exception\ForbiddenAttendeeSchedulingObjectChange;
use PHPUnit\Framework\Attributes\DataProvider;
use Sabre\VObject\Reader;
use Sabre\VObject\Settings;

/**
 * An attendee must not change DTSTART, DTEND, SUMMARY, LOCATION or ORGANIZER
 * of any instance of their copy, whichever VEVENT (master or override) carries
 * that instance before and after the change.
 */
class AttendeeSchedulingObjectChangeGuardTest extends \PHPUnit\Framework\TestCase {
    private const FORBIDDEN_PROPERTIES = ['DTSTART', 'DTEND', 'LOCATION', 'SUMMARY', 'ORGANIZER'];

    private const SERIES_KINDS = ['timed', 'all-day'];

    private const NEW_OVERRIDE = 'new override of an implicit occurrence';
    private const MASTER_TURNED_INTO_OVERRIDE = 'master turned into an override';
    private const MASTER_DROPPED = 'master dropped, occurrence kept as an override';
    private const ORGANIZER_OVERRIDE_DROPPED = 'organizer override dropped in favour of the master';
    private const EXISTING_OVERRIDE_CHANGED = 'existing override changed';
    private const MASTER_CHANGED = 'master changed';
    private const RECURRENCE_ID_AS_UTC = 'override recurrence id rewritten in UTC';
    private const RECURRENCE_ID_AS_FLOATING = 'override recurrence id rewritten as floating';

    /** Scenarios where one side of the comparison is an occurrence generated from the master. */
    private const GENERATED_INSTANCE_SCENARIOS = [
        self::NEW_OVERRIDE,
        self::MASTER_TURNED_INTO_OVERRIDE,
        self::MASTER_DROPPED,
        self::ORGANIZER_OVERRIDE_DROPPED,
    ];

    /** Scenarios where both sides are explicit VEVENTs sharing a recurrence key. */
    private const EXPLICIT_INSTANCE_SCENARIOS = [
        self::EXISTING_OVERRIDE_CHANGED,
        self::MASTER_CHANGED,
        self::RECURRENCE_ID_AS_UTC,
        self::RECURRENCE_ID_AS_FLOATING,
    ];

    private const SERIES_START = '20350101';
    private const OCCURRENCE = '20350107';

    public static function forbiddenChanges(): iterable {
        foreach (self::SERIES_KINDS as $kind) {
            foreach (array_merge(self::GENERATED_INSTANCE_SCENARIOS, self::EXPLICIT_INSTANCE_SCENARIOS) as $scenario) {
                if (!self::appliesTo($kind, $scenario)) {
                    continue;
                }
                foreach (self::forbiddenChangesOf($kind, self::scenarioDay($scenario)) as $label => [$changes, $propertyName]) {
                    yield "$kind / $scenario / $label" => [$kind, $scenario, $changes, $propertyName];
                }
            }
        }
    }

    public static function allowedChanges(): iterable {
        foreach (self::SERIES_KINDS as $kind) {
            foreach (array_merge(self::GENERATED_INSTANCE_SCENARIOS, self::EXPLICIT_INSTANCE_SCENARIOS) as $scenario) {
                if (!self::appliesTo($kind, $scenario)) {
                    continue;
                }
                $changes = self::attendeeLocalChangesOf();
                if (in_array($scenario, self::GENERATED_INSTANCE_SCENARIOS, true)) {
                    // Generated occurrences are compared by value, so equivalent spellings are fine.
                    $changes += self::equivalentSpellingsOf($kind, self::scenarioDay($scenario));
                }
                foreach ($changes as $label => $change) {
                    yield "$kind / $scenario / $label" => [$kind, $scenario, $change];
                }
            }
        }
    }

    #[DataProvider('forbiddenChanges')]
    function testAttendeeCannotChangeOrganizerOwnedFieldOfAnyInstance(string $kind, string $scenario, array $changes, string $propertyName) {
        [$oldObject, $newObject] = self::reshape($kind, $scenario, $changes);

        $this->assertRefused($oldObject, $newObject, $propertyName);
    }

    #[DataProvider('allowedChanges')]
    function testAttendeeCanReshapeTheirCopyWithoutChangingOrganizerOwnedFields(string $kind, string $scenario, array $changes) {
        [$oldObject, $newObject] = self::reshape($kind, $scenario, $changes);

        $this->assertAllowed($oldObject, $newObject);
    }

    #[DataProvider('seriesKinds')]
    function testAttendeeCanTurnMasterIntoOverrideKeepingTheOccurrenceUnchanged(string $kind) {
        // Truncating the series to one occurrence only removes instances from the attendee's copy.
        $this->assertAllowed(
            self::calendar(self::master($kind)),
            self::calendar(self::master($kind, ['RECURRENCE-ID' => self::recurrenceIdLine($kind, self::SERIES_START)]))
        );
    }

    #[DataProvider('seriesKinds')]
    function testAttendeeCanExcludeAnOccurrenceFromTheirCopy(string $kind) {
        $this->assertAllowed(
            self::calendar(self::master($kind)),
            self::calendar(self::master($kind, ['EXDATE' => self::exdateLine($kind, self::OCCURRENCE)]))
        );
    }

    #[DataProvider('seriesKinds')]
    function testAttendeeCanExcludeAnOrganizerOverrideFromTheirCopy(string $kind) {
        $this->assertAllowed(
            self::calendar(self::master($kind), self::override($kind, self::OCCURRENCE, ['SUMMARY' => 'SUMMARY:Moved by organizer'])),
            self::calendar(self::master($kind, ['EXDATE' => self::exdateLine($kind, self::OCCURRENCE)]))
        );
    }

    #[DataProvider('seriesKinds')]
    function testAttendeeCanAnswerTheOnlyOccurrenceTheyAreInvitedTo(string $kind) {
        $this->assertAllowed(
            self::calendar(self::override($kind, self::OCCURRENCE)),
            self::calendar(self::override($kind, self::OCCURRENCE, ['ATTENDEE-ALICE' => 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:alice@example.org']))
        );
    }

    #[DataProvider('seriesKinds')]
    function testAttendeeCanDropOneOfTheOccurrencesTheyAreInvitedTo(string $kind) {
        $otherOccurrence = self::addDays(self::OCCURRENCE, 1);

        $this->assertAllowed(
            self::calendar(self::override($kind, self::OCCURRENCE), self::override($kind, $otherOccurrence)),
            self::calendar(self::override($kind, $otherOccurrence))
        );
    }

    #[DataProvider('seriesKinds')]
    function testAttendeeCannotAddAMasterToACopyWithoutOne(string $kind) {
        $this->assertRefused(
            self::calendar(self::override($kind, self::OCCURRENCE)),
            self::calendar(self::master($kind), self::override($kind, self::OCCURRENCE)),
            'DTSTART'
        );
    }

    #[DataProvider('seriesKinds')]
    function testAttendeeCannotOverrideAnOccurrenceBeyondTheEndOfTheSeries(string $kind) {
        $this->assertRefused(
            self::calendar(self::master($kind)),
            self::calendar(self::master($kind), self::override($kind, '20350301')),
            'DTSTART'
        );
    }

    #[DataProvider('seriesKinds')]
    function testAttendeeCannotOverrideAnOccurrenceTheOrganizerExcluded(string $kind) {
        $master = self::master($kind, ['EXDATE' => self::exdateLine($kind, self::OCCURRENCE)]);

        $this->assertRefused(
            self::calendar($master),
            self::calendar($master, self::override($kind, self::OCCURRENCE)),
            'DTSTART'
        );
    }

    #[DataProvider('seriesKinds')]
    function testAttendeeCannotAddAnOverrideWithAnUnparsableRecurrenceId(string $kind) {
        $this->assertRefused(
            self::calendar(self::master($kind)),
            self::calendar(self::master($kind), self::override($kind, self::OCCURRENCE, ['RECURRENCE-ID' => 'RECURRENCE-ID:not-a-date'])),
            'DTSTART'
        );
    }

    #[DataProvider('seriesKinds')]
    function testAttendeeCannotKeepAnUnparsableRecurrenceIdOverrideWhileChangingIt(string $kind) {
        $override = ['RECURRENCE-ID' => 'RECURRENCE-ID:not-a-date'];

        $this->assertRefused(
            self::calendar(self::master($kind), self::override($kind, self::OCCURRENCE, $override)),
            self::calendar(self::master($kind), self::override($kind, self::OCCURRENCE, $override + ['SUMMARY' => 'SUMMARY:Daily changed'])),
            'SUMMARY'
        );
    }

    function testAttendeeCannotOverrideATimedOccurrenceAtAnotherTimeOfTheSameDay() {
        $this->assertRefused(
            self::calendar(self::master('timed')),
            self::calendar(self::master('timed'), self::override('timed', self::OCCURRENCE, ['RECURRENCE-ID' => 'RECURRENCE-ID:' . self::OCCURRENCE . 'T120000Z'])),
            'DTSTART'
        );
    }

    function testAttendeeCannotOverrideATimedOccurrenceWithADateRecurrenceId() {
        $this->assertRefused(
            self::calendar(self::master('timed')),
            self::calendar(self::master('timed'), self::override('timed', self::OCCURRENCE, ['RECURRENCE-ID' => 'RECURRENCE-ID;VALUE=DATE:' . self::OCCURRENCE])),
            'DTSTART'
        );
    }

    function testAttendeeCannotOverrideAnAllDayOccurrenceWithATimedRecurrenceId() {
        $this->assertRefused(
            self::calendar(self::master('all-day')),
            self::calendar(self::master('all-day'), self::override('all-day', self::OCCURRENCE, ['RECURRENCE-ID' => 'RECURRENCE-ID:' . self::OCCURRENCE . 'T120000Z'])),
            'DTSTART'
        );
    }

    function testAttendeeCannotChangeAFarOccurrenceOfAnEndlessSeries() {
        $master = self::master('timed', ['RRULE' => 'RRULE:FREQ=DAILY']);
        $farOccurrence = '20360107';

        $this->assertRefused(
            self::calendar($master),
            self::calendar($master, self::override('timed', $farOccurrence, ['SUMMARY' => 'SUMMARY:Daily changed'])),
            'SUMMARY'
        );
    }

    function testAttendeeCanAnswerAFarOccurrenceOfAnEndlessSeries() {
        $master = self::master('timed', ['RRULE' => 'RRULE:FREQ=DAILY']);
        $farOccurrence = '20360107';

        $this->assertAllowed(
            self::calendar($master),
            self::calendar($master, self::override('timed', $farOccurrence, ['ATTENDEE-ALICE' => 'ATTENDEE;PARTSTAT=DECLINED:mailto:alice@example.org']))
        );
    }

    function testAttendeeChangeOfAnOccurrenceBeyondTheRecurrenceExpansionLimitIsRefused() {
        // The guard fails closed: an occurrence it cannot expand cannot be proven unchanged.
        $previousLimit = Settings::$maxRecurrences;
        Settings::$maxRecurrences = 10;
        try {
            $master = self::master('timed', ['RRULE' => 'RRULE:FREQ=DAILY']);

            $this->assertRefused(
                self::calendar($master),
                self::calendar($master, self::override('timed', self::addDays(self::SERIES_START, 20))),
                'DTSTART'
            );
        } finally {
            Settings::$maxRecurrences = $previousLimit;
        }
    }

    #[DataProvider('seriesKinds')]
    function testAttendeeCanAnswerSeveralOccurrencesListedOutOfChronologicalOrder(string $kind) {
        $answer = ['ATTENDEE-ALICE' => 'ATTENDEE;PARTSTAT=ACCEPTED:mailto:alice@example.org'];

        $this->assertAllowed(
            self::calendar(self::master($kind)),
            self::calendar(
                self::master($kind),
                self::override($kind, self::addDays(self::OCCURRENCE, 10), $answer),
                self::override($kind, self::OCCURRENCE, $answer),
                self::override($kind, self::addDays(self::OCCURRENCE, 5), $answer)
            )
        );
    }

    #[DataProvider('seriesKinds')]
    function testAttendeeCannotChangeOneOfSeveralOccurrencesListedOutOfChronologicalOrder(string $kind) {
        $this->assertRefused(
            self::calendar(self::master($kind)),
            self::calendar(
                self::master($kind),
                self::override($kind, self::addDays(self::OCCURRENCE, 10)),
                self::override($kind, self::OCCURRENCE),
                self::override($kind, self::addDays(self::OCCURRENCE, 5), ['SUMMARY' => 'SUMMARY:Daily changed'])
            ),
            'SUMMARY'
        );
    }

    public static function seriesKinds(): iterable {
        foreach (self::SERIES_KINDS as $kind) {
            yield $kind => [$kind];
        }
    }

    /**
     * @return array{0: string, 1: string} old and new copies of the attendee's scheduling object
     */
    private static function reshape(string $kind, string $scenario, array $changes): array {
        $day = self::scenarioDay($scenario);
        $master = self::master($kind);

        return match ($scenario) {
            self::NEW_OVERRIDE => [
                self::calendar($master),
                self::calendar($master, self::override($kind, $day, $changes)),
            ],
            self::MASTER_TURNED_INTO_OVERRIDE => [
                self::calendar($master),
                self::calendar(self::master($kind, ['RECURRENCE-ID' => self::recurrenceIdLine($kind, $day)] + $changes)),
            ],
            self::MASTER_DROPPED => [
                self::calendar($master),
                self::calendar(self::override($kind, $day, $changes)),
            ],
            self::ORGANIZER_OVERRIDE_DROPPED => [
                self::calendar($master, self::override($kind, $day, $changes)),
                self::calendar($master),
            ],
            self::EXISTING_OVERRIDE_CHANGED => [
                self::calendar($master, self::override($kind, $day)),
                self::calendar($master, self::override($kind, $day, $changes)),
            ],
            self::MASTER_CHANGED => [
                self::calendar($master),
                self::calendar(self::master($kind, $changes)),
            ],
            self::RECURRENCE_ID_AS_UTC => [
                self::calendar($master, self::override($kind, $day)),
                self::calendar($master, self::override($kind, $day, ['RECURRENCE-ID' => 'RECURRENCE-ID:' . $day . 'T080000Z'] + $changes)),
            ],
            self::RECURRENCE_ID_AS_FLOATING => [
                self::calendar($master, self::override($kind, $day)),
                self::calendar($master, self::override($kind, $day, ['RECURRENCE-ID' => 'RECURRENCE-ID:' . $day . 'T090000'] + $changes)),
            ],
        };
    }

    private static function appliesTo(string $kind, string $scenario): bool {
        // An all-day RECURRENCE-ID only has the DATE form.
        return $kind === 'timed' || !in_array($scenario, [self::RECURRENCE_ID_AS_UTC, self::RECURRENCE_ID_AS_FLOATING], true);
    }

    private static function scenarioDay(string $scenario): string {
        return in_array($scenario, [self::MASTER_TURNED_INTO_OVERRIDE, self::MASTER_CHANGED], true)
            ? self::SERIES_START
            : self::OCCURRENCE;
    }

    /**
     * @return array<string, array{0: array, 1: string}> label => [property changes, property the guard must report]
     */
    private static function forbiddenChangesOf(string $kind, string $day): array {
        $timed = $kind === 'timed';

        return [
            'DTSTART moved' => [['DTSTART' => $timed
                ? 'DTSTART;TZID=Europe/Paris:' . $day . 'T093000'
                : 'DTSTART;VALUE=DATE:' . self::addDays($day, 1)], 'DTSTART'],
            'DTSTART and DTEND moved together' => [$timed
                ? ['DTSTART' => 'DTSTART;TZID=Europe/Paris:' . $day . 'T100000', 'DTEND' => 'DTEND;TZID=Europe/Paris:' . $day . 'T110000']
                : ['DTSTART' => 'DTSTART;VALUE=DATE:' . self::addDays($day, 1), 'DTEND' => 'DTEND;VALUE=DATE:' . self::addDays($day, 2)], 'DTSTART'],
            'DTSTART switched to another value type' => [$timed
                ? ['DTSTART' => 'DTSTART;VALUE=DATE:' . $day, 'DTEND' => 'DTEND;VALUE=DATE:' . self::addDays($day, 1)]
                : ['DTSTART' => 'DTSTART;TZID=Europe/Paris:' . $day . 'T000000', 'DTEND' => 'DTEND;TZID=Europe/Paris:' . self::addDays($day, 1) . 'T000000'], 'DTSTART'],
            'DTEND moved' => [['DTEND' => $timed
                ? 'DTEND;TZID=Europe/Paris:' . $day . 'T110000'
                : 'DTEND;VALUE=DATE:' . self::addDays($day, 2)], 'DTEND'],
            'DTEND replaced by a longer DURATION' => [['DTEND' => null, 'DURATION' => $timed ? 'DURATION:PT2H' : 'DURATION:P2D'], 'DTEND'],
            'SUMMARY changed' => [['SUMMARY' => 'SUMMARY:Daily changed'], 'SUMMARY'],
            'SUMMARY changed by a trailing space' => [['SUMMARY' => 'SUMMARY:Daily '], 'SUMMARY'],
            'SUMMARY removed' => [['SUMMARY' => null], 'SUMMARY'],
            'second SUMMARY added' => [['SUMMARY-2' => 'SUMMARY:Daily changed'], 'SUMMARY'],
            'LOCATION changed' => [['LOCATION' => 'LOCATION:Room B'], 'LOCATION'],
            'LOCATION removed' => [['LOCATION' => null], 'LOCATION'],
            'second LOCATION added' => [['LOCATION-2' => 'LOCATION:Room B'], 'LOCATION'],
            'ORGANIZER changed' => [['ORGANIZER' => 'ORGANIZER:mailto:mallory@example.org'], 'ORGANIZER'],
            'ORGANIZER removed' => [['ORGANIZER' => null], 'ORGANIZER'],
            'second ORGANIZER added' => [['ORGANIZER-2' => 'ORGANIZER:mailto:mallory@example.org'], 'ORGANIZER'],
        ];
    }

    /**
     * @return array<string, array> label => property changes RFC 6638 leaves to the attendee
     */
    private static function attendeeLocalChangesOf(): array {
        return [
            'unchanged' => [],
            'attendee answered' => ['ATTENDEE-ALICE' => 'ATTENDEE;PARTSTAT=DECLINED:mailto:alice@example.org'],
            'transparency changed' => ['TRANSP' => 'TRANSP:TRANSPARENT'],
            'personal alarm added' => ['VALARM' => "BEGIN:VALARM\r\nACTION:DISPLAY\r\nTRIGGER:-PT5M\r\nDESCRIPTION:Reminder\r\nEND:VALARM"],
        ];
    }

    /**
     * @return array<string, array> label => property changes spelling the same values differently
     */
    private static function equivalentSpellingsOf(string $kind, string $day): array {
        $spellings = [
            'DTEND written as the equivalent DURATION' => ['DTEND' => null, 'DURATION' => $kind === 'timed' ? 'DURATION:PT1H' : 'DURATION:P1D'],
            'ORGANIZER written in another case' => ['ORGANIZER' => 'ORGANIZER:MAILTO:BOB@EXAMPLE.ORG'],
        ];
        if ($kind === 'timed') {
            $spellings['DTSTART and DTEND written in UTC'] = ['DTSTART' => 'DTSTART:' . $day . 'T080000Z', 'DTEND' => 'DTEND:' . $day . 'T090000Z'];
        }

        return $spellings;
    }

    private static function master(string $kind, array $changes = []): array {
        return self::applyChanges(
            ['UID' => 'UID:event-uid']
            + self::boundsOf($kind, self::SERIES_START)
            + ['RRULE' => 'RRULE:FREQ=DAILY;COUNT=30']
            + self::sharedPropertiesOf(),
            $changes
        );
    }

    private static function override(string $kind, string $day, array $changes = []): array {
        return self::applyChanges(
            ['UID' => 'UID:event-uid', 'RECURRENCE-ID' => self::recurrenceIdLine($kind, $day)]
            + self::boundsOf($kind, $day)
            + self::sharedPropertiesOf(),
            $changes
        );
    }

    private static function boundsOf(string $kind, string $day): array {
        return $kind === 'timed'
            ? ['DTSTART' => 'DTSTART;TZID=Europe/Paris:' . $day . 'T090000', 'DTEND' => 'DTEND;TZID=Europe/Paris:' . $day . 'T100000']
            : ['DTSTART' => 'DTSTART;VALUE=DATE:' . $day, 'DTEND' => 'DTEND;VALUE=DATE:' . self::addDays($day, 1)];
    }

    private static function sharedPropertiesOf(): array {
        return [
            'SUMMARY' => 'SUMMARY:Daily',
            'LOCATION' => 'LOCATION:Room A',
            'ORGANIZER' => 'ORGANIZER:mailto:bob@example.org',
            'ATTENDEE-BOB' => 'ATTENDEE:mailto:bob@example.org',
            'ATTENDEE-ALICE' => 'ATTENDEE:mailto:alice@example.org',
        ];
    }

    private static function recurrenceIdLine(string $kind, string $day): string {
        return $kind === 'timed'
            ? 'RECURRENCE-ID;TZID=Europe/Paris:' . $day . 'T090000'
            : 'RECURRENCE-ID;VALUE=DATE:' . $day;
    }

    private static function exdateLine(string $kind, string $day): string {
        return $kind === 'timed'
            ? 'EXDATE;TZID=Europe/Paris:' . $day . 'T090000'
            : 'EXDATE;VALUE=DATE:' . $day;
    }

    /**
     * @param array<string, ?string> $changes property key => replacement line, or null to remove it
     */
    private static function applyChanges(array $properties, array $changes): array {
        foreach ($changes as $key => $line) {
            if ($line === null) {
                unset($properties[$key]);
            } else {
                $properties[$key] = $line;
            }
        }

        return $properties;
    }

    private static function calendar(array ...$events): string {
        $calendar = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n";
        foreach ($events as $properties) {
            $calendar .= "BEGIN:VEVENT\r\n" . implode("\r\n", $properties) . "\r\nEND:VEVENT\r\n";
        }

        return $calendar . "END:VCALENDAR\r\n";
    }

    private static function addDays(string $day, int $days): string {
        return (new \DateTimeImmutable($day))->modify('+' . $days . ' day')->format('Ymd');
    }

    private function assertRefused(string $oldObject, string $newObject, string $propertyName): void {
        try {
            AttendeeSchedulingObjectChangeGuard::assertAllowed(Reader::read($oldObject), Reader::read($newObject), self::FORBIDDEN_PROPERTIES);
            $this->fail($propertyName . ' change should be refused');
        } catch (ForbiddenAttendeeSchedulingObjectChange $e) {
            $this->assertSame('Attendees are not allowed to change ' . $propertyName . ' on scheduling objects', $e->getMessage());
        }
    }

    private function assertAllowed(string $oldObject, string $newObject): void {
        AttendeeSchedulingObjectChangeGuard::assertAllowed(Reader::read($oldObject), Reader::read($newObject), self::FORBIDDEN_PROPERTIES);

        $this->addToAssertionCount(1);
    }
}
