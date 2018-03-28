<?php

namespace ESN\CalDAV;
require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

/**
 * @medium
 */
class ParticipationPluginTest extends \ESN\DAV\ServerMock {

    function setUp() {
        parent::setUp();

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals/users'];
        $this->server->addPlugin($aclPlugin);

        $participationPlugin = new \ESN\CalDAV\ParticipationPlugin();
        $this->server->addPlugin($participationPlugin);
    }

    function testProcessICalendarParticipation() {
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
  
        $modified = false;
        $path = "calendars/54b64eadf6d7d8e41d263e0f/participationCal/objecturi.ics";

        $node = $this->server->tree->getNodeForPath("calendars/54b64eadf6d7d8e41d263e0f/participationCal/objecturi.ics");
        $this->assertTrue($this->server->emit('beforeWriteContent', [$path, $node, &$data, &$modified]));
        
        $eventNode = \Sabre\VObject\Reader::read($data);

        list(, , $event1, $event2) = $eventNode->children();

        $this->assertEquals($event1->ATTENDEE['PARTSTAT']->getValue(), 'ACCEPTED');
        $this->assertEquals($event2->ATTENDEE['PARTSTAT']->getValue(), 'ACCEPTED');
    }
 
}