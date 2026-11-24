<?php

namespace ESN\CalDAV;

/**
 * ESN\CalDAV\ICSExportPlugin::mergeObjects, the merge of the calendar objects of an export.
 */
class ICSExportPluginTest extends \PHPUnit\Framework\TestCase {

    private function mergeWithWarningsAsErrors(array $inputObjects) {
        set_error_handler(function ($no, $str, $file, $line) {
            throw new \ErrorException($str, 0, $no, $file, $line);
        });
        try {
            return (new ICSExportPlugin())->mergeObjects([], $inputObjects);
        } finally {
            restore_error_handler();
        }
    }

    private function calendarWithProperty($propertyName) {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//EN\r\n$propertyName:x\r\nBEGIN:VEVENT\r\nUID:a\r\n"
            . "DTSTAMP:20260101T000000Z\r\nDTSTART:20260105T100000Z\r\nDURATION:PT1H\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
    }

    /**
     * linagora/sabre-vobject#172
     */
    function testMergeIgnoresPropertyNamedLikeAComponent() {
        $merged = $this->mergeWithWarningsAsErrors(['a.ics' => $this->calendarWithProperty('VTIMEZONE')]);

        $this->assertCount(1, $merged->select('VEVENT'));
        $this->assertSame(['VEVENT'], array_map(function ($c) {
            return $c->name;
        }, $merged->getComponents()));
    }

    function testMergeIgnoresPropertyNamedLikeAnObject() {
        foreach (['VEVENT', 'VTODO', 'VJOURNAL'] as $propertyName) {
            $merged = $this->mergeWithWarningsAsErrors(['a.ics' => $this->calendarWithProperty($propertyName)]);

            $this->assertSame(['VEVENT'], array_map(function ($c) {
                return $c->name;
            }, $merged->getComponents()), $propertyName);
            $this->assertStringNotContainsString("\r\n$propertyName:x\r\n", $merged->serialize(), $propertyName);
        }
    }

    function testMergeKeepsComponentsOfEveryObject() {
        $tz = "BEGIN:VTIMEZONE\r\nTZID:Europe/Paris\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\n"
            . "TZOFFSETFROM:+0100\r\nTZOFFSETTO:+0100\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n";
        $a = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//EN\r\n" . $tz . "BEGIN:VEVENT\r\nUID:a\r\n"
            . "DTSTAMP:20260101T000000Z\r\nDTSTART;TZID=Europe/Paris:20260105T100000\r\nDURATION:PT1H\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $b = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//EN\r\n" . $tz . "BEGIN:VTODO\r\nUID:b\r\n"
            . "DTSTAMP:20260101T000000Z\r\nEND:VTODO\r\nBEGIN:VJOURNAL\r\nUID:c\r\nDTSTAMP:20260101T000000Z\r\nEND:VJOURNAL\r\n"
            . "END:VCALENDAR\r\n";

        $merged = $this->mergeWithWarningsAsErrors(['a.ics' => $a, 'b.ics' => $b]);

        $this->assertSame(['VTIMEZONE', 'VEVENT', 'VTODO', 'VJOURNAL'], array_map(function ($c) {
            return $c->name;
        }, $merged->getComponents()));
    }

    private function customZone($offset, $tzid = 'Custom') {
        return "BEGIN:VTIMEZONE\r\nTZID:$tzid\r\nBEGIN:STANDARD\r\nDTSTART:19700101T000000\r\n"
            . "TZOFFSETFROM:$offset\r\nTZOFFSETTO:$offset\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n";
    }

    private function eventIn($uid, $day, $tzid = 'Custom', array $extraLines = []) {
        return "BEGIN:VEVENT\r\nUID:$uid\r\nDTSTAMP:20260101T000000Z\r\n"
            . "DTSTART;TZID=$tzid:202601{$day}T100000\r\nDURATION:PT1H\r\n"
            . implode('', array_map(function ($line) {
                return "$line\r\n";
            }, $extraLines))
            . "END:VEVENT\r\n";
    }

    private function calendarOf(...$components) {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//x//EN\r\n" . implode('', $components) . "END:VCALENDAR\r\n";
    }

    private function utcStarts($merged) {
        $merged = \Sabre\VObject\Reader::read($merged->serialize());
        $starts = [];
        foreach ($merged->select('VEVENT') as $vevent) {
            $starts[(string) $vevent->UID] = $vevent->DTSTART->getDateTime()
                ->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis');
        }

        return $starts;
    }

    private function tzids($merged) {
        return array_map(function ($vtimezone) {
            return (string) $vtimezone->TZID;
        }, $merged->select('VTIMEZONE'));
    }

    /**
     * linagora/sabre-vobject#173
     */
    function testMergeKeepsDistinctVTimezonesOfOneTzid() {
        $a = $this->calendarOf($this->customZone('+1000'), $this->eventIn('a', '05'));
        $b = $this->calendarOf($this->customZone('-0300'), $this->eventIn('b', '06'));

        $merged = (new ICSExportPlugin())->mergeObjects([], ['a.ics' => $a, 'b.ics' => $b]);

        // each event keeps the instant it was stored with
        $this->assertSame(['a' => '20260105T000000', 'b' => '20260106T130000'], $this->utcStarts($merged));
        $this->assertSame(['Custom', 'Custom-2'], $this->tzids($merged));
    }

    function testExpandOfMergeKeepsDistinctVTimezonesOfOneTzid() {
        $a = $this->calendarOf($this->customZone('+1000'), $this->eventIn('a', '05'));
        $b = $this->calendarOf($this->customZone('-0300'), $this->eventIn('b', '06'));

        $merged = (new ICSExportPlugin())->mergeObjects([], ['a.ics' => $a, 'b.ics' => $b]);
        $expanded = $merged->expand(new \DateTime('2026-01-01T00:00:00Z'), new \DateTime('2026-02-11T00:00:00Z'));

        $starts = [];
        foreach ($expanded->select('VEVENT') as $vevent) {
            $starts[(string) $vevent->UID] = (string) $vevent->DTSTART;
        }
        $this->assertSame(['a' => '20260105T000000Z', 'b' => '20260106T130000Z'], $starts);
    }

    function testMergeRewritesEveryTzidParameterOfTheRenamedObject() {
        $a = $this->calendarOf($this->customZone('+1000'), $this->eventIn('a', '05'));
        $b = $this->calendarOf(
            $this->eventIn('b', '06', 'Custom', [
                'RRULE:FREQ=DAILY;COUNT=5',
                'EXDATE;TZID=Custom:20260107T100000',
                'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:x', 'TRIGGER:-PT5M',
                'X-SNOOZED;TZID=Custom:20260106T095500', 'END:VALARM',
            ]),
            $this->eventIn('b', '08', 'Custom', ['RECURRENCE-ID;TZID=Custom:20260108T100000']),
            $this->customZone('-0300')
        );

        $merged = (new ICSExportPlugin())->mergeObjects([], ['a.ics' => $a, 'b.ics' => $b]);

        $serialized = $merged->serialize();
        $this->assertStringNotContainsString('TZID=Custom:', $this->withoutEvent($serialized, 'a'));
        $this->assertStringContainsString("EXDATE;TZID=Custom-2:20260107T100000\r\n", $serialized);
        $this->assertStringContainsString("X-SNOOZED;TZID=Custom-2:20260106T095500\r\n", $serialized);
        $this->assertStringContainsString("RECURRENCE-ID;TZID=Custom-2:20260108T100000\r\n", $serialized);
        $this->assertStringContainsString("DTSTART;TZID=Custom:20260105T100000\r\n", $serialized);
    }

    private function withoutEvent($serialized, $uid) {
        return preg_replace("/BEGIN:VEVENT\r\nUID:$uid\r\n.*?END:VEVENT\r\n/s", '', $serialized);
    }

    function testMergeReusesTheTzidOfAnIdenticalDefinition() {
        $a = $this->calendarOf($this->customZone('+1000'), $this->eventIn('a', '05'));
        $b = $this->calendarOf($this->customZone('-0300'), $this->eventIn('b', '06'));
        $c = $this->calendarOf($this->customZone('-0300'), $this->eventIn('c', '07'));
        $d = $this->calendarOf($this->customZone('+1000'), $this->eventIn('d', '08'));

        $merged = (new ICSExportPlugin())->mergeObjects([], ['a.ics' => $a, 'b.ics' => $b, 'c.ics' => $c, 'd.ics' => $d]);

        $this->assertSame(['Custom', 'Custom-2'], $this->tzids($merged));
        $this->assertSame([
            'a' => '20260105T000000', 'b' => '20260106T130000', 'c' => '20260107T130000', 'd' => '20260108T000000',
        ], $this->utcStarts($merged));
    }

    function testMergeDoesNotReuseAnExistingTzid() {
        $a = $this->calendarOf($this->customZone('+1000'), $this->customZone('+0500', 'Custom-2'),
            $this->eventIn('a', '05'), $this->eventIn('a2', '05', 'Custom-2'));
        $b = $this->calendarOf($this->customZone('-0300'), $this->eventIn('b', '06'));

        $merged = (new ICSExportPlugin())->mergeObjects([], ['a.ics' => $a, 'b.ics' => $b]);

        $this->assertSame(['Custom', 'Custom-2', 'Custom-3'], $this->tzids($merged));
        $this->assertSame(['a' => '20260105T000000', 'a2' => '20260105T050000', 'b' => '20260106T130000'],
            $this->utcStarts($merged));
    }

    function testMergeKeepsOneDefinitionOfAWellKnownZoneWrittenDifferently() {
        $paris = function ($extra) {
            return "BEGIN:VTIMEZONE\r\nTZID:Europe/Paris\r\n$extra"
                . "BEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nRRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\n"
                . "TZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD\r\n"
                . "BEGIN:DAYLIGHT\r\nDTSTART:19700329T020000\r\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\n"
                . "TZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\nEND:DAYLIGHT\r\nEND:VTIMEZONE\r\n";
        };
        $a = $this->calendarOf($paris(''), $this->eventIn('a', '05', 'Europe/Paris'));
        $b = $this->calendarOf($paris("X-LIC-LOCATION:Europe/Paris\r\n"), $this->eventIn('b', '06', 'Europe/Paris'));

        $merged = (new ICSExportPlugin())->mergeObjects([], ['a.ics' => $a, 'b.ics' => $b]);

        $this->assertSame(['Europe/Paris'], $this->tzids($merged));
        $this->assertSame(['a' => '20260105T090000', 'b' => '20260106T090000'], $this->utcStarts($merged));
    }

    /**
     * The merge when each object is read from a stream: its VTIMEZONEs are
     * then always serialized to be recognised.
     */
    private function mergeFromStreams(array $inputObjects) {
        $streams = array_map(function ($input) {
            $stream = fopen('php://memory', 'r+');
            fwrite($stream, $input);
            rewind($stream);

            return $stream;
        }, $inputObjects);
        try {
            return (new ICSExportPlugin())->mergeObjects([], $streams);
        } finally {
            array_map('fclose', $streams);
        }
    }

    private function assertMergedAsWhenSerialized(array $inputObjects) {
        $merged = (new ICSExportPlugin())->mergeObjects([], $inputObjects);

        $this->assertSame($this->mergeFromStreams($inputObjects)->serialize(), $merged->serialize());

        return $merged;
    }

    /**
     * linagora/esn-sabre#886
     */
    function testMergeOfRawTimezonesIsTheMergeOfTheirSerialization() {
        $lf = function ($text) {
            return str_replace("\r\n", "\n", $text);
        };
        $objects = [
            'a.ics' => $this->calendarOf($this->customZone('+1000'), $this->eventIn('a', '05')),
            'b.ics' => $this->calendarOf($this->customZone('-0300'), $this->eventIn('b', '06')),
            'c.ics' => $this->calendarOf($this->customZone('+1000'), $this->eventIn('c', '07')),
            'd.ics' => $lf($this->calendarOf($this->customZone('-0300'), $this->eventIn('d', '08'))),
            'e.ics' => $this->calendarOf($this->customZone('+0500', 'Custom-2'), $this->customZone('-0300'),
                $this->eventIn('e', '09', 'Custom-2'), $this->eventIn('e2', '09')),
            'f.ics' => $this->calendarOf($this->customZone('-0300'), $this->customZone('+0700'), $this->eventIn('f', '10')),
            'g.ics' => $this->calendarOf($this->customZone('-0300'), $this->eventIn('g', '11')),
        ];

        $merged = $this->assertMergedAsWhenSerialized($objects);

        $this->assertSame(['Custom', 'Custom-2', 'Custom-2-2'], $this->tzids($merged));
        $this->assertSame([
            'a' => '20260105T000000', 'b' => '20260106T130000', 'c' => '20260107T000000', 'd' => '20260108T130000',
            'e' => '20260109T050000', 'e2' => '20260109T130000', 'f' => '20260110T130000', 'g' => '20260111T130000',
        ], $this->utcStarts($merged));
    }

    function testMergeOfTwoRawTimezonesSerializedAlikeKeepsOneDefinition() {
        $zone = $this->customZone('+1000');
        $a = $this->calendarOf($zone, $this->eventIn('a', '05'));
        $b = $this->calendarOf(str_replace("TZOFFSETTO:+1000", "TZOFFSE\r\n TTO:+1000", $zone), $this->eventIn('b', '06'));
        $c = $this->calendarOf(str_replace("TZID:Custom", "tzid:Custom", $zone), $this->eventIn('c', '07'));

        $merged = $this->assertMergedAsWhenSerialized(['a.ics' => $a, 'b.ics' => $b, 'c.ics' => $c]);

        $this->assertSame(['Custom'], $this->tzids($merged));
    }

    private function eventWithNestedZone($uid, $day, $zone) {
        return str_replace("END:VEVENT\r\n", $zone . "END:VEVENT\r\n", $this->eventIn($uid, $day));
    }

    /**
     * A VTIMEZONE nested in an event is not one of the object: its text must
     * not be taken for the text of the VTIMEZONE of the object.
     */
    function testMergeDoesNotPairATimezoneWithTheTextOfANestedOne() {
        $a = $this->calendarOf($this->eventWithNestedZone('a', '05', $this->customZone('-0300')), $this->customZone('+1000'));
        $b = $this->calendarOf($this->customZone('-0300'), $this->eventIn('b', '06'));

        $merged = $this->assertMergedAsWhenSerialized(['a.ics' => $a, 'b.ics' => $b]);

        $this->assertSame(['a' => '20260105T000000', 'b' => '20260106T130000'], $this->utcStarts($merged));
    }

    function testMergeDoesNotTakeTheTextUpToANestedEndForATimezone() {
        $nesting = function ($offset) {
            return str_replace("BEGIN:STANDARD\r\n",
                "BEGIN:VTIMEZONE\r\nTZID:Inner\r\nEND:VTIMEZONE\r\nBEGIN:STANDARD\r\n", $this->customZone($offset));
        };
        $a = $this->calendarOf($nesting('+1000'), $this->eventIn('a', '05'));
        $b = $this->calendarOf($nesting('-0300'), $this->eventIn('b', '06'));

        $merged = $this->assertMergedAsWhenSerialized(['a.ics' => $a, 'b.ics' => $b]);

        $this->assertSame(['a' => '20260105T000000', 'b' => '20260106T130000'], $this->utcStarts($merged));
    }

    function testMergeDoesNotPairATimezoneWhoseBeginLineIsFolded() {
        // Counting the BEGIN:VTIMEZONE lines would find one, the nested one
        $folded = str_replace("BEGIN:VTIMEZONE\r\n", "BEGIN:VTIME\r\n ZONE\r\n", $this->customZone('+1000'));
        $a = $this->calendarOf($this->eventWithNestedZone('a', '05', $this->customZone('-0300')), $folded);
        $b = $this->calendarOf($this->customZone('-0300'), $this->eventIn('b', '06'));

        $merged = $this->assertMergedAsWhenSerialized(['a.ics' => $a, 'b.ics' => $b]);

        $this->assertSame(['a' => '20260105T000000', 'b' => '20260106T130000'], $this->utcStarts($merged));
    }

    function testMergeDoesNotPairATimezoneWithTextAfterTheEndOfItsObject() {
        $folded = str_replace("BEGIN:VTIMEZONE\r\n", "BEGIN:VTIME\r\n ZONE\r\n", $this->customZone('+1000'));
        // The parser stops at END:VCALENDAR
        $a = $this->calendarOf($folded, $this->eventIn('a', '05')) . $this->customZone('-0300');
        $b = $this->calendarOf($this->customZone('-0300'), $this->eventIn('b', '06'));

        $merged = $this->assertMergedAsWhenSerialized(['a.ics' => $a, 'b.ics' => $b]);

        $this->assertSame(['Custom', 'Custom-2'], $this->tzids($merged));
        $this->assertSame(['a' => '20260105T000000', 'b' => '20260106T130000'], $this->utcStarts($merged));
    }

    function testMergeOfAZoneResolvedWithAnotherDefinitionOfItsObject() {
        // Without observances, a VTIMEZONE resolves to the zone of the next
        // VTIMEZONE of its TZID in its object, if any.
        $bare = "BEGIN:VTIMEZONE\r\nTZID:Custom\r\nEND:VTIMEZONE\r\n";
        $a = $this->calendarOf($this->customZone('+1000'), $this->eventIn('a', '05'));
        $b = $this->calendarOf($bare, $this->customZone('+1000'), $this->eventIn('b', '06'));
        $c = $this->calendarOf($bare, $this->eventIn('c', '07'));
        $d = $this->calendarOf($bare, $this->eventIn('d', '08'));

        $merged = $this->assertMergedAsWhenSerialized(['a.ics' => $a, 'b.ics' => $b, 'c.ics' => $c, 'd.ics' => $d]);

        $this->assertSame(['Custom', 'Custom-2'], $this->tzids($merged));
    }
}
