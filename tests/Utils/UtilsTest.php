<?php

namespace ESN\Utils;

use ESN\Utils\Utils;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class UtilsTest extends \ESN\DAV\ServerMock {

    protected static $responseDetails = [
        'fileProperties' => [
            [
                200 => [
                    '{urn:ietf:params:xml:ns:caldav}calendar-data' =>
                        'BEGIN:VCALENDAR
                VERSION:2.0
                PRODID:-//Sabre//Sabre VObject 4.1.3//EN
                BEGIN:VTIMEZONE
                TZID:Asia/Jakarta
                BEGIN:STANDARD
                TZOFFSETFROM:+0700
                TZOFFSETTO:+0700
                TZNAME:WIB
                DTSTART:19700101T000000
                END:STANDARD
                END:VTIMEZONE
                BEGIN:VEVENT
                UID:237512e8-7410-4d52-8abf-d4827d171c30
                TRANSP:OPAQUE
                DTSTART;TZID=Asia/Jakarta:20190917T133000
                DTEND;TZID=Asia/Jakarta:20190917T143000
                CLASS:PUBLIC
                SUMMARY:Daily event 01
                RRULE:FREQ=DAILY;COUNT=3
                ORGANIZER;CN=John0 Doe0:mailto:user0@open-paas.org
                DTSTAMP:20190917T062518Z
                SEQUENCE:2
                ATTENDEE;PARTSTAT=ACCEPTED;RSVP=FALSE;ROLE=CHAIR;CUTYPE=INDIVIDUAL;CN=John0
                  Doe0:mailto:user0@open-paas.org
                END:VEVENT
                END:VCALENDAR',
                    '{DAV:}getetag' => 'abd4ece606f93d7e7399b07d36fb0f22'
                ],
                404 => [],
                'href' => 'calendars/5d11c3e0ce1f61631c82b595/5d11c3e0ce1f61631c82b595/237512e8-7410-4d52-8abf-d4827d171c30.ics'
            ],
            [
                200 => [],
                404 => ['notfoundevent.ics'],
                'href' => 'calendars/5d11c3e0ce1f61631c82b595/5d11c3e0ce1f61631c82b595/notfoundevent.ics'
            ]
        ],
        'dataKey' => '{urn:ietf:params:xml:ns:caldav}calendar-data',
        'baseUri' => 'baseUri/'
    ];

    private function assertJSONMultiStatusResult($fileProperty, $result, $is404Item) {
        $this->assertEquals(self::$responseDetails['baseUri'] . $fileProperty['href'], $result['_links']['self']['href']);
        $this->assertEquals($is404Item ? 404 : 200, $result['status']);

        if ($is404Item) return;

        $this->assertEquals($fileProperty['200']['{DAV:}getetag'], $result['etag']);
        $this->assertEquals($fileProperty['200'][self::$responseDetails['dataKey']], $result['data']);
    }

    function testGenerateJSONMultiStatusOnly200() {
        $responseDetails = array_replace([], self::$responseDetails);
        $responseDetails['fileProperties'] = [self::$responseDetails['fileProperties'][0]];
        $results = Utils::generateJSONMultiStatus($responseDetails);

        $this->assertCount(1, $results);
        $this->assertJSONMultiStatusResult(self::$responseDetails['fileProperties'][0], $results[0], false);
    }

    function testGenerateJSONMultiStatus200and404() {
        $results = Utils::generateJSONMultiStatus(self::$responseDetails);

        $this->assertCount(2, $results);

        $this->assertJSONMultiStatusResult(self::$responseDetails['fileProperties'][0], $results[0], false);
        $this->assertJSONMultiStatusResult(self::$responseDetails['fileProperties'][1], $results[1], true);
    }

    function testGenerateJSONMultiStatusStrips404() {
        $responseDetails = array_replace([], self::$responseDetails);
        $responseDetails['strip404s'] = true;
        $results = Utils::generateJSONMultiStatus($responseDetails);

        $this->assertCount(1, $results);
        $this->assertJSONMultiStatusResult(self::$responseDetails['fileProperties'][0], $results[0], false);
    }

    function testSplitEventPathShouldReturnCalendarPathAndEventUriWhenEventPathIsValid() {
        list($calendarPath, $eventUri) = Utils::splitEventPath('/calendars/calendarHomeId-1/calendarId-1/eventUid-1.ics');

        $this->assertEquals('calendars/calendarHomeId-1/calendarId-1', $calendarPath);
        $this->assertEquals('eventUid-1.ics', $eventUri);
    }

    function testSplitEventPathShouldReturnAPairOfNullsWhenEventPathIsInvalid() {
        $invalidEventPaths = [
            'calendars/calendarHomeId/calendarId/eventUid.ics',
            '/calendars/calendarHomeId/calendarId/eventUid',
            'calendar/calendarHomeId/calendarId/eventUid.ics',
            '/calendarHomeId/calendarId/eventUid.ics',
            '/calendars/calendarId/eventUid.ics',
        ];

        foreach ($invalidEventPaths as $invalidEventPath) {
            list($calendarPath, $eventUri) = Utils::splitEventPath($invalidEventPath);
            $this->assertEquals(null, $calendarPath);
            $this->assertEquals(null, $eventUri);
        }
    }

    function testGetCalendarHomePathFromEventPath() {
        $eventPath = 'calendars/599aefa0a310ed32d28d52e6/events/sabredav-63884fc4-e0ea-456f-97f6-36e0e274f703.ics';
        $result = Utils::getCalendarHomePathFromEventPath($eventPath);

        $this->assertEquals($result, 'calendars/599aefa0a310ed32d28d52e6');
    }

    function testGetEventUriFromPath() {
        $eventPath = 'calendars/599aefa0a310ed32d28d52e6/events/sabredav-63884fc4-e0ea-456f-97f6-36e0e274f703.ics';
        $result = Utils::getEventUriFromPath($eventPath);

        $this->assertEquals($result, 'sabredav-63884fc4-e0ea-456f-97f6-36e0e274f703.ics');
    }

    function testIsPrincipalNotAttendingEvent() {
        $event = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:foobar
RECURRENCE-ID:20140718T120000Z
ORGANIZER;CN=Strunk:mailto:strunk@example.org
ATTENDEE;CN=White;PARTSTAT=NEEDS-ACTION:mailto:user1@example.com
ATTENDEE;CN=White;PARTSTAT=ACCEPTED:mailto:user2@example.com
ATTENDEE;CN=White;PARTSTAT=DECLINED:mailto:user3@example.com
DTSTART:20140718T120000Z
DURATION:PT1H
END:VEVENT
END:VCALENDAR
ICS;

        $eventNode = \Sabre\VObject\Reader::read($event);
        $vevent = $eventNode->VEVENT;

        $result = Utils::isPrincipalNotAttendingEvent($vevent, 'mailto:user1@example.com');
        $this->assertTrue($result);

        $result = Utils::isPrincipalNotAttendingEvent($vevent, 'mailto:user2@example.com');
        $this->assertFalse($result);

        $result = Utils::isPrincipalNotAttendingEvent($vevent, 'mailto:user3@example.com');
        $this->assertTrue($result);
    }

    function testGetPrincipalEmail() {
        $principal = 'principals/users/54b64eadf6d7d8e41d263e0f';

        $result = Utils::getPrincipalEmail($principal, $this->server);

        $this->assertEquals($result, 'mailto:robertocarlos@realmadrid.com');
    }

    /**
     * Test for issue #242 - Handle null URI in getPrincipalByUri
     *
     * When deleting a calendar event, if the iTipMessage recipient is null,
     * getPrincipalByUri should handle it gracefully instead of causing a
     * TypeError in substr().
     */
    function testGetPrincipalByUriWithNullUri() {
        // This should not throw a TypeError
        $result = Utils::getPrincipalByUri(null, $this->server);

        // Should return null when URI is null
        $this->assertNull($result);
    }

    /**
     * Test for issue #242 - Handle empty string URI in getPrincipalByUri
     */
    function testGetPrincipalByUriWithEmptyUri() {
        // This should not throw a TypeError
        $result = Utils::getPrincipalByUri('', $this->server);

        // Should return null when URI is empty
        $this->assertNull($result);
    }

    /**
     * Test for issue #43 - Non-standard timezone IDs from Microsoft Exchange
     *
     * This test verifies that calendar events with non-standard timezone identifiers
     * like "(UTC+05:30) Chennai, Kolkata, Mumbai, New Delhi" can be parsed correctly.
     *
     * According to chibenwa's comment, sabre-vobject 4.1.2+ should handle this
     * automatically thanks to commit c8ad40c1d8571aff9b7b62d33d91a29e779b2c2f
     */
    function testMicrosoftExchangeTimezoneHandling() {
        $icsPath = ESN_TEST_BASE . '/fixtures/bug2.ics';
        $this->assertTrue(file_exists($icsPath), 'Test fixture bug2.ics not found');

        $icsContent = file_get_contents($icsPath);

        // Parse the ICS file
        $vcalendar = \Sabre\VObject\Reader::read($icsContent);
        $this->assertNotNull($vcalendar, 'Failed to parse bug2.ics');

        $vevent = $vcalendar->VEVENT;
        $this->assertNotNull($vevent, 'No VEVENT found in calendar');

        // Verify event properties
        $this->assertEquals('James Support - Linagora', (string)$vevent->SUMMARY);

        // The key test: can we convert DTSTART/DTEND to DateTime objects?
        // If timezone handling is broken, this will throw an exception
        try {
            $dtstart = $vevent->DTSTART->getDateTime();
            $dtend = $vevent->DTEND->getDateTime();

            // Verify the datetime values
            $this->assertEquals('2025-09-10', $dtstart->format('Y-m-d'));
            $this->assertEquals('14:00:00', $dtstart->format('H:i:s'));
            $this->assertEquals('15:00:00', $dtend->format('H:i:s'));

            // Verify timezone was properly handled
            $tz = $dtstart->getTimezone();
            $tzName = $tz->getName();

            // The timezone should be normalized to a valid IANA timezone
            // It should NOT be the raw Microsoft format
            $this->assertStringNotContainsString('Chennai', $tzName, 'Timezone was not normalized from Microsoft format');
            $this->assertStringNotContainsString('UTC+05:30', $tzName, 'Timezone was not normalized from Microsoft format');

        } catch (\Exception $e) {
            $this->fail('Failed to convert DTSTART/DTEND to DateTime: ' . $e->getMessage());
        }
    }

    /**
     * Test that PRIVATE events are identified as hidden for non-owners
     */
    function testIsHiddenPrivateEventForNonOwner() {
        $icalData = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:private-event-test',
            'DTSTART:20260110T100000Z',
            'DTEND:20260110T110000Z',
            'SUMMARY:Secret Meeting',
            'CLASS:PRIVATE',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $vcalendar = \Sabre\VObject\Reader::read($icalData);
        $vevent = $vcalendar->VEVENT;

        $node = new class('principals/users/owner') {
            private $owner;
            public function __construct($owner) { $this->owner = $owner; }
            public function getOwner() { return $this->owner; }
        };

        $userPrincipal = 'principals/users/other';

        $this->assertTrue(Utils::isHiddenPrivateEvent($vevent, $node, $userPrincipal));
    }

    /**
     * Test that PRIVATE events are not hidden for owners
     */
    function testIsHiddenPrivateEventForOwner() {
        $icalData = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:private-event-test',
            'DTSTART:20260110T100000Z',
            'DTEND:20260110T110000Z',
            'SUMMARY:Secret Meeting',
            'CLASS:PRIVATE',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $vcalendar = \Sabre\VObject\Reader::read($icalData);
        $vevent = $vcalendar->VEVENT;

        $node = new class('principals/users/owner') {
            private $owner;
            public function __construct($owner) { $this->owner = $owner; }
            public function getOwner() { return $this->owner; }
        };

        $userPrincipal = 'principals/users/owner';

        $this->assertFalse(Utils::isHiddenPrivateEvent($vevent, $node, $userPrincipal));
    }

    /**
     * Test that CONFIDENTIAL events are identified as hidden for non-owners
     */
    function testIsHiddenConfidentialEventForNonOwner() {
        $icalData = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:confidential-event-test',
            'DTSTART:20260110T100000Z',
            'DTEND:20260110T110000Z',
            'SUMMARY:Confidential Meeting',
            'CLASS:CONFIDENTIAL',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $vcalendar = \Sabre\VObject\Reader::read($icalData);
        $vevent = $vcalendar->VEVENT;

        $node = new class('principals/users/owner') {
            private $owner;
            public function __construct($owner) { $this->owner = $owner; }
            public function getOwner() { return $this->owner; }
        };

        $userPrincipal = 'principals/users/other';

        $this->assertTrue(Utils::isHiddenPrivateEvent($vevent, $node, $userPrincipal));
    }

    /**
     * Test that CONFIDENTIAL events are not hidden for owners
     */
    function testIsHiddenConfidentialEventForOwner() {
        $icalData = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:confidential-event-test',
            'DTSTART:20260110T100000Z',
            'DTEND:20260110T110000Z',
            'SUMMARY:Confidential Meeting',
            'CLASS:CONFIDENTIAL',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $vcalendar = \Sabre\VObject\Reader::read($icalData);
        $vevent = $vcalendar->VEVENT;

        $node = new class('principals/users/owner') {
            private $owner;
            public function __construct($owner) { $this->owner = $owner; }
            public function getOwner() { return $this->owner; }
        };

        $userPrincipal = 'principals/users/owner';

        $this->assertFalse(Utils::isHiddenPrivateEvent($vevent, $node, $userPrincipal));
    }

    /**
     * Test that PUBLIC events are not hidden for anyone
     */
    function testIsHiddenPublicEventForNonOwner() {
        $icalData = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:public-event-test',
            'DTSTART:20260110T100000Z',
            'DTEND:20260110T110000Z',
            'SUMMARY:Public Meeting',
            'CLASS:PUBLIC',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $vcalendar = \Sabre\VObject\Reader::read($icalData);
        $vevent = $vcalendar->VEVENT;

        $node = new class('principals/users/owner') {
            private $owner;
            public function __construct($owner) { $this->owner = $owner; }
            public function getOwner() { return $this->owner; }
        };

        $userPrincipal = 'principals/users/other';

        $this->assertFalse(Utils::isHiddenPrivateEvent($vevent, $node, $userPrincipal));
    }

    /**
     * Test that hidePrivateEventInfoForUser masks PRIVATE event details
     */
    function testHidePrivateEventInfoForUserMasksPrivateEvents() {
        $icalData = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:private-event-test',
            'DTSTART:20260110T100000Z',
            'DTEND:20260110T110000Z',
            'SUMMARY:Secret Meeting',
            'DESCRIPTION:Very secret details',
            'LOCATION:Secret Location',
            'CLASS:PRIVATE',
            'ORGANIZER:mailto:boss@example.com',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $vcalendar = \Sabre\VObject\Reader::read($icalData);

        $node = new class('principals/users/owner') {
            private $owner;
            public function __construct($owner) { $this->owner = $owner; }
            public function getOwner() { return $this->owner; }
        };

        $userPrincipal = 'principals/users/other';

        $result = Utils::hidePrivateEventInfoForUser($vcalendar, $node, $userPrincipal);

        $this->assertEquals('Busy', $result->VEVENT->SUMMARY->getValue());
        $this->assertEquals('PRIVATE', $result->VEVENT->CLASS->getValue());
        $this->assertEquals('private-event-test', $result->VEVENT->UID->getValue());
        $this->assertEquals('mailto:boss@example.com', $result->VEVENT->ORGANIZER->getValue());
        $this->assertFalse(isset($result->VEVENT->DESCRIPTION));
        $this->assertFalse(isset($result->VEVENT->LOCATION));
    }

    /**
     * Test that hidePrivateEventInfoForUser masks CONFIDENTIAL event details
     */
    function testHidePrivateEventInfoForUserMasksConfidentialEvents() {
        $icalData = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:confidential-event-test',
            'DTSTART:20260110T100000Z',
            'DTEND:20260110T110000Z',
            'SUMMARY:Confidential Meeting',
            'DESCRIPTION:Very confidential details',
            'LOCATION:Confidential Location',
            'CLASS:CONFIDENTIAL',
            'ORGANIZER:mailto:boss@example.com',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $vcalendar = \Sabre\VObject\Reader::read($icalData);

        $node = new class('principals/users/owner') {
            private $owner;
            public function __construct($owner) { $this->owner = $owner; }
            public function getOwner() { return $this->owner; }
        };

        $userPrincipal = 'principals/users/other';

        $result = Utils::hidePrivateEventInfoForUser($vcalendar, $node, $userPrincipal);

        $this->assertEquals('Busy', $result->VEVENT->SUMMARY->getValue());
        $this->assertEquals('PRIVATE', $result->VEVENT->CLASS->getValue());
        $this->assertEquals('confidential-event-test', $result->VEVENT->UID->getValue());
        $this->assertEquals('mailto:boss@example.com', $result->VEVENT->ORGANIZER->getValue());
        $this->assertFalse(isset($result->VEVENT->DESCRIPTION));
        $this->assertFalse(isset($result->VEVENT->LOCATION));
    }

    /**
     * Test that hidePrivateEventInfoForUser does not mask events for owners
     */
    function testHidePrivateEventInfoForUserDoesNotMaskForOwner() {
        $icalData = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:private-event-test',
            'DTSTART:20260110T100000Z',
            'DTEND:20260110T110000Z',
            'SUMMARY:Secret Meeting',
            'DESCRIPTION:Very secret details',
            'LOCATION:Secret Location',
            'CLASS:PRIVATE',
            'ORGANIZER:mailto:boss@example.com',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $vcalendar = \Sabre\VObject\Reader::read($icalData);

        $node = new class('principals/users/owner') {
            private $owner;
            public function __construct($owner) { $this->owner = $owner; }
            public function getOwner() { return $this->owner; }
        };

        $userPrincipal = 'principals/users/owner';

        $result = Utils::hidePrivateEventInfoForUser($vcalendar, $node, $userPrincipal);

        // Event should remain unchanged for owner
        $this->assertEquals('Secret Meeting', $result->VEVENT->SUMMARY->getValue());
        $this->assertEquals('PRIVATE', $result->VEVENT->CLASS->getValue());
        $this->assertTrue(isset($result->VEVENT->DESCRIPTION));
        $this->assertTrue(isset($result->VEVENT->LOCATION));
    }
}