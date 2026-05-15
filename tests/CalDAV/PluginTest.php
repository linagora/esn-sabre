<?php

namespace ESN\CalDAV;

use ESN\CalDAV\Validation\CalendarObjectValidator;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Sabre\VObject\Reader;

require_once ESN_TEST_BASE . '/CalDAV/MockUtils.php';

#[\AllowDynamicProperties]
class PluginTest extends \PHPUnit\Framework\TestCase {

    function  testGetCalendarHomeForPrincipal() {
        $plugin = new Plugin();

        $this->assertNull($plugin->getCalendarHomeForPrincipal('/principals/123'));
        $this->assertNull($plugin->getCalendarHomeForPrincipal('/users/123'));
        $this->assertNull($plugin->getCalendarHomeForPrincipal('/notprincipal/notuser/123'));
        $this->assertNull($plugin->getCalendarHomeForPrincipal('/principals/users/1/123'));
        $this->assertEquals($plugin->getCalendarHomeForPrincipal('/principals/users/123'), $plugin::CALENDAR_ROOT . '/123');
    }

    function testValidateCalendarObjectValidatesNewJCalInputRegardlessContentType() {
        $validator = $this->createMock(CalendarObjectValidator::class);
        $validator->expects($this->once())->method('validate');
        $plugin = new PluginTestDouble($validator);
        $plugin->setCalendarObjectInputType(Plugin::INPUT_TYPE_JCAL);
        $modified = false;

        $plugin->validateCalendarObject(
            $this->request('text/calendar; charset=utf-8'),
            new Response(),
            $this->vCalendar(),
            'calendars/user/user/event.ics',
            $modified,
            true
        );
    }

    function testValidateCalendarObjectSkipsJCalUpdate() {
        $validator = $this->createMock(CalendarObjectValidator::class);
        $validator->expects($this->never())->method('validate');
        $plugin = new PluginTestDouble($validator);
        $plugin->setCalendarObjectInputType(Plugin::INPUT_TYPE_JCAL);
        $modified = false;

        $plugin->validateCalendarObject(
            $this->request('application/calendar+json'),
            new Response(),
            $this->vCalendar(),
            'calendars/user/user/event.ics',
            $modified,
            false
        );
    }

    function testValidateCalendarObjectSkipsIcsCreateByDefault() {
        $validator = $this->createMock(CalendarObjectValidator::class);
        $validator->expects($this->never())->method('validate');
        $plugin = new PluginTestDouble($validator);
        $plugin->setCalendarObjectInputType(Plugin::INPUT_TYPE_ICAL);
        $modified = false;

        $plugin->validateCalendarObject(
            $this->request('application/calendar+json'),
            new Response(),
            $this->vCalendar(),
            'calendars/user/user/event.ics',
            $modified,
            true
        );
    }

    function testValidateCalendarObjectUsesConfiguredInputTypes() {
        $validator = $this->createMock(CalendarObjectValidator::class);
        $validator->expects($this->once())->method('validate');
        $plugin = new PluginTestDouble($validator, [
            Plugin::INPUT_TYPE_JCAL,
            Plugin::INPUT_TYPE_ICAL
        ]);
        $plugin->setCalendarObjectInputType(Plugin::INPUT_TYPE_ICAL);
        $modified = false;

        $plugin->validateCalendarObject(
            $this->request('text/calendar; charset=utf-8'),
            new Response(),
            $this->vCalendar(),
            'calendars/user/user/event.ics',
            $modified,
            true
        );
    }

    function testDetectCalendarObjectInputTypeDetectsJCal() {
        $plugin = new PluginTestDouble();

        $this->assertEquals(Plugin::INPUT_TYPE_JCAL, $plugin->detectCalendarObjectInputTypeForTest(' ["vcalendar", [], []]'));
    }

    function testDetectCalendarObjectInputTypeDetectsICal() {
        $plugin = new PluginTestDouble();

        $this->assertEquals(Plugin::INPUT_TYPE_ICAL, $plugin->detectCalendarObjectInputTypeForTest('BEGIN:VCALENDAR'));
    }

    private function request($contentType) {
        return new Request('PUT', '/calendars/user/user/event.ics', [
            'Content-Type' => $contentType
        ]);
    }

    private function vCalendar() {
        return Reader::read(implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'DTEND:20260515T100000Z',
            'SUMMARY:Event',
            'END:VEVENT',
            'END:VCALENDAR'
        ]) . "\r\n");
    }
}

class PluginTestDouble extends Plugin {
    function setCalendarObjectInputType($inputType) {
        $this->calendarObjectInputType = $inputType;
    }

    function detectCalendarObjectInputTypeForTest($data) {
        return $this->detectCalendarObjectInputType($data);
    }
}
