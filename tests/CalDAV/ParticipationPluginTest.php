<?php

namespace ESN\CalDAV;

use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

/**
 * @medium
 */
class ParticipationPluginTest extends \ESN\DAV\ServerMock {

    function setUp(): void {
        parent::setUp();

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals/users'];
        $this->server->addPlugin($aclPlugin);

        $participationPlugin = new \ESN\CalDAV\ParticipationPlugin();
        $this->server->addPlugin($participationPlugin);
    }

    function testProcessICalendarParticipationShouldOnlyUpdateFutureOverrides() {
        $oldCal = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Mozilla.org/NONSGML Mozilla Calendar V1.1//EN
BEGIN:VEVENT
UID:foobar
ORGANIZER;CN=Strunk:mailto:strunk@example.org
ATTENDEE;CN=White;PARTSTAT=NEEDS-ACTION:mailto:robertocarlos@realmadrid.com
ATTENDEE;CN=Two:mailto:two@example.org
DTSTART:20140716T120000Z
DURATION:PT1H
RRULE:FREQ=DAILY
EXDATE:20140717T120000Z
END:VEVENT
BEGIN:VEVENT
UID:foobar
RECURRENCE-ID:20140718T120000Z
ORGANIZER;CN=Strunk:mailto:strunk@example.org
ATTENDEE;CN=White;PARTSTAT=NEEDS-ACTION:mailto:robertocarlos@realmadrid.com
ATTENDEE;CN=Two:mailto:two@example.org
DTSTART:20140718T120000Z
DURATION:PT1H
END:VEVENT
BEGIN:VEVENT
UID:foobar
RECURRENCE-ID:30250718T120000Z
ORGANIZER;CN=Strunk:mailto:strunk@example.org
ATTENDEE;CN=White;PARTSTAT=NEEDS-ACTION:mailto:robertocarlos@realmadrid.com
ATTENDEE;CN=Two:mailto:two@example.org
DTSTART:30250718T120000Z
DURATION:PT1H
END:VEVENT
END:VCALENDAR
ICS;

        $data = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:foobar
ORGANIZER;CN=Strunk:mailto:strunk@example.org
ATTENDEE;CN=White;PARTSTAT=ACCEPTED:mailto:robertocarlos@realmadrid.com
ATTENDEE;CN=Two:mailto:two@example.org
DTSTART:20140716T120000Z
DURATION:PT1H
RRULE:FREQ=DAILY
EXDATE:20140717T120000Z
END:VEVENT
BEGIN:VEVENT
UID:foobar
RECURRENCE-ID:20140718T120000Z
ORGANIZER;CN=Strunk:mailto:strunk@example.org
ATTENDEE;CN=White;PARTSTAT=NEEDS-ACTION:mailto:robertocarlos@realmadrid.com
ATTENDEE;CN=Two:mailto:two@example.org
DTSTART:20140718T120000Z
DURATION:PT1H
END:VEVENT
BEGIN:VEVENT
UID:foobar
RECURRENCE-ID:30250718T120000Z
ORGANIZER;CN=Strunk:mailto:strunk@example.org
ATTENDEE;CN=White;PARTSTAT=NEEDS-ACTION:mailto:robertocarlos@realmadrid.com
ATTENDEE;CN=Two:mailto:two@example.org
DTSTART:30250718T120000Z
DURATION:PT1H
END:VEVENT
END:VCALENDAR
ICS;

        $calendarData = [
            'uri' => 'participationCal',
            'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f'
        ];

        $objectData = [
            'uri' => 'objecturi.ics',
            'calendardata' => $oldCal
        ];


        $calendarData['id'] = $this->caldavBackend->createCalendar($calendarData['principaluri'], $calendarData['uri'], $calendarData);
        $etag = $this->caldavBackend->createCalendarObject($calendarData['id'], $objectData['uri'], $oldCal);
  
        $path = "calendars/54b64eadf6d7d8e41d263e0f/participationCal/objecturi.ics";
        $eventNode = \Sabre\VObject\Reader::read($data);

        $this->assertTrue($this->emitCalendarObjectChange($path, $eventNode));

        [$masterEvent, $pastOverride, $futureOverride] = $eventNode->select('VEVENT');

        $this->assertEquals('ACCEPTED', $masterEvent->ATTENDEE['PARTSTAT']->getValue());
        $this->assertEquals('NEEDS-ACTION', $pastOverride->ATTENDEE['PARTSTAT']->getValue());
        $this->assertEquals('ACCEPTED', $futureOverride->ATTENDEE['PARTSTAT']->getValue());
    }

    function testProcessICalendarParticipationShouldIgnoreRecurringOverrideWithoutAttendees() {
        $oldCal = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:foobar
ORGANIZER;CN=Strunk:mailto:strunk@example.org
ATTENDEE;CN=White;PARTSTAT=NEEDS-ACTION:mailto:robertocarlos@realmadrid.com
ATTENDEE;CN=Two:mailto:two@example.org
DTSTART:20140716T120000Z
DURATION:PT1H
RRULE:FREQ=DAILY
END:VEVENT
BEGIN:VEVENT
UID:foobar
RECURRENCE-ID:20140718T120000Z
ORGANIZER;CN=Strunk:mailto:strunk@example.org
DTSTART:20140718T120000Z
DURATION:PT1H
END:VEVENT
END:VCALENDAR
ICS;

        $data = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:foobar
ORGANIZER;CN=Strunk:mailto:strunk@example.org
ATTENDEE;CN=White;PARTSTAT=ACCEPTED:mailto:robertocarlos@realmadrid.com
ATTENDEE;CN=Two:mailto:two@example.org
DTSTART:20140716T120000Z
DURATION:PT1H
RRULE:FREQ=DAILY
END:VEVENT
BEGIN:VEVENT
UID:foobar
RECURRENCE-ID:20140718T120000Z
ORGANIZER;CN=Strunk:mailto:strunk@example.org
DTSTART:20140718T120000Z
DURATION:PT1H
END:VEVENT
END:VCALENDAR
ICS;

        $calendarData = [
            'uri' => 'participationRecurringCal',
            'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f'
        ];
        $objectData = [
            'uri' => 'recurring-objecturi.ics',
            'calendardata' => $oldCal
        ];

        $calendarData['id'] = $this->caldavBackend->createCalendar($calendarData['principaluri'], $calendarData['uri'], $calendarData);
        $this->caldavBackend->createCalendarObject($calendarData['id'], $objectData['uri'], $oldCal);

        $path = "calendars/54b64eadf6d7d8e41d263e0f/participationRecurringCal/recurring-objecturi.ics";
        $eventNode = \Sabre\VObject\Reader::read($data);

        $this->assertTrue($this->emitCalendarObjectChange($path, $eventNode));

        [$masterEvent, $overrideEvent] = $this->extractMasterAndOverrideEvents($eventNode);

        $this->assertNotNull($masterEvent);
        $this->assertNotNull($overrideEvent);
        $this->assertEquals('ACCEPTED', $masterEvent->ATTENDEE['PARTSTAT']->getValue());
        $this->assertFalse(isset($overrideEvent->ATTENDEE));
    }

    /**
     * Drives the plugin the way Sabre does: through calendarObjectChange, with
     * the object it has already parsed and which it mutates in place.
     */
    private function emitCalendarObjectChange($path, \Sabre\VObject\Component\VCalendar $vCal): bool {
        $this->server->httpRequest = new Request('PUT', '/' . $path);

        $modified = false;

        return $this->server->emit('calendarObjectChange', [
            $this->server->httpRequest,
            new Response(),
            $vCal,
            dirname($path),
            &$modified,
            false
        ]);
    }

    private function extractMasterAndOverrideEvents(\Sabre\VObject\Component\VCalendar $calendar): array {
        $masterEvent = null;
        $overrideEvent = null;

        foreach ($calendar->select('VEVENT') as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'})) {
                $overrideEvent = $vevent;
            } else {
                $masterEvent = $vevent;
            }
        }

        return [$masterEvent, $overrideEvent];
    }
 
}
