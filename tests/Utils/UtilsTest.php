<?php

namespace ESN\Utils;

use ESN\Utils\Utils;

class UtilsTest extends \PHPUnit_Framework_TestCase {

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

        $this->assertEquals($calendarPath, 'calendars/calendarHomeId-1/calendarId-1');
        $this->assertEquals($eventUri, 'eventUid-1.ics');
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
            $this->assertEquals($calendarPath, null);
            $this->assertEquals($eventUri, null);
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
}