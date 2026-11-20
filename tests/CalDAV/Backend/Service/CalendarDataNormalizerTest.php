<?php

namespace ESN\CalDAV\Backend\Service;

use PHPUnit\Framework\TestCase;
use Sabre\DAV\Exception\BadRequest;

/**
 * The stored first/last occurrence window is what the backend uses to discard
 * objects before post-filtering time-range queries: it must always cover every
 * instance the event generates.
 */
class CalendarDataNormalizerTest extends TestCase {
    private $normalizer;

    protected function setUp(): void {
        $this->normalizer = new CalendarDataNormalizer();
    }

    function testDurationShouldExtendLastOccurrence() {
        $data = $this->normalizer->getDenormalizedData($this->eventWith([
            'DTSTART:20260101T100000Z',
            'DURATION:PT1H'
        ]));

        $this->assertSame(strtotime('2026-01-01T10:00:00Z'), $data['firstOccurence']);
        $this->assertSame(strtotime('2026-01-01T11:00:00Z'), $data['lastOccurence']);
    }

    function testPeriodDtStartShouldBeRejected() {
        $this->assertRejected([
            'DTSTART;VALUE=PERIOD:20260101T100000Z/20260101T110000Z'
        ]);
    }

    function testPeriodDtEndShouldBeRejected() {
        $this->assertRejected([
            'DTSTART:20260101T100000Z',
            'DTEND;VALUE=PERIOD:20260101T100000Z/20260101T110000Z'
        ]);
    }

    function testPeriodDtEndOfRecurringEventShouldBeRejected() {
        $this->assertRejected([
            'DTSTART:20260101T100000Z',
            'DTEND;VALUE=PERIOD:20260101T100000Z/20260101T110000Z',
            'RRULE:FREQ=DAILY;COUNT=3'
        ]);
    }

    function testMissingDtStartShouldBeRejected() {
        $this->assertRejected([
            'DTEND:20260101T110000Z'
        ]);
    }

    function testEmptyDurationShouldBeRejected() {
        $this->assertRejected([
            'DTSTART:20260101T100000Z',
            'DURATION:'
        ]);
    }

    function testInvalidDurationShouldBeRejected() {
        $this->assertRejected([
            'DTSTART:20260101T100000Z',
            'DURATION:one hour'
        ]);
    }

    private function assertRejected(array $dateLines): void {
        $this->expectException(BadRequest::class);

        $this->normalizer->getDenormalizedData($this->eventWith($dateLines));
    }

    private function eventWith(array $dateLines): string {
        return implode("\r\n", array_merge([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260101T000000Z'
        ], $dateLines, [
            'END:VEVENT',
            'END:VCALENDAR'
        ])) . "\r\n";
    }

    private function denormalize(string $vevent): array {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\n"
             . $vevent
             . "END:VCALENDAR\r\n";

        return (new CalendarDataNormalizer())->getDenormalizedData($ics);
    }

    public function testNegativeDurationDoesNotInvertOccurrenceWindow(): void {
        $d = $this->denormalize("BEGIN:VEVENT\r\nUID:neg\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260515T090000Z\r\nDURATION:-PT1H\r\nEND:VEVENT\r\n");

        $this->assertEquals(strtotime('20260515T090000Z'), $d['firstOccurence']);
        $this->assertGreaterThanOrEqual($d['firstOccurence'], $d['lastOccurence']);
    }

    public function testNegativeDurationRecurringEventCoversLastInstanceStart(): void {
        $d = $this->denormalize("BEGIN:VEVENT\r\nUID:neg-rrule\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260515T090000Z\r\nDURATION:-PT1H\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\n");

        $this->assertGreaterThanOrEqual(strtotime('20260517T090000Z'), $d['lastOccurence']);
    }

    public function testRdateInstancesAreCoveredByLastOccurrence(): void {
        $d = $this->denormalize("BEGIN:VEVENT\r\nUID:r\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260515T090000Z\r\nDTEND:20260515T100000Z\r\n"
            . "RDATE:20260520T090000Z,20260601T090000Z\r\nEND:VEVENT\r\n");

        $this->assertEquals(strtotime('20260515T090000Z'), $d['firstOccurence']);
        $this->assertEquals(strtotime('20260601T100000Z'), $d['lastOccurence']);
    }

    public function testRdateBeforeDtStartShouldLowerFirstOccurrence(): void {
        $d = $this->denormalize("BEGIN:VEVENT\r\nUID:rdate\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260601T090000Z\r\nDURATION:PT1H\r\nRRULE:FREQ=DAILY;COUNT=2\r\n"
            . "RDATE:20260520T090000Z\r\nEND:VEVENT\r\n");

        $this->assertEquals(strtotime('20260520T090000Z'), $d['firstOccurence']);
        $this->assertEquals(strtotime('20260602T100000Z'), $d['lastOccurence']);
    }

    public function testRdatePeriodBeforeDtStartShouldLowerFirstOccurrence(): void {
        $d = $this->denormalize("BEGIN:VEVENT\r\nUID:rdate\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260601T090000Z\r\nDURATION:PT1H\r\nRRULE:FREQ=DAILY;COUNT=2\r\n"
            . "RDATE;VALUE=PERIOD:20260520T090000Z/PT1H\r\nEND:VEVENT\r\n");

        $this->assertEquals(strtotime('20260520T090000Z'), $d['firstOccurence']);
    }

    public function testRdateOnlyBeforeDtStartShouldLowerFirstOccurrence(): void {
        $d = $this->denormalize("BEGIN:VEVENT\r\nUID:rdate\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260601T090000Z\r\nDTEND:20260601T100000Z\r\n"
            . "RDATE:20260701T090000Z,20260520T090000Z\r\nEND:VEVENT\r\n");

        $this->assertEquals(strtotime('20260520T090000Z'), $d['firstOccurence']);
        $this->assertEquals(strtotime('20260701T100000Z'), $d['lastOccurence']);
    }

    public function testExcludedDtStartKeepsDtStartAsFirstOccurrence(): void {
        $d = $this->denormalize("BEGIN:VEVENT\r\nUID:ex\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260601T090000Z\r\nDTEND:20260601T100000Z\r\nRRULE:FREQ=DAILY;COUNT=3\r\n"
            . "EXDATE:20260601T090000Z\r\nEND:VEVENT\r\n");

        $this->assertEquals(strtotime('20260601T090000Z'), $d['firstOccurence']);
    }

    public function testInfiniteRuleStartingAfterMaxDateKeepsWindowOrdered(): void {
        $d = $this->denormalize("BEGIN:VEVENT\r\nUID:far\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20400101T090000Z\r\nDTEND:20400101T100000Z\r\nRRULE:FREQ=DAILY\r\nEND:VEVENT\r\n");

        $this->assertGreaterThanOrEqual($d['firstOccurence'], $d['lastOccurence']);
    }

    public function testSimpleEventWindowIsUnchanged(): void {
        $d = $this->denormalize("BEGIN:VEVENT\r\nUID:simple\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260515T090000Z\r\nDTEND:20260515T100000Z\r\nEND:VEVENT\r\n");

        $this->assertEquals(strtotime('20260515T090000Z'), $d['firstOccurence']);
        $this->assertEquals(strtotime('20260515T100000Z'), $d['lastOccurence']);
    }

    public function testOverrideOwnRruleDoesNotExtendLastOccurrence(): void {
        $d = $this->denormalize(
            "BEGIN:VEVENT\r\nUID:o\r\nDTSTAMP:20250101T000000Z\r\n"
            . "DTSTART:20250101T100000Z\r\nDTEND:20250101T110000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:o\r\nDTSTAMP:20250101T000000Z\r\nRECURRENCE-ID:20250101T100000Z\r\n"
            . "DTSTART:20250105T140000Z\r\nDTEND:20250105T150000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\n");

        // An override is a single instance (RFC 4791 9.6.5): Sabre's post-filter
        // ignores its own RRULE, so its window stops at its own DTEND.
        $this->assertEquals(strtotime('20250101T100000Z'), $d['firstOccurence']);
        $this->assertEquals(strtotime('20250105T150000Z'), $d['lastOccurence']);
    }

    public function testOverrideOwnRdateDoesNotExtendLastOccurrence(): void {
        $d = $this->denormalize(
            "BEGIN:VEVENT\r\nUID:o\r\nDTSTAMP:20250101T000000Z\r\n"
            . "DTSTART:20250101T100000Z\r\nDTEND:20250101T110000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:o\r\nDTSTAMP:20250101T000000Z\r\nRECURRENCE-ID:20250101T100000Z\r\n"
            . "DTSTART:20250105T140000Z\r\nDTEND:20250105T150000Z\r\nRDATE:20250106T140000Z\r\nEND:VEVENT\r\n");

        $this->assertEquals(strtotime('20250101T100000Z'), $d['firstOccurence']);
        $this->assertEquals(strtotime('20250105T150000Z'), $d['lastOccurence']);
    }

    public function testInstanceMovedLaterByOverrideIsCoveredByLastOccurrence(): void {
        $d = $this->denormalize(
            "BEGIN:VEVENT\r\nUID:m\r\nDTSTAMP:20250101T000000Z\r\n"
            . "DTSTART:20250101T100000Z\r\nDTEND:20250101T110000Z\r\nRRULE:FREQ=DAILY;COUNT=3\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:m\r\nDTSTAMP:20250101T000000Z\r\nRECURRENCE-ID:20250103T100000Z\r\n"
            . "DTSTART:20250110T140000Z\r\nDTEND:20250110T150000Z\r\nEND:VEVENT\r\n");

        $this->assertEquals(strtotime('20250101T100000Z'), $d['firstOccurence']);
        $this->assertEquals(strtotime('20250110T150000Z'), $d['lastOccurence']);
    }

    /**
     * Expands the single VEVENT of $vevent and checks that the stored window
     * covers every instance, returning the stored window.
     */
    private function assertWindowCoversEveryInstance(string $vevent): array {
        $d = $this->denormalize($vevent);
        $vcal = \Sabre\VObject\Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Example//EN\r\n"
            . $vevent . "END:VCALENDAR\r\n");

        $lastEnd = null;
        $cap = \Sabre\VObject\Settings::$maxRecurrences;
        \Sabre\VObject\Settings::$maxRecurrences = -1;
        try {
            $it = new \Sabre\VObject\Recur\EventIterator($vcal->VEVENT);
            for (; $it->valid(); $it->next()) {
                $lastEnd = max($lastEnd ?? 0, $it->getDtStart()->getTimestamp(), $it->getDtEnd()->getTimestamp());
            }
        } finally {
            \Sabre\VObject\Settings::$maxRecurrences = $cap;
        }

        $this->assertGreaterThanOrEqual($lastEnd, $d['lastOccurence']);

        return $d;
    }

    public function testUntilBoundedSeriesEndsAtUntilPlusItsDuration(): void {
        $d = $this->assertWindowCoversEveryInstance("BEGIN:VEVENT\r\nUID:standup\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T080000Z\r\nDTEND:20260105T081500Z\r\n"
            . "RRULE:FREQ=DAILY;UNTIL=20371231T080000Z\r\nEND:VEVENT\r\n");

        // Beyond Settings::$maxRecurrences: the walk used to give up at MAX_DATE
        $this->assertSame(strtotime('20371231T081500Z'), $d['lastOccurence']);
    }

    public function testUntilBoundedSeriesBeyondMaxDateIsCappedAtMaxDate(): void {
        $d = $this->denormalize("BEGIN:VEVENT\r\nUID:far\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T080000Z\r\nDTEND:20260105T081500Z\r\n"
            . "RRULE:FREQ=MONTHLY;UNTIL=20991231T080000Z\r\nEND:VEVENT\r\n");

        $this->assertSame(strtotime(CalendarDataNormalizer::MAX_DATE), $d['lastOccurence']);
    }

    public function testUntilBeforeDtStartStillCoversTheFirstInstance(): void {
        $d = $this->assertWindowCoversEveryInstance("BEGIN:VEVENT\r\nUID:early\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260601T090000Z\r\nDTEND:20260601T100000Z\r\n"
            . "RRULE:FREQ=DAILY;UNTIL=20260101T000000Z\r\nEND:VEVENT\r\n");

        $this->assertSame(strtotime('20260601T100000Z'), $d['lastOccurence']);
    }

    public function testUntilBoundedSeriesWithANegativeDurationCoversItsLastStart(): void {
        $this->assertWindowCoversEveryInstance("BEGIN:VEVENT\r\nUID:neg\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260515T090000Z\r\nDURATION:-PT1H\r\n"
            . "RRULE:FREQ=DAILY;UNTIL=20260520T090000Z\r\nEND:VEVENT\r\n");
    }

    public static function untilBoundedSeries(): array {
        return [
            'local time across DST' => ["DTSTART;TZID=Europe/Paris:20260105T090000\r\nDTEND;TZID=Europe/Paris:20260105T091500\r\n"
                . "RRULE:FREQ=DAILY;UNTIL=20261231T080000Z\r\n"],
            'DATE UNTIL on a local time' => ["DTSTART;TZID=America/New_York:20260105T220000\r\nDTEND;TZID=America/New_York:20260105T230000\r\n"
                . "RRULE:FREQ=WEEKLY;UNTIL=20261228\r\n"],
            'floating' => ["DTSTART:20260105T233000\r\nDTEND:20260106T003000\r\nRRULE:FREQ=DAILY;UNTIL=20260131T233000\r\n"],
            'all-day' => ["DTSTART;VALUE=DATE:20260105\r\nRRULE:FREQ=WEEKLY;UNTIL=20261228\r\n"],
            'with EXDATE' => ["DTSTART:20260105T090000Z\r\nDTEND:20260105T100000Z\r\nRRULE:FREQ=DAILY;UNTIL=20260110T090000Z\r\n"
                . "EXDATE:20260110T090000Z\r\n"],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('untilBoundedSeries')]
    public function testUntilBoundedSeriesWindowCoversEveryInstance(string $dates): void {
        $this->assertWindowCoversEveryInstance("BEGIN:VEVENT\r\nUID:u\r\nDTSTAMP:20260101T000000Z\r\n"
            . $dates . "END:VEVENT\r\n");
    }

    public function testCountAboveTheExpansionLimitEndsAtMaxDate(): void {
        $d = $this->denormalize("BEGIN:VEVENT\r\nUID:count\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T080000Z\r\nDTEND:20260105T081500Z\r\n"
            . "RRULE:FREQ=HOURLY;COUNT=" . (\Sabre\VObject\Settings::$maxRecurrences + 1) . "\r\nEND:VEVENT\r\n");

        $this->assertSame(strtotime(CalendarDataNormalizer::MAX_DATE), $d['lastOccurence']);
    }

    public function testCountWithinTheExpansionLimitStillEndsAtItsLastInstance(): void {
        $d = $this->assertWindowCoversEveryInstance("BEGIN:VEVENT\r\nUID:count\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART:20260105T080000Z\r\nDTEND:20260105T081500Z\r\n"
            . "RRULE:FREQ=DAILY;COUNT=10\r\nEND:VEVENT\r\n");

        $this->assertSame(strtotime('20260114T081500Z'), $d['lastOccurence']);
    }

    public function testUnexpandableRuleFallsBackToDtStartAndAnOpenEndedWindow(): void {
        $log = ini_set('error_log', '/dev/null');
        try {
            $d = $this->denormalize("BEGIN:VEVENT\r\nUID:bad\r\nDTSTAMP:20260101T000000Z\r\n"
                . "DTSTART:20260601T090000Z\r\nDTEND:20260601T100000Z\r\n"
                . "RRULE:FREQ=DAILY;COUNT=2\r\nEXDATE:20260601T090000Z,20260602T090000Z\r\nEND:VEVENT\r\n");
        } finally {
            ini_set('error_log', $log);
        }

        // Every instance is excluded: the iterator cannot be built
        $this->assertSame(strtotime('20260601T090000Z'), $d['firstOccurence']);
        $this->assertSame(strtotime(CalendarDataNormalizer::MAX_DATE), $d['lastOccurence']);
    }

    private const PUBLIC_MASTER = "BEGIN:VEVENT\r\nUID:c\r\nDTSTAMP:20250101T000000Z\r\n"
        . "DTSTART:20250101T100000Z\r\nDTEND:20250101T110000Z\r\nRRULE:FREQ=DAILY;COUNT=3\r\nCLASS:PUBLIC\r\nEND:VEVENT\r\n";

    private static function override(string $class): string {
        return "BEGIN:VEVENT\r\nUID:c\r\nDTSTAMP:20250101T000000Z\r\nRECURRENCE-ID:20250102T100000Z\r\n"
            . "DTSTART:20250102T100000Z\r\nDTEND:20250102T110000Z\r\nCLASS:$class\r\nSUMMARY:Doctor\r\nEND:VEVENT\r\n";
    }

    public static function privateOverrideOrders(): array {
        return [
            'private override after public master' => [self::PUBLIC_MASTER . self::override('PRIVATE'), 'PRIVATE'],
            'private override before public master' => [self::override('PRIVATE') . self::PUBLIC_MASTER, 'PRIVATE'],
            'confidential override after public master' => [self::PUBLIC_MASTER . self::override('confidential'), 'CONFIDENTIAL'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('privateOverrideOrders')]
    public function testPrivateOverrideMakesTheObjectPrivate(string $vevents, string $expected): void {
        $this->assertSame($expected, $this->denormalize($vevents)['classification']);
    }

    public function testPrivateEventAfterPublicTodoMakesTheObjectPrivate(): void {
        $todo = "BEGIN:VTODO\r\nUID:t\r\nDTSTAMP:20250101T000000Z\r\nCLASS:PUBLIC\r\nEND:VTODO\r\n";

        $this->assertSame('PRIVATE', $this->denormalize($todo . self::override('PRIVATE'))['classification']);
    }

    public function testPublicSeriesKeepsItsClassification(): void {
        $this->assertSame('PUBLIC', $this->denormalize(self::PUBLIC_MASTER . self::override('PUBLIC'))['classification']);
    }

    public function testClassificationIsNullWithoutClass(): void {
        $this->assertNull($this->denormalize("BEGIN:VEVENT\r\nUID:n\r\nDTSTAMP:20250101T000000Z\r\n"
            . "DTSTART:20250101T100000Z\r\nEND:VEVENT\r\n")['classification']);
    }

    public function testSingleEventIsNotRecurring(): void {
        $this->assertFalse($this->denormalize("BEGIN:VEVENT\r\nUID:s\r\nDTSTAMP:20250101T000000Z\r\n"
            . "DTSTART:20250101T100000Z\r\nDTEND:20250101T110000Z\r\nEND:VEVENT\r\n")['recurring']);
    }

    public function testLoneOverrideIsNotRecurring(): void {
        $this->assertFalse($this->denormalize("BEGIN:VEVENT\r\nUID:o\r\nDTSTAMP:20250101T000000Z\r\n"
            . "RECURRENCE-ID:20250101T100000Z\r\nDTSTART:20250101T140000Z\r\nEND:VEVENT\r\n")['recurring']);
    }

    public function testRruleIsRecurring(): void {
        $this->assertTrue($this->denormalize("BEGIN:VEVENT\r\nUID:r\r\nDTSTAMP:20250101T000000Z\r\n"
            . "DTSTART:20250101T100000Z\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\n")['recurring']);
    }

    public function testRdateIsRecurring(): void {
        $this->assertTrue($this->denormalize("BEGIN:VEVENT\r\nUID:r\r\nDTSTAMP:20250101T000000Z\r\n"
            . "DTSTART:20250101T100000Z\r\nRDATE:20250105T100000Z\r\nEND:VEVENT\r\n")['recurring']);
    }

    public function testSeveralEventsAreRecurring(): void {
        // Detached instances without their master: the stored range is their hull
        $this->assertTrue($this->denormalize(
            "BEGIN:VEVENT\r\nUID:d\r\nDTSTAMP:20250101T000000Z\r\nRECURRENCE-ID:20250101T100000Z\r\n"
            . "DTSTART:20250101T100000Z\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nUID:d\r\nDTSTAMP:20250101T000000Z\r\nRECURRENCE-ID:20250108T100000Z\r\n"
            . "DTSTART:20250108T100000Z\r\nEND:VEVENT\r\n")['recurring']);
    }
}
