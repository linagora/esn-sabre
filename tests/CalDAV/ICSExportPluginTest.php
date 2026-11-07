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
}
