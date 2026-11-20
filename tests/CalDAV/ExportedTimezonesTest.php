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
}

class CountingVTimeZone extends VTimeZone {
    public $getTimeZoneCalls = 0;

    public function getTimeZone(): \DateTimeZone {
        $this->getTimeZoneCalls++;
        return parent::getTimeZone();
    }
}
