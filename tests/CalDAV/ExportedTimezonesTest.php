<?php

namespace ESN\CalDAV;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VTimeZone;

class ExportedTimezonesTest extends \PHPUnit\Framework\TestCase {

    private function vtimezone($tzid, $offset = '+0100') {
        $vcal = new VCalendar();
        $vtimezone = new CountingVTimeZone($vcal, 'VTIMEZONE', [
            'TZID' => $tzid,
        ]);
        $vtimezone->add('STANDARD', [
            'DTSTART' => '19701025T030000',
            'TZOFFSETFROM' => '+0200',
            'TZOFFSETTO' => $offset,
        ]);
        $vcal->add($vtimezone);

        return $vtimezone;
    }

    function testTheSameTextIsExportedOnceWithoutBeingResolvedAgain() {
        $timezones = new ExportedTimezones();
        $first = $this->vtimezone('Europe/Paris');
        $second = $this->vtimezone('Europe/Paris');

        $this->assertSame('Europe/Paris', $timezones->add($first));
        $this->assertSame('Europe/Paris', $timezones->add($second));

        $this->assertCount(1, $timezones->components());
        $this->assertSame(1, $first->getTimeZoneCalls);
        $this->assertSame(0, $second->getTimeZoneCalls);
    }

    function testAnotherTextOfTheSameZoneIsExportedUnderItsTzid() {
        $timezones = new ExportedTimezones();
        $timezones->add($this->vtimezone('Europe/Paris'));
        $other = $this->vtimezone('Europe/Paris', '+0059');

        $this->assertSame('Europe/Paris', $timezones->add($other));
        $this->assertCount(1, $timezones->components());
        $this->assertSame(1, $other->getTimeZoneCalls);
    }

    function testEachNewDefinitionTakesTheFirstFreeSuffixSkippingARealTzid() {
        $timezones = new ExportedTimezones();

        $this->assertSame('Custom', $timezones->add($this->fixedZone('Custom', '+0100')));
        $this->assertSame('Custom-2', $timezones->add($this->fixedZone('Custom', '+0200')));
        // Another object uses Custom-3 and Custom-4 as its own TZIDs
        $this->assertSame('Custom-3', $timezones->add($this->fixedZone('Custom-3', '+0300')));
        $this->assertSame('Custom-4', $timezones->add($this->fixedZone('Custom-4', '+0400')));
        $this->assertSame('Custom-5', $timezones->add($this->fixedZone('Custom', '+0500')));
        $this->assertSame('Custom-6', $timezones->add($this->fixedZone('Custom', '+0600')));
        // A renamed definition's TZID is itself a TZID that may be defined again
        $this->assertSame('Custom-2-2', $timezones->add($this->fixedZone('Custom-2', '+0700')));
        $this->assertSame('Custom-7', $timezones->add($this->fixedZone('Custom', '+0800')));

        $this->assertSame(['Custom', 'Custom-2', 'Custom-3', 'Custom-4', 'Custom-5', 'Custom-6', 'Custom-2-2', 'Custom-7'],
            array_map(function ($vtimezone) { return (string) $vtimezone->TZID; }, $timezones->components()));
    }

    function testAnotherTextOfAZoneAlreadyExportedTakesTheTzidOfItsFirstDefinition() {
        $timezones = new ExportedTimezones();

        $this->assertSame('Custom', $timezones->add($this->fixedZone('Custom', '+0100')));
        $this->assertSame('Custom-2', $timezones->add($this->fixedZone('Custom', '+0200')));
        // Same zones written another way: the first definition of each is reused
        $this->assertSame('Custom-2', $timezones->add($this->vtimezoneWithComment('Custom', '+0200', 'a')));
        $this->assertSame('Custom', $timezones->add($this->vtimezoneWithComment('Custom', '+0100', 'b')));
        $this->assertCount(2, $timezones->components());
    }

    function testManyDefinitionsOfOneTzidAreEachExportedOnce() {
        $timezones = new ExportedTimezones();
        $exported = [];
        for ($minutes = 1; $minutes <= 300; $minutes++) {
            $offset = sprintf('+%02d%02d', intdiv($minutes, 60), $minutes % 60);
            $exported[] = $timezones->add($this->fixedZone('Custom', $offset));
        }

        $this->assertSame(array_merge(['Custom'], array_map(function ($n) { return 'Custom-' . $n; }, range(2, 300))),
            $exported);
        $this->assertCount(300, $timezones->components());
    }

    function testARawTextAlreadySeenGivesTheSameTzidWithoutSerializing() {
        $timezones = new ExportedTimezones();
        $first = $this->vtimezone('Europe/Paris');
        $second = $this->vtimezone('Europe/Paris');
        // As ICSExportPlugin::rawTimezones() reads it: up to END:VTIMEZONE, without the line break
        $raw = rtrim($first->serialize(), "\r\n");
        $first->serializeCalls = 0;

        $this->assertSame('Europe/Paris', $timezones->add($first, $raw));
        $this->assertSame('Europe/Paris', $timezones->add($second, $raw));

        $this->assertCount(1, $timezones->components());
        $this->assertSame(1, $first->serializeCalls);
        $this->assertSame(0, $second->serializeCalls);
        $this->assertSame(0, $second->getTimeZoneCalls);
    }

    function testARawHitGivesWhatTheSerializedTextGives() {
        $raws = [
            $this->rawZone('Custom', '+0100'), $this->rawZone('Custom', '+0200'),
            $this->rawZone('Custom', '+0100'), $this->rawZone('Custom-2', '+0300'),
            $this->rawZone('Custom', '+0200'), $this->rawZone('Custom-2', '+0300'),
        ];
        $withRaw = new ExportedTimezones();
        $withoutRaw = new ExportedTimezones();

        foreach ($raws as $raw) {
            $this->assertSame($withoutRaw->add($this->read($raw)), $withRaw->add($this->read($raw), $raw), $raw);
        }
        $this->assertSame(array_map([$this, 'serialized'], $withoutRaw->components()),
            array_map([$this, 'serialized'], $withRaw->components()));
    }

    function testTwoRawTextsSerializedAlikeAreExportedOnce() {
        $timezones = new ExportedTimezones();
        $crlf = $this->rawZone('Custom', '+0100');
        $lf = str_replace("\r\n", "\n", $crlf);
        $folded = str_replace("TZOFFSETTO:+0100", "TZOFFSE\r\n TTO:+0100", $crlf);
        $lowercase = str_replace("TZID:", "tzid:", $crlf);

        foreach ([$crlf, $lf, $folded, $lowercase] as $raw) {
            $this->assertSame('Custom', $timezones->add($this->read($raw), $raw), $raw);
        }
        $this->assertCount(1, $timezones->components());
    }

    function testAZoneMergedWithAnotherIsResolvedAgainInEachObject() {
        // Without observances, a VTIMEZONE resolves to the zone of the next
        // VTIMEZONE of its TZID in its object: the TZID it is exported under
        // depends on that object, and its raw text is not remembered.
        $bare = "BEGIN:VTIMEZONE\r\nTZID:Custom\r\nEND:VTIMEZONE";
        $timezones = new ExportedTimezones();
        $this->assertSame('Custom', $timezones->add($this->fixedZone('Custom', '+1000')));

        $withAnother = \Sabre\VObject\Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n" . $bare . "\r\n"
            . $this->rawZone('Custom', '+1000') . "\r\nEND:VCALENDAR\r\n");
        $this->assertSame('Custom', $timezones->add($withAnother->VTIMEZONE, $bare));

        $this->assertSame('Custom-2', $timezones->add($this->read($bare), $bare));
        $this->assertSame('Custom-2', $timezones->add($this->read($bare), $bare));
        $this->assertCount(2, $timezones->components());
    }

    private function rawZone($tzid, $offset) {
        return "BEGIN:VTIMEZONE\r\nTZID:$tzid\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:19700101T000000\r\nTZOFFSETFROM:$offset\r\nTZOFFSETTO:$offset\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE";
    }

    private function read($raw) {
        return \Sabre\VObject\Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n$raw\r\nEND:VCALENDAR\r\n")->VTIMEZONE;
    }

    private function serialized($component) {
        return $component->serialize();
    }

    /**
     * A zone of a fixed offset, which resolves to that offset whatever its TZID.
     */
    private function fixedZone($tzid, $offset) {
        return \Sabre\VObject\Reader::read("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VTIMEZONE\r\nTZID:$tzid\r\n"
            . "BEGIN:STANDARD\r\nDTSTART:19700101T000000\r\nTZOFFSETFROM:$offset\r\nTZOFFSETTO:$offset\r\n"
            . "END:STANDARD\r\nEND:VTIMEZONE\r\nEND:VCALENDAR\r\n")->VTIMEZONE;
    }

    private function vtimezoneWithComment($tzid, $offset, $comment) {
        $vtimezone = $this->fixedZone($tzid, $offset);
        $vtimezone->add('COMMENT', $comment);

        return $vtimezone;
    }
}

class CountingVTimeZone extends VTimeZone {
    public $getTimeZoneCalls = 0;
    public $serializeCalls = 0;

    public function serialize(): string {
        $this->serializeCalls++;
        return parent::serialize();
    }

    public function getTimeZone(): \DateTimeZone {
        $this->getTimeZoneCalls++;
        return parent::getTimeZone();
    }
}
