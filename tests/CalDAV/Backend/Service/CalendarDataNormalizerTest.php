<?php

namespace ESN\CalDAV\Backend\Service;

/**
 * The stored first/last occurrence window is what the backend uses to discard
 * objects before post-filtering time-range queries: it must always cover every
 * instance the event generates.
 */
class CalendarDataNormalizerTest extends \PHPUnit\Framework\TestCase {

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
