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
}
