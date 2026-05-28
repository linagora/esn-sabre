<?php

namespace ESN\CalDAV;
require_once ESN_TEST_BASE . '/CalDAV/MockUtils.php';

use ESN\CalDAV\Validation\CalendarObjectValidator;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\UnsupportedMediaType;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

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

    function testCalendarObjectValidatorRunsWithStrictHandling() {
        $validator = new RecordingCalendarObjectValidator();
        $plugin = new PluginTestDouble($validator);
        $plugin->setHTTPPreferHandlingForTest('strict');

        $plugin->validateCalendarObjectBeforeSchedulingForTest($this->readCalendar());

        $this->assertEquals(1, $validator->calls);
    }

    function testCalendarObjectValidatorDoesNotRunWithoutStrictHandling() {
        $validator = new RecordingCalendarObjectValidator();
        $plugin = new PluginTestDouble($validator);
        $plugin->setHTTPPreferHandlingForTest(false);

        $plugin->validateCalendarObjectBeforeSchedulingForTest($this->readCalendar());

        $this->assertEquals(0, $validator->calls);
    }

    function testStrictICalendarValidationErrorsReturnBadRequest() {
        $plugin = new PluginTestDouble();
        $plugin->setHTTPPreferHandlingForTest('strict');

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage('Validation error in iCalendar: PRODID MUST appear exactly once in a VCALENDAR component');

        $plugin->validateICalendarForTest(implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ]) . "\r\n");
    }

    function testICalendarParseErrorsStillReturnUnsupportedMediaType() {
        $plugin = new PluginTestDouble();
        $plugin->setHTTPPreferHandlingForTest('strict');

        $this->expectException(UnsupportedMediaType::class);

        $plugin->validateICalendarForTest('invalid calendar data');
    }

    private function readCalendar() {
        return Reader::read(implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//test//EN',
            'BEGIN:VEVENT',
            'UID:event-1',
            'DTSTAMP:20260515T000000Z',
            'DTSTART:20260515T090000Z',
            'END:VEVENT',
            'END:VCALENDAR'
        ]) . "\r\n");
    }
}

class PluginTestDouble extends Plugin {
    function setHTTPPreferHandlingForTest($handling) {
        $this->server = new PluginServerTestDouble($handling);
    }

    function validateCalendarObjectBeforeSchedulingForTest(VCalendar $vCal) {
        $modified = false;

        $this->validateCalendarObjectBeforeScheduling(new Request('PUT', '/event.ics'), new Response(), $vCal, 'calendars/user/calendar', $modified, true);
    }

    function validateICalendarForTest($data) {
        $modified = false;

        $this->validateICalendar($data, 'calendars/user/calendar/event.ics', $modified, new Request('PUT', '/event.ics'), new Response(), true);
    }
}

class PluginServerTestDouble {
    private $handling;

    function __construct($handling) {
        $this->handling = $handling;
    }

    function getHTTPPrefer() {
        return ['handling' => $this->handling];
    }

    function getProperties() {
        return [];
    }

    function emit() {
    }
}

class RecordingCalendarObjectValidator extends CalendarObjectValidator {
    public $calls = 0;

    function validate(VCalendar $vCalendar) {
        $this->calls++;
    }
}
