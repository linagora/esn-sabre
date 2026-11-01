<?php

namespace ESN\JSON;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;
use \ESN\Utils\TenantType;
use \ESN\Utils\AuthTenant;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

/**
 * @medium
 */
class PluginTest extends \ESN\DAV\ServerMock {

    use \Sabre\VObject\PHPUnitAssertions;

    function setUp(): void {
        parent::setUp();

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals/users'];
        $aclPlugin->adminPrincipals[] = PRINCIPALS_TECHNICAL_USER;
        $this->server->addPlugin($aclPlugin);

        $plugin = new Plugin('json');
        $this->server->addPlugin($plugin);
    }

    function testCalendarHomeEventsReturnsMatchingOwnEvent() {
        $response = $this->calendarHomeEventsRequest('ownCalendar=true');

        $this->assertEquals(200, $response->status);
        $body = json_decode($response->getBodyAsString(), true);
        $this->assertEquals('/calendars/54b64eadf6d7d8e41d263e0f/events', $body['_links']['self']['href']);
        $this->assertCount(1, $body['_embedded']['dav:item']);
        $this->assertEquals(
            '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event1.ics',
            $body['_embedded']['dav:item'][0]['_links']['self']['href']
        );
        $events = $this->eventsOf($response);
        $this->assertEquals('Monday 0h', (string) $events['event1']->SUMMARY);
    }

    function testCalendarHomeEventsDefaultsToNoSelectedSources() {
        $response = $this->calendarHomeEventsRequest();

        $this->assertEquals(200, $response->status);
        $body = json_decode($response->getBodyAsString(), true);
        $this->assertSame([], $body['_embedded']['dav:item']);
    }

    function testCalendarHomeEventsDoesNotMatchUidPrefixes() {
        $response = $this->calendarHomeEventsRequest('ownCalendar=true', '{"uid":"event"}');

        $this->assertEquals(200, $response->status);
        $body = json_decode($response->getBodyAsString(), true);
        $this->assertSame([], $body['_embedded']['dav:item']);
    }

    public static function invalidCalendarHomeEventFilters(): array {
        return [
            'missing UID' => ['{}'],
            'non-string UID' => ['{"uid":42}'],
            'blank UID' => ['{"uid":"  "}'],
            'unsupported filter' => ['{"uid":"event1","summary":"Monday"}'],
            'malformed JSON' => ['{'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCalendarHomeEventFilters')]
    function testCalendarHomeEventsRejectsInvalidFilters($body) {
        $response = $this->calendarHomeEventsRequest('ownCalendar=true', $body);

        $this->assertEquals(400, $response->status);
    }

    function testCalendarHomeEventsRejectsInvalidSelector() {
        $response = $this->calendarHomeEventsRequest('ownCalendar=yes');

        $this->assertEquals(400, $response->status);
    }

    function testCalendarHomeEventsRequiresJsonContentType() {
        $response = $this->calendarHomeEventsRequest('ownCalendar=true', '{"uid":"event1"}', 'text/plain');

        $this->assertEquals(415, $response->status);
    }

    private function calendarHomeEventsRequest($query = '', $body = '{"uid":"event1"}', $contentType = 'application/json') {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD' => 'POST',
            'HTTP_CONTENT_TYPE' => $contentType,
            'HTTP_ACCEPT' => 'application/json',
            'REQUEST_URI' => '/calendars/54b64eadf6d7d8e41d263e0f/events' . ($query ? '?' . $query : ''),
        ]);
        $request->setBody($body);

        return $this->request($request);
    }

    function testTimeRangeQuery() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertCount(1, $jsonResponse->_embedded->{'dav:item'});
    }

    function testTimeRangeQuerySerializesRegisteredCustomCalendarPropertiesWithConfiguredTypes() {
        $customEvent = 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:custom-property-event
DTSTART:20120227T010000Z
DTEND:20120227T020000Z
SUMMARY:Custom property event
X-OPENPAAS-VIDEOCONFERENCE:https://meet.example/room
X-PUBLICLY-CREATED:true
X-PUBLICLY-CREATOR:bob@linagora.local
X-OPENPAAS-BOOKING-LINK:booking-link-id
END:VEVENT
END:VCALENDAR
';
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'custom-property.ics', $customEvent);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $customItem = null;
        foreach ($jsonResponse['_embedded']['dav:item'] as $item) {
            if (str_contains($item['_links']['self']['href'], 'custom-property.ics')) {
                $customItem = $item;
                break;
            }
        }

        $this->assertNotNull($customItem);
        $veventProperties = $customItem['data'][2][0][1];

        $videoConference = $this->findJCalProperty($veventProperties, 'x-openpaas-videoconference');
        $publiclyCreated = $this->findJCalProperty($veventProperties, 'x-publicly-created');
        $publiclyCreator = $this->findJCalProperty($veventProperties, 'x-publicly-creator');
        $bookingLink = $this->findJCalProperty($veventProperties, 'x-openpaas-booking-link');

        $this->assertEquals('uri', $videoConference[2]);
        $this->assertEquals('https://meet.example/room', $videoConference[3]);
        $this->assertEquals('boolean', $publiclyCreated[2]);
        $this->assertTrue($publiclyCreated[3]);
        $this->assertEquals('text', $publiclyCreator[2]);
        $this->assertEquals('bob@linagora.local', $publiclyCreator[3]);
        $this->assertEquals('text', $bookingLink[2]);
        $this->assertEquals('booking-link-id', $bookingLink[3]);
    }

    function testCalendarObjectReportSerializesRegisteredCustomCalendarPropertiesWithConfiguredTypes() {
        $customEvent = 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:custom-property-object-event
DTSTART:20120227T010000Z
DTEND:20120227T020000Z
SUMMARY:Custom property object event
X-OPENPAAS-VIDEOCONFERENCE:https://meet.example/room
X-PUBLICLY-CREATED:true
X-PUBLICLY-CREATOR:bob@linagora.local
X-OPENPAAS-BOOKING-LINK:booking-link-id
END:VEVENT
END:VCALENDAR
';
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'custom-property-object.ics', $customEvent);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/custom-property-object.ics',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);
        $veventProperties = $jsonResponse['data'][2][0][1];

        $videoConference = $this->findJCalProperty($veventProperties, 'x-openpaas-videoconference');
        $publiclyCreated = $this->findJCalProperty($veventProperties, 'x-publicly-created');
        $publiclyCreator = $this->findJCalProperty($veventProperties, 'x-publicly-creator');
        $bookingLink = $this->findJCalProperty($veventProperties, 'x-openpaas-booking-link');

        $this->assertEquals('uri', $videoConference[2]);
        $this->assertEquals('https://meet.example/room', $videoConference[3]);
        $this->assertEquals('boolean', $publiclyCreated[2]);
        $this->assertTrue($publiclyCreated[3]);
        $this->assertEquals('text', $publiclyCreator[2]);
        $this->assertEquals('bob@linagora.local', $publiclyCreator[3]);
        $this->assertEquals('text', $bookingLink[2]);
        $this->assertEquals('booking-link-id', $bookingLink[3]);
    }

    private function findJCalProperty($properties, $propertyName) {
        foreach ($properties as $property) {
            if ($property[0] === $propertyName) {
                return $property;
            }
        }

        return null;
    }

    function testTimeRangeQueryShouldReturnTwoEventsWhenBothAreInRange() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode($this->timeRangeDataBothEvents));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertCount(2, $jsonResponse->_embedded->{'dav:item'});
    }

    function testTimeRangeQueryShouldReturnMultistatusResponse() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode($this->timeRangeDataBothEvents));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $items = $jsonResponse->_embedded->{'dav:item'};
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertEquals(200, $item->status);
        }
    }

    function testTimeRangeQueryShouldIncludeSyncToken() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        // Verify that the response includes a sync-token in _embedded
        $this->assertObjectHasProperty('_embedded', $jsonResponse);
        $this->assertObjectHasProperty('sync-token', $jsonResponse->_embedded);

        // Verify that the sync-token has the expected format (http://sabre.io/ns/sync/{number})
        $this->assertIsString($jsonResponse->_embedded->{'sync-token'});
        $this->assertStringStartsWith('http://sabre.io/ns/sync/', $jsonResponse->_embedded->{'sync-token'});
    }

    function testTimeRangeQueryRecur() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode($this->timeRangeDataRecur));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $items = $jsonResponse->_embedded->{'dav:item'};
        $this->assertCount(1, $items);

        $vcalendar = \Sabre\VObject\Reader::readJson($items[0]->{'data'});

        $vevents = $vcalendar->select('VEVENT');
        $this->assertCount(3, $vevents);

        // All properties must contain a recurrence id
        foreach ($vevents as $vevent) {
            $this->assertTrue(isset($vevent->{'RECURRENCE-ID'}));
        }
    }

    function testTimeRangeQueryRecurExceptionOnly() {
        // Test for issue #138: recurring event with only RECURRENCE-ID (no master event with RRULE)
        // This happens when a user is invited to only one occurrence of a recurring event

        // Create an event with only RECURRENCE-ID (no RRULE)
        $calendars = $this->caldavBackend->getCalendarsForUser('principals/users/54b64eadf6d7d8e41d263e0f');
        $calendarId = null;
        foreach ($calendars as $calendar) {
            if ($calendar['uri'] === 'calendar1') {
                $calendarId = $calendar['id'];
                break;
            }
        }

        $ics = 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:exception-only-event
TRANSP:OPAQUE
DTSTART:20150228T073000Z
DTEND:20150228T080000Z
CLASS:PUBLIC
SUMMARY:Exception Only
RECURRENCE-ID:20150228T073000Z
DTSTAMP:20151021T083253Z
SEQUENCE:0
END:VEVENT
END:VCALENDAR
';
        $this->caldavBackend->createCalendarObject($calendarId, 'exception-only.ics', $ics);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $timeRangeData = [
            'match' => [ 'start' => '20150227T000000Z', 'end' => '20150301T000000Z' ],
            'scope' => [ 'calendars' => [ '/calendars/54b64eadf6d7d8e41d263e0f/calendar1' ] ]
        ];

        $request->setBody(json_encode($timeRangeData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        // Should return 2 items: recur.ics (expanded) and exception-only.ics (not expanded)
        $items = $jsonResponse->_embedded->{'dav:item'};
        $this->assertCount(2, $items);

        // Find the exception-only.ics item
        $exceptionOnlyItem = null;
        foreach ($items as $item) {
            if (strpos($item->{'_links'}->{'self'}->{'href'}, 'exception-only.ics') !== false) {
                $exceptionOnlyItem = $item;
                break;
            }
        }

        $this->assertNotNull($exceptionOnlyItem, 'exception-only.ics should be in the results');

        $vcalendar = \Sabre\VObject\Reader::readJson($exceptionOnlyItem->{'data'});
        $vevents = $vcalendar->select('VEVENT');

        // Event with only RECURRENCE-ID should be returned as-is (not expanded)
        $this->assertCount(1, $vevents);
        $this->assertEquals('Exception Only', (string)$vevents[0]->SUMMARY);
        $this->assertTrue(isset($vevents[0]->{'RECURRENCE-ID'}));
    }

    function testTimeRangeQueryRecurExceptionOnlyWithTimezone() {
        // Test for issue #138: event with RECURRENCE-ID and TZID should have dates converted to UTC
        $calendars = $this->caldavBackend->getCalendarsForUser('principals/users/54b64eadf6d7d8e41d263e0f');
        $calendarId = null;
        foreach ($calendars as $calendar) {
            if ($calendar['uri'] === 'calendar1') {
                $calendarId = $calendar['id'];
                break;
            }
        }

        $ics = 'BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VTIMEZONE
TZID:Europe/Paris
BEGIN:STANDARD
DTSTART:19701025T030000
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU
END:STANDARD
BEGIN:DAYLIGHT
DTSTART:19700329T020000
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU
END:DAYLIGHT
END:VTIMEZONE
BEGIN:VEVENT
UID:exception-with-timezone
TRANSP:OPAQUE
DTSTART;TZID=Europe/Paris:20150228T100000
DTEND;TZID=Europe/Paris:20150228T110000
CLASS:PUBLIC
SUMMARY:Exception with Timezone
RECURRENCE-ID;TZID=Europe/Paris:20150228T100000
DTSTAMP:20151021T083253Z
SEQUENCE:0
END:VEVENT
END:VCALENDAR
';
        $this->caldavBackend->createCalendarObject($calendarId, 'exception-timezone.ics', $ics);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $timeRangeData = [
            'match' => [ 'start' => '20150227T000000Z', 'end' => '20150301T000000Z' ],
            'scope' => [ 'calendars' => [ '/calendars/54b64eadf6d7d8e41d263e0f/calendar1' ] ]
        ];

        $request->setBody(json_encode($timeRangeData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        // Find the exception-timezone.ics item
        $items = $jsonResponse->_embedded->{'dav:item'};
        $exceptionTimezoneItem = null;
        foreach ($items as $item) {
            if (strpos($item->{'_links'}->{'self'}->{'href'}, 'exception-timezone.ics') !== false) {
                $exceptionTimezoneItem = $item;
                break;
            }
        }

        $this->assertNotNull($exceptionTimezoneItem, 'exception-timezone.ics should be in the results');

        $vcalendar = \Sabre\VObject\Reader::readJson($exceptionTimezoneItem->{'data'});

        // VTIMEZONE should be removed (to match expand() behavior)
        $this->assertCount(0, $vcalendar->select('VTIMEZONE'), 'VTIMEZONE should be removed');

        $vevents = $vcalendar->select('VEVENT');
        $this->assertCount(1, $vevents);

        $vevent = $vevents[0];

        // Dates should have been processed
        // TZID parameter should be removed
        $this->assertFalse(isset($vevent->DTSTART['TZID']), 'DTSTART should not have TZID parameter');
        $this->assertFalse(isset($vevent->DTEND['TZID']), 'DTEND should not have TZID parameter');

        // The dates should be in UTC format (ending with Z)
        $this->assertStringEndsWith('Z', (string)$vevent->DTSTART, 'DTSTART should end with Z (UTC format)');
        $this->assertStringEndsWith('Z', (string)$vevent->DTEND, 'DTEND should end with Z (UTC format)');

        // RECURRENCE-ID must have the same UTC shape as the expand() output
        $this->assertFalse(isset($vevent->{'RECURRENCE-ID'}['TZID']), 'RECURRENCE-ID should not have TZID parameter');
        $this->assertEquals('20150228T090000Z', (string)$vevent->{'RECURRENCE-ID'});
    }

    function testTimeRangeQueryShouldNotReturnRecurringEventWhoseOnlyOccurrenceInRangeWasMovedOut() {
        // Issue #631: weekly master whose occurrence in the range was moved to the next week
        $calendarId = $this->getCalendar1Id();

        $ics = 'BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Test//Test//EN
BEGIN:VTIMEZONE
TZID:Europe/Paris
BEGIN:STANDARD
DTSTART:19701025T030000
TZOFFSETFROM:+0200
TZOFFSETTO:+0100
RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU
END:STANDARD
BEGIN:DAYLIGHT
DTSTART:19700329T020000
TZOFFSETFROM:+0100
TZOFFSETTO:+0200
RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU
END:DAYLIGHT
END:VTIMEZONE
BEGIN:VEVENT
UID:moved-out-occurrence
DTSTART;TZID=Europe/Paris:20261030T140000
DTEND;TZID=Europe/Paris:20261030T150000
RRULE:FREQ=WEEKLY;BYDAY=FR
SUMMARY:Weekly
DTSTAMP:20261020T083253Z
END:VEVENT
BEGIN:VEVENT
UID:moved-out-occurrence
RECURRENCE-ID;TZID=Europe/Paris:20261030T140000
DTSTART;TZID=Europe/Paris:20261102T140000
DTEND;TZID=Europe/Paris:20261102T150000
SUMMARY:Weekly moved
DTSTAMP:20261020T083253Z
END:VEVENT
END:VCALENDAR
';
        $this->caldavBackend->createCalendarObject($calendarId, 'moved-out.ics', $ics);

        $items = $this->timeRangeReportOnCalendar1('20261026T000000Z', '20261101T000000Z');

        $this->assertNull($this->findItemByUri($items, 'moved-out.ics'), 'moved-out.ics has no occurrence in range');

        $nextWeekItem = $this->findItemByUri(
            $this->timeRangeReportOnCalendar1('20261102T000000Z', '20261108T000000Z'),
            'moved-out.ics'
        );
        $this->assertNotNull($nextWeekItem, 'moved-out.ics should be returned for the following week');

        $vevents = \Sabre\VObject\Reader::readJson($nextWeekItem->{'data'})->select('VEVENT');
        $this->assertCount(2, $vevents);
        $this->assertEquals('20261030T130000Z', (string)$vevents[0]->{'RECURRENCE-ID'});
        $this->assertEquals('20261102T130000Z', (string)$vevents[0]->DTSTART);
    }

    private function getCalendar1Id() {
        $calendars = $this->caldavBackend->getCalendarsForUser('principals/users/54b64eadf6d7d8e41d263e0f');
        $calendar1 = array_values(array_filter($calendars, function ($calendar) {
            return $calendar['uri'] === 'calendar1';
        }));

        return $calendar1[0]['id'];
    }

    private function timeRangeReportOnCalendar1($start, $end) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));
        $request->setBody(json_encode([
            'match' => [ 'start' => $start, 'end' => $end ],
            'scope' => [ 'calendars' => [ '/calendars/54b64eadf6d7d8e41d263e0f/calendar1' ] ]
        ]));

        return json_decode($this->request($request)->getBodyAsString())->_embedded->{'dav:item'};
    }

    private function findItemByUri($items, $uri) {
        $matching = array_values(array_filter($items, function ($item) use ($uri) {
            return strpos($item->{'_links'}->{'self'}->{'href'}, $uri) !== false;
        }));

        return $matching[0] ?? null;
    }

    function testTimeRangeQueryShouldFindEventWithCustomTimezoneAtItsUtcTime() {
        // A TZID that is not an Olson identifier can only be resolved through
        // its VTIMEZONE: the denormalized range must honour it.
        $ics = implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Linagora//Twake Calendar//EN',
            'BEGIN:VTIMEZONE',
            'TZID:Custom Eastern',
            'X-LIC-LOCATION:America/New_York',
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:-0400',
            'TZOFFSETTO:-0400',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:custom-timezone-event',
            'DTSTAMP:20260301T000000Z',
            'DTSTART;TZID=Custom Eastern:20260322T090000',
            'DTEND;TZID=Custom Eastern:20260322T100000',
            'SUMMARY:Custom timezone event',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $put = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/custom-timezone-event.ics',
        ));
        $put->setBody($ics);
        $this->assertEquals(201, $this->request($put)->getStatus());

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));
        $request->setBody(json_encode([
            'match' => [ 'start' => '20260322T130000Z', 'end' => '20260322T150000Z' ]
        ]));
        $response = $this->request($request);

        $this->assertStringContainsString('custom-timezone-event', $response->getBodyAsString());
    }

    function testTimeRangeQueryMissingMatch() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $data = $this->timeRangeData;
        unset($data['match']);

        $request->setBody(json_encode($data));
        $response = $this->request($request);
        $this->assertEquals($response->status, 400);
    }

    function testGetDefaultCalendar() {
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0c');

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0c/events.json',
        ));

        $request->setBody(json_encode($this->oldTimeRangeData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertCount(1, $jsonResponse->_embedded->{'dav:item'});
    }

    function testTechnicalUserGetDefaultCalendarResolvesHomeOfPath() {
        $this->authBackend->setPrincipal('principals/technicalUser');

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0c/events.json',
        ));

        $request->setBody(json_encode($this->oldTimeRangeData));
        $response = $this->request($request);

        $this->assertEquals(200, $response->status);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertCount(1, $jsonResponse->_embedded->{'dav:item'});
    }

    function testTechnicalUserPutIntoEventsCalendarIsNotA500() {
        $this->caldavBackend->createCalendar('principals/users/54b64eadf6d7d8e41d263e0f',
            \ESN\CalDAV\Backend\Esn::EVENTS_URI, ['{DAV:}displayname' => 'Calendar']);
        $this->authBackend->setPrincipal('principals/technicalUser');

        $response = $this->putNewEvent('/calendars/54b64eadf6d7d8e41d263e0f/events/new.ics');

        $this->assertEquals(201, $response->status);
        $this->assertNotNull($this->server->tree->getNodeForPath('calendars/54b64eadf6d7d8e41d263e0f/events/new.ics'));
    }

    function testPutObjectWhoseNameContainsEventsIsNotRewritten() {
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0f');

        $response = $this->putNewEvent('/calendars/54b64eadf6d7d8e41d263e0f/calendar1/events-1.ics');

        $this->assertEquals(201, $response->status);
        $this->assertNotNull($this->server->tree->getNodeForPath('calendars/54b64eadf6d7d8e41d263e0f/calendar1/events-1.ics'));
    }

    private function putNewEvent($uri) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'REQUEST_URI'       => $uri,
        ]);
        $request->setBody("BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:new-1\r\n"
            . "DTSTAMP:20261001T000000Z\r\nDTSTART:20261110T100000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        return $this->request($request);
    }

    function testFreebusyReport() {
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0c');

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0c/events.json',
        ));

        $request->setBody(json_encode($this->freeBusyTimeRangeData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $vobjResponse = \Sabre\VObject\Reader::readJson($jsonResponse->data);

        $this->assertVObjectEqualsVObject($this->freeBusyReport, $vobjResponse);
    }

    function testGetAnonimizedCalendarObjects() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json',
        ));

        $request->setBody(json_encode($this->timeRangeDataRecur));
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $vcalendar = \Sabre\VObject\Reader::readJson($jsonResponse->_embedded->{'dav:item'}[0]->{'data'});
        $vevents = $vcalendar->select('VEVENT');
        $this->assertCount(3, $vevents);
        $this->assertEquals($vevents[0]->SUMMARY, 'Busy');
        $this->assertEquals($vevents[0]->CLASS, 'PRIVATE');
        $this->assertEquals($vevents[1]->SUMMARY, 'Exception');
        $this->assertEquals($vevents[2]->SUMMARY, 'Busy');
        $this->assertEquals($vevents[2]->CLASS, 'PRIVATE');
    }

    // User 0e shares her (non public) calendar2: the JSON listing advertises it to the sharees as
    // calendarserver:delegatedsource, and the frontend reads the events there.
    private function shareCalendarUser2With($principal, $access) {
        $this->caldavBackend->createCalendarObject($this->calUser2['id'], 'shared.ics', 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:shared-event
SUMMARY:Sprint planning
DTSTART:20150227T090000Z
DTEND:20150227T100000Z
END:VEVENT
END:VCALENDAR
');
        $this->caldavBackend->createCalendarObject($this->calUser2['id'], 'private.ics', 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:private-event
SUMMARY:Secret meeting
LOCATION:Secret room
DTSTART:20150227T110000Z
DTEND:20150227T120000Z
CLASS:PRIVATE
END:VEVENT
END:VCALENDAR
');
        $this->caldavBackend->updateInvites($this->calUser2['id'], [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href'         => 'mailto:sharee@example.org',
                'principal'    => $principal,
                'access'       => $access,
                'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED,
            ]),
        ]);
    }

    private function jsonReportCalendarUser2As($principal) {
        $this->authBackend->setPrincipal($principal);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/calendar2.json',
        ));
        $request->setBody(json_encode($this->timeRangeDataRecur));

        return $this->request($request);
    }

    private function eventsOf($response) {
        $events = [];
        foreach (json_decode($response->getBodyAsString())->_embedded->{'dav:item'} as $item) {
            $vcalendar = \Sabre\VObject\Reader::readJson($item->data);
            foreach ($vcalendar->select('VEVENT') as $vevent) {
                $events[(string) $vevent->UID] = $vevent;
            }
        }

        return $events;
    }

    public static function shareeReadAccessCases(): array {
        return [
            'read' => [\Sabre\DAV\Sharing\Plugin::ACCESS_READ],
            'read-write' => [\Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE],
            'administration' => [\ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shareeReadAccessCases')]
    function testShareeCanJsonReportTheOwnerCalendarByTimeRange($access) {
        $this->shareCalendarUser2With('principals/users/54b64eadf6d7d8e41d263e0f', $access);

        $response = $this->jsonReportCalendarUser2As('principals/users/54b64eadf6d7d8e41d263e0f');

        $this->assertEquals(200, $response->status);
        $events = $this->eventsOf($response);
        $this->assertEquals('Sprint planning', (string) $events['shared-event']->SUMMARY);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('shareeReadAccessCases')]
    function testPrivateEventIsAnonymizedWhenAShareeJsonReportsTheOwnerCalendar($access) {
        $this->shareCalendarUser2With('principals/users/54b64eadf6d7d8e41d263e0f', $access);

        $response = $this->jsonReportCalendarUser2As('principals/users/54b64eadf6d7d8e41d263e0f');

        $this->assertEquals(200, $response->status);
        $events = $this->eventsOf($response);
        $this->assertEquals('Busy', (string) $events['private-event']->SUMMARY);
        $this->assertStringNotContainsString('Secret', $response->getBodyAsString());
    }

    function testUserWithoutShareCannotJsonReportTheOwnerCalendar() {
        $this->shareCalendarUser2With('principals/users/54b64eadf6d7d8e41d263e0f', \Sabre\DAV\Sharing\Plugin::ACCESS_READ);

        $response = $this->jsonReportCalendarUser2As('principals/users/54b64eadf6d7d8e41d263e0c');

        $this->assertEquals(403, $response->status);
    }

    function testFreeBusyShareeCannotJsonReportTheOwnerCalendar() {
        $this->shareCalendarUser2With('principals/users/54b64eadf6d7d8e41d263e0f', \ESN\DAV\Sharing\Plugin::ACCESS_FREEBUSY);

        $response = $this->jsonReportCalendarUser2As('principals/users/54b64eadf6d7d8e41d263e0f');

        $this->assertEquals(403, $response->status);
    }

    function testFormerShareeCannotJsonReportTheOwnerCalendar() {
        $this->shareCalendarUser2With('principals/users/54b64eadf6d7d8e41d263e0f', \Sabre\DAV\Sharing\Plugin::ACCESS_READ);
        $this->caldavBackend->updateInvites($this->calUser2['id'], [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href'      => 'mailto:sharee@example.org',
                'principal' => 'principals/users/54b64eadf6d7d8e41d263e0f',
                'access'    => \Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS,
            ]),
        ]);

        $response = $this->jsonReportCalendarUser2As('principals/users/54b64eadf6d7d8e41d263e0f');

        $this->assertEquals(403, $response->status);
    }

    function testGetSubscriptionObjects() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json',
        ));

        $request->setBody(json_encode($this->timeRangeDataRecur));
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $vcalendar = \Sabre\VObject\Reader::readJson($jsonResponse->_embedded->{'dav:item'}[0]->{'data'});
        $vevents = $vcalendar->select('VEVENT');
        $this->assertCount(3, $vevents);
        $this->assertEquals($vevents[0]->SUMMARY, 'Busy');
        $this->assertEquals($vevents[0]->CLASS, 'PRIVATE');
        $this->assertEquals($vevents[1]->SUMMARY, 'Exception');
        $this->assertEquals($vevents[2]->SUMMARY, 'Busy');
        $this->assertEquals($vevents[2]->CLASS, 'PRIVATE');
    }

    function testGetAnonimizedCalendarObjectByUID() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e',
        ));

        $request->setBody(json_encode([ 'uid' => '75EE3C60-34AC-4A97-953D-56CC004D6706' ]));
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $vcalendar = \Sabre\VObject\Reader::readJson($jsonResponse->_embedded->{'dav:item'}[0]->{'data'});
        $vevents = $vcalendar->select('VEVENT');
        $this->assertCount(2, $vevents);
        $this->assertEquals($vevents[0]->SUMMARY->getValue(), 'Busy');
        $this->assertEquals($vevents[0]->CLASS, 'PRIVATE');
        $this->assertEquals($vevents[1]->SUMMARY, 'Exception');
    }

    function test403ModifyPrivateCalendarObjects() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PUT',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1/privateRecurEvent.ics',
        ));

        $event = $this->caldavBackend->getCalendarObject([$this->publicCal['id'][0], ''], 'privateRecurEvent.ics');

        $request->setBody($event['calendardata']);
        $response = $this->request($request);
        $this->assertEquals($response->status, 403);
    }

    function test403DeletePrivateCalendarObjects() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1/privateRecurEvent.ics',
        ));

        $event = $this->caldavBackend->getCalendarObject([$this->publicCal['id'][0], ''], 'privateRecurEvent.ics');
        $response = $this->request($request);
        $this->assertEquals($response->status, 403);
    }

    function test403ModifyOwnerPrivateCalendarObjectsViaDelegatedCalendar() {
        $delegatedEventUri = $this->givenOwnerPrivateEventInDelegatedCalendar();

        $response = $this->requestAsDelegate('PUT', $delegatedEventUri, 'text/calendar', $this->privateRecurEvent);

        $this->assertEquals(403, $response->status);
    }

    function test403ModifyOwnerPrivateCalendarObjectsViaDelegatedCalendarWithJson() {
        $delegatedEventUri = $this->givenOwnerPrivateEventInDelegatedCalendar();
        $jsonEvent = json_encode(\Sabre\VObject\Reader::read($this->privateRecurEvent)->jsonSerialize());

        $response = $this->requestAsDelegate('PUT', $delegatedEventUri, 'application/calendar+json', $jsonEvent);

        $this->assertEquals(403, $response->status);
    }

    function test403DeleteOwnerPrivateCalendarObjectsViaDelegatedCalendar() {
        $delegatedEventUri = $this->givenOwnerPrivateEventInDelegatedCalendar();

        $response = $this->requestAsDelegate('DELETE', $delegatedEventUri);

        $this->assertEquals(403, $response->status);
    }

    function testOwnerCanModifyPrivateCalendarObjectsOfDelegatedCalendar() {
        $this->givenOwnerPrivateEventInDelegatedCalendar();
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/' . $this->delegatedCal['uri'] . '/privateRecurEvent.ics',
        ));
        $request->setBody($this->privateRecurEvent);

        $response = $this->request($request);

        $this->assertEquals(204, $response->status);
    }

    private function givenOwnerPrivateEventInDelegatedCalendar() {
        $this->caldavBackend->createCalendarObject($this->delegatedCal['id'], 'privateRecurEvent.ics', $this->privateRecurEvent);
        $this->caldavBackend->updateInvites($this->delegatedCal['id'], [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href'       => 'mailto:johndoe@example.org',
                'principal'  => 'principals/users/54b64eadf6d7d8e41d263e0e',
                'access'     => \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE,
                'properties' => []
            ])
        ]);

        $delegateInstances = array_filter(
            $this->caldavBackend->getCalendarsForUser('principals/users/54b64eadf6d7d8e41d263e0e'),
            fn($calendar) => $calendar['id'][0] == $this->delegatedCal['id'][0]
        );

        return '/calendars/54b64eadf6d7d8e41d263e0e/' . reset($delegateInstances)['uri'] . '/privateRecurEvent.ics';
    }

    private function requestAsDelegate($method, $uri, $contentType = null, $body = null) {
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0e');
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array_filter(array(
            'REQUEST_METHOD'    => $method,
            'HTTP_CONTENT_TYPE' => $contentType,
            'REQUEST_URI'       => $uri,
        )));
        if ($body !== null) {
            $request->setBody($body);
        }

        return $this->request($request);
    }

    function testTimeRangeQuery404() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/notfound.json',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testTimeRangeOutsideroot() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/notfound.jaysun',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testMultiQueryEndpointIsNotAvailable() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/query.json',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    private function checkCalendars($calendars) {
        $this->assertCount(count($this->user1Calendars['ownedCalendars']) + count($this->user1Calendars['otherCalendars']), $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/4');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');

        $this->assertEquals($calendars[1]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/delegatedCal1.json');
        $this->assertEquals($calendars[1]->{'dav:name'}, 'delegatedCalendar');

        $this->assertEquals($calendars[2]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/user1Calendar2.json');
        $this->assertEquals($calendars[2]->{'dav:name'}, 'User1 Calendar2');

        $this->assertEquals($calendars[3]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json');
        $this->assertEquals($calendars[3]->{'dav:name'}, 'Subscription');
        $this->assertEquals($calendars[3]->{'calendarserver:source'}->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json');

        return $calendars;
    }

    function testGetCalendarRootAsNormalUser() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(403, $response->status);
    }

    private function authenticateAsTechnicalUserOfUsersDomain() {
        $DOMAIN_ID = '54b64eadf6d7d8e41d263e7e';
        $this->esndb->users->updateMany(
            [],
            [ '$set' => [ 'domains' => [ [ 'domain_id' => new \MongoDB\BSON\ObjectId($DOMAIN_ID) ] ] ] ]
        );
        $this->esndb->resources->updateMany(
            [],
            [ '$set' => [ 'domain' => new \MongoDB\BSON\ObjectId($DOMAIN_ID) ] ]
        );
        $this->authBackend->setAuthTenant(new AuthTenant('technicalUser', $DOMAIN_ID, TenantType::Technical));
    }

    function testGETAddressBookHomesWithTechnicalUser() {
        $this->authenticateAsTechnicalUserOfUsersDomain();

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);
        $this->assertEquals(200, $response->status);

        $this->assertEquals([
            // 4 users
            '54b64eadf6d7d8e41d263e0f' => [ 'calendar1', 'delegatedCal1', 'user1Calendar2' ],
            '54b64eadf6d7d8e41d263e0e' => [ 'calendar2', 'publicCal1' ],
            '54b64eadf6d7d8e41d263e0d' => [],
            '54b64eadf6d7d8e41d263e0c' => [ '54b64eadf6d7d8e41d263e0c' ],
            // 1 resource
            '62b64eadf6d7d8e41d263e0c' => []
        ], $jsonResponse);
    }

    private function _testCalendarList($withRightsParam = null) {
        $requestUri = $withRightsParam ? '/calendars/54b64eadf6d7d8e41d263e0f.json?withRights=' . $withRightsParam : '/calendars/54b64eadf6d7d8e41d263e0f.json';
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => $requestUri,
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');

        return $this->checkCalendars($jsonResponse->{'_embedded'}->{'dav:calendar'});
    }

    function testCalendarList() {
        $this->_testCalendarList();
    }

    function testCalendarListSurvivesSharedCalendarOfDeletedOwner() {
        $gone = 'principals/users/54b64eadf6d7d8e41d26e099';
        $id = $this->caldavBackend->createCalendar($gone, 'gonecal', ['{DAV:}displayname' => 'Gone']);
        $this->caldavBackend->updateInvites($id, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href'         => 'mailto:user1@example.org',
                'principal'    => 'principals/users/54b64eadf6d7d8e41d263e0f',
                'access'       => \Sabre\DAV\Sharing\Plugin::ACCESS_READ,
                'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED,
            ]),
        ]);

        $response = $this->request(\Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT'    => 'application/json',
            'REQUEST_URI'    => '/calendars/54b64eadf6d7d8e41d263e0f.json?sharedDelegationStatus=accepted',
        ]));

        $this->assertEquals(200, $response->status);
        $this->assertStringNotContainsString('54b64eadf6d7d8e41d26e099', $response->getBodyAsString());
    }

    function testCalendarListWithFreeBusy() {
        $requestUri = '/calendars/54b64eadf6d7d8e41d263e0f.json?withFreeBusy=true';
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => $requestUri,
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');

        $calendars = $jsonResponse->{'_embedded'}->{'dav:calendar'};

        $expectedCount = count($this->user1Calendars['ownedCalendars']) + count($this->user1Calendars['otherCalendars']);
        $this->assertCount($expectedCount, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/4');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');

        $this->assertEquals($calendars[1]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/delegatedCal1.json');
        $this->assertEquals($calendars[1]->{'dav:name'}, 'delegatedCalendar');
    }

    private function listedCalendarsByHref($requestUri) {
        $response = $this->request(\Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD' => 'GET',
            'HTTP_ACCEPT'    => 'application/json',
            'REQUEST_URI'    => $requestUri,
        ]));
        $this->assertEquals(200, $response->status);

        $calendars = [];
        foreach (json_decode($response->getBodyAsString(), true)['_embedded']['dav:calendar'] as $calendar) {
            $calendars[$calendar['_links']['self']['href']] = $calendar;
        }

        return $calendars;
    }

    function testCalendarListWithFreeBusyOnlyGivesTheLinkOfCalendarsTheUserCanNotRead() {
        $calendars = $this->listedCalendarsByHref('/calendars/54b64eadf6d7d8e41d263e0e.json?withFreeBusy=true&withRights=true');

        // calendar2 has no public right: authenticated users may only read its free/busy
        $this->assertSame(
            ['_links' => ['self' => ['href' => '/calendars/54b64eadf6d7d8e41d263e0e/calendar2.json']]],
            $calendars['/calendars/54b64eadf6d7d8e41d263e0e/calendar2.json']
        );

        // publicCal1 is publicly readable: rendered in full
        $publicCal = $calendars['/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json'];
        $this->assertSame('Calendar', $publicCal['dav:name']);
        $this->assertArrayHasKey('acl', $publicCal);
    }

    function testCalendarListWithFreeBusyGivesOnlyTheLinkOfAFreeBusySharedInstance() {
        $this->shareCalendarUser2With('principals/users/54b64eadf6d7d8e41d263e0f', \ESN\DAV\Sharing\Plugin::ACCESS_FREEBUSY);

        $calendars = $this->listedCalendarsByHref('/calendars/54b64eadf6d7d8e41d263e0f.json?withFreeBusy=true&withRights=true');

        $ownCalendars = array_map(function ($calendar) {
            return '/calendars/54b64eadf6d7d8e41d263e0f/' . $calendar['uri'] . '.json';
        }, array_merge($this->user1Calendars['ownedCalendars'], $this->user1Calendars['otherCalendars']));
        $sharedInstances = array_diff_key($calendars, array_flip($ownCalendars));

        $this->assertCount(1, $sharedInstances);
        $this->assertSame(['_links'], array_keys(reset($sharedInstances)));
    }

    function testSharedPublicListGivesOnlyTheLinkOfFreeBusyPublicCalendars() {
        $this->caldavBackend->saveCalendarPublicRight($this->calUser2['id'], '{urn:ietf:params:xml:ns:caldav}read-free-busy', [
            'principaluri' => $this->calUser2['principaluri'],
            'uri' => $this->calUser2['uri']
        ]);

        $calendars = $this->listedCalendarsByHref('/calendars/54b64eadf6d7d8e41d263e0e.json?sharedPublic=true&withRights=true');

        $this->assertSame(
            ['_links' => ['self' => ['href' => '/calendars/54b64eadf6d7d8e41d263e0e/calendar2.json']]],
            $calendars['/calendars/54b64eadf6d7d8e41d263e0e/calendar2.json']
        );
        $publicCal = $calendars['/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json'];
        $this->assertSame('description', $publicCal['caldav:description']);
        $this->assertArrayHasKey('acl', $publicCal);
    }

    function testSharedPublicListGivesFreeBusyPublicCalendarsInFullToTheirOwner() {
        $this->caldavBackend->saveCalendarPublicRight($this->calUser2['id'], '{urn:ietf:params:xml:ns:caldav}read-free-busy', [
            'principaluri' => $this->calUser2['principaluri'],
            'uri' => $this->calUser2['uri']
        ]);
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0e');

        $calendars = $this->listedCalendarsByHref('/calendars/54b64eadf6d7d8e41d263e0e.json?sharedPublic=true&withRights=true');

        $calendar = $calendars['/calendars/54b64eadf6d7d8e41d263e0e/calendar2.json'];
        $this->assertSame('Calendar', $calendar['dav:name']);
        $this->assertArrayHasKey('invite', $calendar);
        $this->assertArrayHasKey('acl', $calendar);
    }

    function testCalendarListWithRights() {
        $calendars = $this->_testCalendarList('true');
        $this->assertNotNull($calendars[1]->{'dav:name'});
        $this->assertNotNull($calendars[1]->{'invite'});
        $this->assertNotNull($calendars[1]->{'acl'});
    }

    function testCalendarListWithoutRights() {
        $calendars = $this->_testCalendarList('false');
        $this->assertFalse(isset($calendars[1]->{'invite'}));
        $this->assertFalse(isset($calendars[1]->{'acl'}));
    }

    private function _testFilteredCalendarList($personal = null, $subscription = null, $inviteStatusFilter = null) {
        $delegationRequest = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json',
        ));

        $sharees = [
            'share' => [
                'set' => [
                    [
                        'dav:href' => 'mailto:robertocarlos@realmadrid.com',
                        'dav:read' => true
                    ]
                ]
            ]
        ];

        $delegationRequest->setBody(json_encode($sharees));
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0e');
        $response = $this->request($delegationRequest);
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0f');

        $this->assertEquals(200, $response->status);


        $filterParams = '';
        if (isset($personal)) {
            $filterParams = '&personal=' . $personal;
        };

        if (isset($subscription)) {
            $filterParams = $filterParams . '&sharedPublicSubscription=' . $subscription;
        };

        if (isset($inviteStatusFilter)) {
            $filterParams = $filterParams . '&sharedDelegationStatus=' . $inviteStatusFilter;
        };

        $filterParams = $filterParams === '' ? '' : '?'.substr($filterParams, 1);

        $requestUri = '/calendars/54b64eadf6d7d8e41d263e0f.json' . $filterParams;
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => $requestUri,
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);

        return $jsonResponse;
    }

    function testFilteredCalendarList() {
        $jsonResponse = $this->_testFilteredCalendarList();
        $calendars = $jsonResponse->{'_embedded'}->{'dav:calendar'};

        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');
        $this->assertFalse(isset($calendars[1]->{'invite'}));
        $this->assertFalse(isset($calendars[1]->{'acl'}));

        $this->assertCount(count($this->user1Calendars['ownedCalendars']) + count($this->user1Calendars['otherCalendars']), $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/4');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');
        $this->assertFalse(property_exists($calendars[0], 'calendarserver:delegatedsource'));

        $this->assertEquals($calendars[1]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/delegatedCal1.json');
        $this->assertEquals($calendars[1]->{'dav:name'}, 'delegatedCalendar');
        $this->assertFalse(property_exists($calendars[1], 'calendarserver:delegatedsource'));

        $this->assertEquals($calendars[2]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/user1Calendar2.json');
        $this->assertEquals($calendars[2]->{'dav:name'}, 'User1 Calendar2');
        $this->assertFalse(property_exists($calendars[2], 'calendarserver:delegatedsource'));

        $this->assertEquals($calendars[3]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json');
        $this->assertEquals($calendars[3]->{'dav:name'}, 'Subscription');
        $this->assertEquals($calendars[3]->{'calendarserver:source'}->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json');
        $this->assertFalse(property_exists($calendars[3], 'calendarserver:delegatedsource'));
    }

    function testFilteredCalendarLisWithPersonalOnly() {
        $jsonResponse = $this->_testFilteredCalendarList('true');
        $calendars = $jsonResponse->{'_embedded'}->{'dav:calendar'};

        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/4');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');
        $this->assertFalse(property_exists($calendars[0], 'calendarserver:delegatedsource'));

        $this->assertEquals($calendars[1]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/delegatedCal1.json');
        $this->assertEquals($calendars[1]->{'dav:name'}, 'delegatedCalendar');
        $this->assertFalse(property_exists($calendars[1], 'calendarserver:delegatedsource'));
    }

    function testFilteredCalendarLisWithSubscriptionOnly() {
        $jsonResponse = $this->_testFilteredCalendarList(null, 'true');
        $calendars = $jsonResponse->{'_embedded'}->{'dav:calendar'};

        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');
        $this->assertCount(1, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Subscription');
        $this->assertEquals($calendars[0]->{'calendarserver:source'}->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json');
        $this->assertFalse(property_exists($calendars[0], 'calendarserver:delegatedsource'));
    }

    function testFilteredCalendarLisWithSharedNoResponseOnly() {
        $jsonResponse = $this->_testFilteredCalendarList(null, null, 'noresponse');

        $this->assertSame('/calendars/54b64eadf6d7d8e41d263e0f.json', $jsonResponse->{'_links'}->self->href);
        $this->assertSame([], $jsonResponse->{'_embedded'}->{'dav:calendar'});
    }

    function testFilteredCalendarLisWithSharedAcceptedOnly() {
        $jsonResponse = $this->_testFilteredCalendarList(null, null, 'accepted');

        $calendars = $jsonResponse->{'_embedded'}->{'dav:calendar'};

        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');
        $this->assertCount(1, $calendars);

        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'calendarserver:delegatedsource'}, '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json');
    }

    /**
     * Test that listing calendars with a subscription that has an empty source href
     * does not crash with TypeError (null passed to getNodeForPath).
     *
     * Regression test for: Sabre\Uri\split(): Argument #1 ($path) must be of type string, null given
     */
    function testFilteredCalendarListWithEmptySubscriptionSourceDoesNotCrash() {
        // Create a subscription with an empty source href
        $brokenSubscription = [
            '{DAV:}displayname' => 'Broken Subscription',
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href(''),
            '{http://apple.com/ns/ical/}calendar-color' => '#FF0000FF',
            '{http://apple.com/ns/ical/}calendar-order' => '99',
            'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
            'uri' => 'broken-subscription'
        ];
        $this->caldavBackend->createSubscription(
            $brokenSubscription['principaluri'],
            $brokenSubscription['uri'],
            $brokenSubscription
        );

        // This request should NOT throw a TypeError
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f.json?sharedPublicSubscription=true',
        ));

        $response = $this->request($request);

        // Should return 200, not crash with TypeError
        $this->assertEquals(200, $response->status);

        // The broken subscription should be excluded from results (source not found)
        $jsonResponse = json_decode($response->getBodyAsString());
        $calendars = $jsonResponse->{'_embedded'}->{'dav:calendar'} ?? [];

        // Should only contain the valid subscription, not the broken one
        foreach ($calendars as $calendar) {
            $this->assertNotEquals('Broken Subscription', $calendar->{'dav:name'} ?? '');
        }
    }

    /**
     * Test that listing calendars with a subscription that has a path ending with //
     * does not crash with TypeError.
     *
     * When a path ends with //, Sabre\Uri\split() returns [NULL, NULL],
     * which causes getNodeForPath(null) to be called, triggering the error:
     * Sabre\Uri\split(): Argument #1 ($path) must be of type string, null given
     *
     * Regression test for issue #278
     */
    function testFilteredCalendarListWithTrailingDoubleSlashDoesNotCrash() {
        // Create a subscription with a path ending in //
        $brokenSubscription = [
            '{DAV:}displayname' => 'Trailing Slash Subscription',
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href('/calendars/user/cal//'),
            '{http://apple.com/ns/ical/}calendar-color' => '#FF0000FF',
            '{http://apple.com/ns/ical/}calendar-order' => '99',
            'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
            'uri' => 'trailing-slash-subscription'
        ];
        $this->caldavBackend->createSubscription(
            $brokenSubscription['principaluri'],
            $brokenSubscription['uri'],
            $brokenSubscription
        );

        // This request should NOT throw a TypeError
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f.json?sharedPublicSubscription=true',
        ));

        $response = $this->request($request);

        // Should return 200, not crash with TypeError
        $this->assertEquals(200, $response->status);

        // The broken subscription should be excluded from results
        $jsonResponse = json_decode($response->getBodyAsString());
        $calendars = $jsonResponse->{'_embedded'}->{'dav:calendar'} ?? [];

        foreach ($calendars as $calendar) {
            $this->assertNotEquals('Trailing Slash Subscription', $calendar->{'dav:name'} ?? '');
        }
    }

    /**
     * A subscription whose stored source the subscription node does not read must not be
     * listed with a source calendar. Regression test for issue #677
     */
    function testCalendarListSkipsSubscriptionsWhoseSourceTheNodeDoesNotRead() {
        $unreadSources = [
            'absolute URL' => 'http://localhost/calendars/54b64eadf6d7d8e41d263e0e/calendar2',
            'query' => 'calendars/54b64eadf6d7d8e41d263e0e/calendar2?x=1',
            'fragment' => 'calendars/54b64eadf6d7d8e41d263e0e/calendar2#f',
            'double slash' => 'calendars//54b64eadf6d7d8e41d263e0e/calendar2',
        ];
        foreach ($unreadSources as $form => $source) {
            $this->caldavBackend->createSubscription('principals/users/54b64eadf6d7d8e41d263e0f', 'sub-' . md5($form), [
                '{DAV:}displayname' => 'Unread source: ' . $form,
                '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href($source),
            ]);
        }

        $response = $this->request(\Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f.json?withRights=true',
        )));

        $this->assertEquals(200, $response->status);
        $listedNames = array_map(
            fn($calendar) => $calendar->{'dav:name'} ?? '',
            json_decode($response->getBodyAsString())->{'_embedded'}->{'dav:calendar'}
        );
        foreach (array_keys($unreadSources) as $form) {
            $this->assertNotContains('Unread source: ' . $form, $listedNames);
        }
    }

    function testGetCalendarConfiguration() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($jsonResponse->{'dav:name'}, 'Calendar');
        $this->assertEquals($jsonResponse->{'caldav:description'}, 'description');
        $this->assertEquals($jsonResponse->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/4');
        $this->assertEquals($jsonResponse->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($jsonResponse->{'apple:order'}, '2');
    }

    function testGetCalendarIgnoresUnsupportedAllEventsParameter() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json?allEvents=true',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertObjectNotHasProperty('_embedded', $jsonResponse);
        $this->assertEquals($jsonResponse->{'dav:name'}, 'Calendar');
    }

    function testCreateCalendar() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f.json',
        ));

        $calendar = [
            'id' => 'ID',
            'dav:name' => 'NAME',
            'caldav:description' => 'DESCRIPTION',
            'apple:color' => '#0190FFFF',
            'apple:order' => '99'
        ];

        $request->setBody(json_encode($calendar));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 201);

        $calendars = $this->caldavBackend->getCalendarsForUser($this->cal['principaluri']);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']) + count($this->user1Calendars['otherCalendars']), $calendars);

        $cal = $calendars[count($calendars) - 1];
        $this->assertEquals('NAME', $cal['{DAV:}displayname']);
        $this->assertEquals('DESCRIPTION', $cal['{urn:ietf:params:xml:ns:caldav}calendar-description']);
        $this->assertEquals('#0190FFFF', $cal['{http://apple.com/ns/ical/}calendar-color']);
        $this->assertEquals('99', $cal['{http://apple.com/ns/ical/}calendar-order']);
    }

    function testCreateCalendarDuplication() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f.json',
        ));

        $calendar = [
            'id' => 'ID',
            'dav:name' => 'NAME',
            'caldav:description' => 'DESCRIPTION',
            'apple:color' => '#0190FFFF',
            'apple:order' => '99'
        ];

        $calendarsBefore = $this->caldavBackend->getCalendarsForUser($this->cal['principaluri']);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $calendarsBefore);

        $request->setBody(json_encode($calendar));
        $firstCall = $this->request($request);
        $secondCall = $this->request($request);

        $this->assertEquals(201, $firstCall->status);
        $this->assertEquals(405, $secondCall->status);

        $calendarsAfter = $this->caldavBackend->getCalendarsForUser($this->cal['principaluri']);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']) + 1, $calendarsAfter);

        $cal = $calendarsAfter[count($calendarsAfter) - 1];
        $this->assertEquals('NAME', $cal['{DAV:}displayname']);
        $this->assertEquals('DESCRIPTION', $cal['{urn:ietf:params:xml:ns:caldav}calendar-description']);
        $this->assertEquals('#0190FFFF', $cal['{http://apple.com/ns/ical/}calendar-color']);
        $this->assertEquals('99', $cal['{http://apple.com/ns/ical/}calendar-order']);
    }

    function testResourceCalendarCannotBeCreatedByAnotherUser() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/62b64eadf6d7d8e41d263e0c.json',
        ));

        $calendar = [
            'id' => 'ID',
            'dav:name' => 'cal resource',
            'caldav:description' => 'DESCRIPTION',
            'apple:color' => '#0190FFFF',
            'apple:order' => '99'
        ];

        $request->setBody(json_encode($calendar));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals(403, $response->status);
    }

    function testCreateSubscription() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f.json',
        ));

        $calendar = [
            'id' => 'ID',
            'dav:name' => 'SUB NAME',
            'calendarserver:source' => [
                'href' => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json'
            ],
            'apple:color' => '#0190FFFF',
            'apple:order' => '99'
        ];

        $request->setBody(json_encode($calendar));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 201);

        $calendars = $this->caldavBackend->getSubscriptionsForUser($this->cal['principaluri']);
        $this->assertCount(2, $calendars);

        $cal = $calendars[1];
        $this->assertEquals('SUB NAME', $cal['{DAV:}displayname']);
        $this->assertEquals('calendars/54b64eadf6d7d8e41d263e0e/publicCal1', $cal['source']);
        $this->assertEquals('#0190FFFF', $cal['{http://apple.com/ns/ical/}calendar-color']);
        $this->assertEquals('99', $cal['{http://apple.com/ns/ical/}calendar-order']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unusableSubscriptionSources')]
    function testCreateSubscriptionWithUnusableSourceIsBadRequest($href) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f.json',
        ));
        $request->setBody(json_encode([
            'id' => 'sub1',
            'dav:name' => 'S',
            'calendarserver:source' => ['href' => $href]
        ]));

        $response = $this->request($request);

        $this->assertEquals(400, $response->status);
        $this->assertCount(1, $this->caldavBackend->getSubscriptionsForUser($this->cal['principaluri']));
    }

    static function unusableSubscriptionSources() {
        return [
            'url without path' => ['http://example.org'],
            'unparsable url' => ['?://'],
            'calendar home' => ['/calendars/54b64eadf6d7d8e41d263e0f'],
            'address book' => ['/addressbooks/54b64eadf6d7d8e41d263e0f/contacts']
        ];
    }

    function testCreateCalendarMissingId() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f.json',
        ));

        $calendar = [
            'id' => '',
            'dav:name' => 'NAME',
            'caldav:description' => 'DESCRIPTION',
            'apple:color' => '#0190FFFF',
            'apple:order' => '99'
        ];

        $request->setBody(json_encode($calendar));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 400);

        $calendars = $this->caldavBackend->getCalendarsForUser($this->cal['principaluri']);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $calendars);
    }

    function testCreateSubscriptionMissingId() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f.json',
        ));

        $calendar = [
            'dav:name' => 'SUB NAME',
            'calendarserver:source' => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json',
            'apple:color' => '#0190FFFF',
            'apple:order' => '99'
        ];

        $request->setBody(json_encode($calendar));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 400);

        $calendars = $this->caldavBackend->getSubscriptionsForUser($this->cal['principaluri']);
        $this->assertCount(1, $calendars);
    }

    function testDeleteCalendar() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $calendars = $this->caldavBackend->getCalendarsForUser($this->cal['principaluri']);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $calendars);

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);

        $calendars = $this->caldavBackend->getCalendarsForUser($this->cal['principaluri']);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']) - 1, $calendars);
    }

    function testDeleteCalendarsOfHome() {
        $this->authenticateAsTechnicalUser();
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));

        $calendars = $this->caldavBackend->getCalendarsForUser($this->cal['principaluri']);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $calendars);

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);

        $calendars = $this->caldavBackend->getCalendarsForUser($this->cal['principaluri']);
        $this->assertCount(0, $calendars);
    }

    function testDeleteCalendarHomeOfAnotherUserIsForbidden() {
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0e');

        $response = $this->makeRequest('DELETE', '/calendars/54b64eadf6d7d8e41d263e0f.json', null);

        $this->assertEquals(403, $response->status);
        $calendars = $this->caldavBackend->getCalendarsForUser($this->cal['principaluri']);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $calendars);
    }

    function testDeleteOwnCalendarHomeIsForbidden() {
        $this->caldavBackend->createCalendar($this->cal['principaluri'], \ESN\CalDAV\Backend\Esn::EVENTS_URI, [
            '{DAV:}displayname' => 'Calendar',
        ]);

        $response = $this->makeRequest('DELETE', '/calendars/54b64eadf6d7d8e41d263e0f', null);

        $this->assertEquals(403, $response->status);
        $this->assertContains(\ESN\CalDAV\Backend\Esn::EVENTS_URI, $this->calendarUrisOfUser1());
    }

    function testMoveOntoCalendarHomeIsForbidden() {
        $calendarCount = count($this->calendarUrisOfUser1());

        $response = $this->request(\Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'   => 'MOVE',
            'REQUEST_URI'      => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event1.ics',
            'HTTP_DESTINATION' => '/calendars/54b64eadf6d7d8e41d263e0f',
            'HTTP_OVERWRITE'   => 'T',
        ]));

        $this->assertEquals(403, $response->status);
        $this->assertCount($calendarCount, $this->calendarUrisOfUser1());
    }

    function testCopyOntoCalendarHomeIsForbidden() {
        $calendarCount = count($this->calendarUrisOfUser1());

        $response = $this->request(\Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'   => 'COPY',
            'REQUEST_URI'      => '/calendars/54b64eadf6d7d8e41d263e0e/newcal',
            'HTTP_DESTINATION' => '/calendars/./54b64eadf6d7d8e41d263e0f',
        ]));

        $this->assertEquals(403, $response->status);
        $this->assertCount($calendarCount, $this->calendarUrisOfUser1());
    }

    private function calendarUrisOfUser1() {
        return array_column($this->caldavBackend->getCalendarsForUser($this->cal['principaluri']), 'uri');
    }

    private function authenticateAsTechnicalUser() {
        // As in esn.php: the technical user holds every privilege. It has no node
        // in the principal tree of the tests, so its (empty) membership is seeded.
        $aclPlugin = $this->server->getPlugin('acl');
        $aclPlugin->adminPrincipals[] = PRINCIPALS_TECHNICAL_USER;
        (new \ReflectionProperty($aclPlugin, 'principalMembershipCache'))
            ->setValue($aclPlugin, [PRINCIPALS_TECHNICAL_USER => []]);
        $this->authBackend->setAuthTenant(new AuthTenant('technicalUser', SERVER_MOCK_DOMAIN_ID, TenantType::Technical));
    }

    function testPatchSubscription() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPPATCH',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json',
        ));
        $nameUpdated = 'subscription tested';
        $data = [ 'dav:name' => $nameUpdated ];
        $request->setBody(json_encode($data));

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);

        $subscriptions = $this->caldavBackend->getSubscriptionsForUser($this->cal['principaluri']);
        $this->assertEquals($nameUpdated, $subscriptions[0]['{DAV:}displayname']);
    }

    function testDeleteSubscription() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json',
        ));

        $subscriptions = $this->caldavBackend->getSubscriptionsForUser($this->cal['principaluri']);
        $this->assertCount(1, $subscriptions);

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);

        $subscriptions = $this->caldavBackend->getSubscriptionsForUser($this->cal['principaluri']);
        $this->assertCount(0, $subscriptions);
    }

    function testGetPublicCalendarsUser() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_ACCEPT'       => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e.json?sharedPublic=true',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);
        $calendars = $jsonResponse->_embedded->{'dav:calendar'};
        $this->assertCount(1, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/2');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');
    }

    function testGetPublicCalendarsUserWithNoPublicCalendars() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_ACCEPT'       => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f.json?sharedPublic=true',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 200);
        $this->assertSame('/calendars/54b64eadf6d7d8e41d263e0f.json', $jsonResponse->{'_links'}->self->href);
        $this->assertSame([], $jsonResponse->{'_embedded'}->{'dav:calendar'});
    }

    function testGetPublicCalendarsUserWithDelegatePublicCalendar() {
        $delegationRequest = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json',
        ));

        $sharees = [
            'share' => [
                'set' => [
                    [
                        'dav:href' => 'mailto:johndoe2@example.org',
                        'dav:read' => true
                    ]
                ]
            ]
        ];

        $delegationRequest->setBody(json_encode($sharees));
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0e');
        $response = $this->request($delegationRequest);
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0f');

        $this->assertEquals(200, $response->status);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_ACCEPT'       => 'application/json',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0d.json?sharedPublic=true',
        ));

        $response2 = $this->request($request);
        $jsonResponse = json_decode($response2->getBodyAsString());

        $this->assertEquals($response2->status, 200);
        $this->assertSame('/calendars/54b64eadf6d7d8e41d263e0d.json', $jsonResponse->{'_links'}->self->href);
        $this->assertSame([], $jsonResponse->{'_embedded'}->{'dav:calendar'});
    }

    function makeRequest($method, $uri, $body) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => $method,
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => $uri,
        ));

        if ($body) {
            $request->setBody(json_encode($body));
        }

        return $this->request($request);
    }

    function testGetSubscriptionOfDeletedCalendar() {
        $publicCaldavCalendar = array(
            '{DAV:}displayname' => 'Calendar',
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
            '{http://apple.com/ns/ical/}calendar-color' => '#0190FFFF',
            '{http://apple.com/ns/ical/}calendar-order' => '2',
            'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0e',
            'uri' => 'publicCalToRemove',
        );

        $calendarInfo = [];
        $calendarInfo['principaluri'] = $publicCaldavCalendar['principaluri'];
        $calendarInfo['uri'] = $publicCaldavCalendar['uri'];

        // Create calendar
        $publicCaldavCalendar['id'] = $this->caldavBackend->createCalendar($publicCaldavCalendar['principaluri'], $publicCaldavCalendar['uri'], $publicCaldavCalendar);
        $this->caldavBackend->saveCalendarPublicRight($publicCaldavCalendar['id'], '{DAV:}read', $calendarInfo);

        $subscriptionBody = [
            'id' => 'publicCalToRemoveSubscription',
            'dav:name' => 'SUB NAME',
            'calendarserver:source' => [
                'href' => '/calendars/54b64eadf6d7d8e41d263e0e/publicCalToRemove.json'
            ],
            'apple:color' => '#0190FFFF',
            'apple:order' => '99'
        ];

        // Subscribe to calendar
        $subscriptionResponse = $this->makeRequest('POST', '/calendars/54b64eadf6d7d8e41d263e0f.json', $subscriptionBody);

        $this->assertEquals($subscriptionResponse->status, 201);

        $getAllCalendarResponse = $this->makeRequest('GET', '/calendars/54b64eadf6d7d8e41d263e0f.json?withRights=true', null);
        $jsonResponse = json_decode($getAllCalendarResponse->getBodyAsString());
        $allCalendarsAfterSubscription = count($jsonResponse->{'_embedded'}->{'dav:calendar'});

        // Delete Original calendar
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0e');
        $deleteResponse = $this->makeRequest('DELETE', '/calendars/54b64eadf6d7d8e41d263e0e/'.$publicCaldavCalendar['uri'].'.json', null);
        $this->assertEquals($deleteResponse->status, 204);

        // Check if Subscriptions to calendar have been deleted
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0f');
        $getSubscriptionResponse = $this->makeRequest('GET', '/calendars/54b64eadf6d7d8e41d263e0f/publicCalToRemoveSubscription.json?withRights=true', null);
        $this->assertEquals($getSubscriptionResponse->status, 404);

        $getAllUserCalendarResponse = $this->makeRequest('GET', '/calendars/54b64eadf6d7d8e41d263e0f.json?withRights=true', null);
        $jsonResponse = json_decode($getAllUserCalendarResponse->getBodyAsString());
        $allCalendarsAfterRemoveSource = $jsonResponse->{'_embedded'}->{'dav:calendar'};

        $this->assertCount($allCalendarsAfterSubscription - 1, $allCalendarsAfterRemoveSource);
    }

    function testDeleteMainCalendar() {
        $defaultUri = \ESN\CalDAV\Backend\Esn::EVENTS_URI;

        $mainCalDavCalendar = array(
            '{DAV:}displayname' => 'Calendar',
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
            '{http://apple.com/ns/ical/}calendar-color' => '#0190FFFF',
            '{http://apple.com/ns/ical/}calendar-order' => '2',
            'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
            'uri' => $defaultUri,
        );

        $this->caldavBackend->createCalendar($mainCalDavCalendar['principaluri'], $mainCalDavCalendar['uri'], $mainCalDavCalendar);
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/'.$defaultUri.'.json',
        ));

        $calendars = $this->caldavBackend->getCalendarsForUser($mainCalDavCalendar['principaluri']);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']) + count($this->user1Calendars['otherCalendars']), $calendars);

        $response = $this->request($request);
        $this->assertEquals(403, $response->status);

        $calendars = $this->caldavBackend->getCalendarsForUser($mainCalDavCalendar['principaluri']);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']) + count($this->user1Calendars['otherCalendars']), $calendars);
    }

    function testDeleteCalendarOfUserAsTechnicalUser() {
        $this->authenticateAsTechnicalUserOfUsersDomain();

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/' . $this->cal['uri'] . '.json',
        ));
        $response = $this->request($request);

        $this->assertEquals(204, $response->status);
    }

    function testDeleteMainCalendarOfUserAsTechnicalUserIsForbidden() {
        $mainCalendarUri = '54b64eadf6d7d8e41d263e0f';
        $this->caldavBackend->createCalendar('principals/users/54b64eadf6d7d8e41d263e0f', $mainCalendarUri, array(
            '{DAV:}displayname' => 'Main',
        ));
        $this->authenticateAsTechnicalUserOfUsersDomain();

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/' . $mainCalendarUri . '.json',
        ));
        $response = $this->request($request);

        $this->assertEquals(403, $response->status);
    }

    function testDeleteWrongCollection() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar2.json'
        ));

        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testDeleteUnknown() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar.jaysun'
        ));

        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testDeleteWrongNode() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/unsupportednode/54b64eadf6d7d8e41d263e0f/resource.json',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testPatchCalendar() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPPATCH',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $data = [ 'dav:name' => 'tested' ];
        $request->setBody(json_encode($data));

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);
    }

    function testPatchCalendarRejectsNameWithXmlIllegalCharacter() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPPATCH',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $data = [ 'dav:name' => "My\x01calendar" ];
        $request->setBody(json_encode($data));

        $response = $this->request($request);
        $this->assertEquals(400, $response->status);
    }

    public static function illTypedCalendarPropertyCases(): array {
        return [
            'array name' => ['dav:name', []],
            'object description' => ['caldav:description', new \stdClass()],
            'float color' => ['apple:color', 1.5],
            'boolean color' => ['apple:color', true],
            'object order' => ['apple:order', new \stdClass()],
            'non numeric order' => ['apple:order', 'first'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('illTypedCalendarPropertyCases')]
    function testPatchCalendarRejectsIllTypedProperty($jsonProp, $value) {
        $calendarsBefore = $this->caldavBackend->getCalendarsForUser($this->cal['principaluri']);

        $response = $this->makeRequest('PROPPATCH', '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json', [ $jsonProp => $value ]);

        $this->assertEquals(400, $response->status);
        $this->assertEquals($calendarsBefore, $this->caldavBackend->getCalendarsForUser($this->cal['principaluri']));
    }

    function testPatchCalendarAcceptsIntegerOrder() {
        $response = $this->makeRequest('PROPPATCH', '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json', [ 'apple:order' => 3 ]);

        $this->assertEquals(204, $response->status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('illTypedCalendarPropertyCases')]
    function testCreateCalendarRejectsIllTypedProperty($jsonProp, $value) {
        $response = $this->makeRequest('POST', '/calendars/54b64eadf6d7d8e41d263e0f.json', [ 'id' => 'ID', $jsonProp => $value ]);

        $this->assertEquals(400, $response->status);
        $calendars = $this->caldavBackend->getCalendarsForUser($this->cal['principaluri']);
        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $calendars);
    }

    function testPatchSubscriptionRejectsIllTypedColor() {
        $subscriptionsBefore = $this->caldavBackend->getSubscriptionsForUser($this->cal['principaluri']);

        $response = $this->makeRequest('PROPPATCH', '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json', [ 'apple:color' => new \stdClass() ]);

        $this->assertEquals(400, $response->status);
        $this->assertEquals($subscriptionsBefore, $this->caldavBackend->getSubscriptionsForUser($this->cal['principaluri']));
    }

    function testCreateSubscriptionRejectsIllTypedName() {
        $response = $this->makeRequest('POST', '/calendars/54b64eadf6d7d8e41d263e0f.json', [
            'id' => 'ID',
            'dav:name' => [],
            'calendarserver:source' => [
                'href' => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json'
            ]
        ]);

        $this->assertEquals(400, $response->status);
        $this->assertCount(1, $this->caldavBackend->getSubscriptionsForUser($this->cal['principaluri']));
    }

    function testPatchCalendarReadonlyProp() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPPATCH',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $data = [ 'dav:getetag' => 'no' ];
        $request->setBody(json_encode($data));

        $response = $this->request($request);
        $this->assertEquals(403, $response->status);
    }

    function testPatchWrongCollection() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPPATCH',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar2.json'
        ));

        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testPatchUnknown() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPPATCH',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar.jaysun'
        ));

        $data = [ 'dav:name' => 'tested' ];
        $request->setBody(json_encode($data));

        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testPatchWrongNode() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPPATCH',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/unsupportednode/54b64eadf6d7d8e41d263e0f/resource.json',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testPropFindRequestCalendarSharingMultipleProp() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $body = '{"prop": ["acl", "cs:invite"]}';
        $request->setBody($body);
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(200, $response->status);
        $this->assertTrue(is_array($jsonResponse['invite']));
        $this->assertTrue(is_array($jsonResponse['acl']));
    }

    function testPropFindRequestCalendarSharingInvites() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $body = '{"prop": ["cs:invite"]}';
        $request->setBody($body);
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(200, $response->status);
        $this->assertTrue(is_array($jsonResponse['invite']));

        $this->assertEquals($jsonResponse['invite'][0]['href'], 'principals/users/54b64eadf6d7d8e41d263e0f');
        $this->assertEquals($jsonResponse['invite'][0]['principal'], 'principals/users/54b64eadf6d7d8e41d263e0f');
        $this->assertEquals($jsonResponse['invite'][0]['properties'], array());
        $this->assertEquals($jsonResponse['invite'][0]['access'], 1);
        $this->assertEquals($jsonResponse['invite'][0]['comment'], '');
        $this->assertEquals($jsonResponse['invite'][0]['inviteStatus'], 2);
    }

    function testPropFindRequestCalendarSharingMultipleACL() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $body = '{"prop": ["acl"]}';
        $request->setBody($body);
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(200, $response->status);
        $this->assertTrue(is_array($jsonResponse['acl']));

        $shared = array();
        $write = array();
        $write_properties = array();
        $read = array();
        $read_free_busy = array();
        foreach($jsonResponse['acl'] as $acl) {
            $this->assertEquals($acl['protected'], 1);
            if(strcmp($acl['privilege'], '{DAV:}share') == 0) {
                array_push($shared, $acl['principal']);
            }
            else if(strcmp($acl['privilege'], '{DAV:}write') == 0) {
                array_push($write, $acl['principal']);
            }
            else if(strcmp($acl['privilege'], '{DAV:}write-properties') == 0) {
                array_push($write_properties, $acl['principal']);
            }
            else if(strcmp($acl['privilege'], '{DAV:}read') == 0) {
                array_push($read, $acl['principal']);
            }
            else if(strcmp($acl['privilege'], '{urn:ietf:params:xml:ns:caldav}read-free-busy') == 0) {
                array_push($read_free_busy, $acl['principal']);
            }
        }

        $this->assertEquals($shared, array('principals/users/54b64eadf6d7d8e41d263e0f', 'principals/users/54b64eadf6d7d8e41d263e0f/calendar-proxy-write'));
        $this->assertEquals($write, array('principals/users/54b64eadf6d7d8e41d263e0f', 'principals/users/54b64eadf6d7d8e41d263e0f/calendar-proxy-write'));
        $this->assertEquals($write_properties, array('principals/users/54b64eadf6d7d8e41d263e0f', 'principals/users/54b64eadf6d7d8e41d263e0f/calendar-proxy-write'));
        $this->assertEquals($read, array('principals/users/54b64eadf6d7d8e41d263e0f', 'principals/users/54b64eadf6d7d8e41d263e0f/calendar-proxy-read', 'principals/users/54b64eadf6d7d8e41d263e0f/calendar-proxy-write'));
        $this->assertEquals($read_free_busy, array('{DAV:}authenticated'));
    }

    function testCalendarUpdateShareesAdd() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $sharees = [
            'share' => [
                'set' => [
                    [
                        'dav:href'       => 'mailto:johndoe@example.org',
                        'common-name'    => 'With John Doe',
                        'summary'        => 'Delegation',
                        'dav:read-write' => true
                    ],
                    [
                        'dav:href' => 'mailto:johndoe2@example.org',
                        'dav:read' => true
                    ],
                    [
                        'dav:href' => 'mailto:johndoe3@example.org',
                        'dav:administration' => true
                    ],
                    [
                        'dav:href' => 'mailto:johndoe4@example.org',
                        'dav:freebusy' => true
                    ]
                ],
                'remove' => [
                    [
                        'dav:href' => 'mailto:janedoe@example.org',
                    ]
                ]
            ]
        ];

        $request->setBody(json_encode($sharees));
        $response = $this->request($request);

        $this->assertEquals(200, $response->status);

        $sharees = $this->caldavBackend->getInvites($this->cal['id']);
        $this->assertEquals(count($sharees), 3);
        $this->assertEquals($sharees[1]->href, 'mailto:johndoe@example.org');
        $this->assertEquals($sharees[1]->properties['{DAV:}displayname'], 'With John Doe');
        $this->assertEquals($sharees[1]->access, 3);
        $this->assertEquals($sharees[2]->href, 'mailto:johndoe2@example.org');
        $this->assertEquals($sharees[2]->access, 2);
    }

    public static function illTypedShareeCases(): array {
        return [
            'boolean common-name' => ['common-name', true],
            'object summary' => ['summary', new \stdClass()],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('illTypedShareeCases')]
    function testCalendarUpdateShareesRejectsIllTypedProperty($jsonProp, $value) {
        $inviteCountBefore = count($this->caldavBackend->getInvites($this->cal['id']));

        $response = $this->makeRequest('POST', '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json', [
            'share' => [
                'set' => [
                    [
                        'dav:href'       => 'mailto:johndoe@example.org',
                        'dav:read-write' => true,
                        $jsonProp        => $value
                    ]
                ]
            ]
        ]);

        $this->assertEquals(400, $response->status);
        $this->assertCount($inviteCountBefore, $this->caldavBackend->getInvites($this->cal['id']));
    }

    function testCalendarUpdateShareesToOwnerReturnsBadRequest() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $sharees = [
            'share' => [
                'set' => [
                    [
                        'dav:href' => 'mailto:robertocarlos@realmadrid.com',
                        'dav:read' => true
                    ]
                ]
            ]
        ];

        $before = $this->caldavBackend->getInvites($this->cal['id']);

        $request->setBody(json_encode($sharees));
        $response = $this->request($request);

        $this->assertEquals(400, $response->status);

        $after = $this->caldavBackend->getInvites($this->cal['id']);

        $this->assertCount(count($before), $after);
        $this->assertEquals('principals/users/54b64eadf6d7d8e41d263e0f', $after[0]->principal);
        $this->assertEquals(\Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER, $after[0]->access);
    }

    function testCalendarUpdateInviteStatus() {
        $this->authBackend->setAuthTenant(new AuthTenant('54b64eadf6d7d8e41d263e0e', SERVER_MOCK_DOMAIN_ID));

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json',
        ));

        $invitereply = [
            'invite-reply' => [
                'invitestatus' => 'noresponse'
            ]
        ];

        $request->setBody(json_encode($invitereply));
        $response = $this->request($request);

        $this->assertEquals(200, $response->status);

        $calendars = $this->caldavBackend->getCalendarsForUser('principals/users/54b64eadf6d7d8e41d263e0e');

        $this->assertCount(2, $calendars);

        $this->assertEquals(2, $calendars[0]['share-invitestatus']);
        $this->assertEquals('calendar2', $calendars[0]['uri']);
        $this->assertEquals(1, $calendars[1]['share-invitestatus']);
        $this->assertEquals('publicCal1', $calendars[1]['uri']);
    }

    function testUIDQueryShouldReturn400WhenUIDIsMissing() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));
        $response = $this->request($request);

        $this->assertEquals($response->status, 400);
    }

    function testUIDQueryShouldReturn404WhenEventDoesNotExist() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));

        $request->setBody(json_encode([ 'uid' => 'CertainlyDoesNotExist' ]));
        $response = $this->request($request);

        $this->assertEquals($response->status, 404);
    }

    function testUIDQueryShouldReturnOneEvent() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));

        $request->setBody(json_encode($this->uidQueryData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $items = $jsonResponse->_embedded->{'dav:item'};
        $this->assertCount(1, $items);

        $vcalendar = \Sabre\VObject\Reader::readJson($items[0]->{'data'});
        $vevents = $vcalendar->select('VEVENT');

        $this->assertCount(1, $vevents);
        $this->assertEquals('Monday 0h', $vevents[0]->SUMMARY);
    }

    function testUIDQueryShouldReturnOneRecurringEventWithNoRecurrenceIdOnMasterEvent() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));

        $request->setBody(json_encode($this->uidQueryDataRecur));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $items = $jsonResponse->_embedded->{'dav:item'};
        $this->assertCount(1, $items);

        $vcalendar = \Sabre\VObject\Reader::readJson($items[0]->{'data'});
        $vevents = $vcalendar->select('VEVENT');

        $this->assertCount(2, $vevents);
        $this->assertTrue(!$vevents[0]->{'RECURRENCE-ID'});
        $this->assertTrue(!!$vevents[1]->{'RECURRENCE-ID'});
    }

    function testACLShouldReturn404IfCalendarDoesNotExist() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ACL',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/unknownCalendar.json',
        ));

        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testACLShouldReturn400IfBodyIsBadlyFormatted() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ACL',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode(['key' => 'value']));
        $response = $this->request($request);

        $this->assertEquals($response->status, 400);
    }

    function testACLShouldReturn412IfPrivilegeIsNotSupported() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ACL',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode(['public_right' => '{DAV}:doesnotexist']));
        $response = $this->request($request);

        $this->assertEquals($response->status, 412);
    }

    public static function unsupportedPublicRights(): array {
        return [
            'share' => ['{DAV:}share'],
            'all' => ['{DAV:}all'],
            'write-acl' => ['{DAV:}write-acl'],
            'unbind' => ['{DAV:}unbind'],
            'write-properties' => ['{DAV:}write-properties'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedPublicRights')]
    function testACLRejectsPrivilegesThatAreNotPublicRights($privilege) {
        $response = $this->changePublicRight($privilege);

        $this->assertEquals(412, $response->status);
    }

    public static function supportedPublicRights(): array {
        return [
            'private' => [''],
            'free-busy' => ['{urn:ietf:params:xml:ns:caldav}read-free-busy'],
            'read' => ['{DAV:}read'],
            'write' => ['{DAV:}write'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('supportedPublicRights')]
    function testACLAcceptsPublicRights($privilege) {
        $response = $this->changePublicRight($privilege);

        $this->assertEquals(200, $response->status);
    }

    private function changePublicRight($privilege) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ACL',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));
        $request->setBody(json_encode(['public_right' => $privilege]));

        return $this->request($request);
    }

    function testACLShouldReturn200WithModifiedPrivileges() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ACL',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode(['public_right' => '{DAV:}read']));
        $response = $this->request($request);

        $this->assertEquals($response->status, 200);
        $publicACE = null;
        foreach (json_decode($response->body) as &$ace) {
            if ($ace->principal === '{DAV:}authenticated') {
                $publicACE = $ace;
            }
        }
        $this->assertEquals($publicACE->privilege, '{DAV:}read');
    }

    function testAddressBookACLShouldReturn404IfAddressBookDoesNotExist() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ACL',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/unknownAddressBook.json',
        ));

        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testGetMultipleCalendarObjectsFromPathsAll200s() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars',
        ));

        $requestBody = array_replace([], $this->getMultipleCalendarObjectsFromPathsRequestBody);
        $request->setBody(json_encode($requestBody));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());
        $items = $jsonResponse->_embedded->{'dav:item'};
        $this->assertCount(count($this->getMultipleCalendarObjectsFromPathsRequestBody['eventPaths']), $items);
        $this->assertEquals(200, $items[0]->status);
    }

    function testGetMultipleCalendarObjectsFromPathsAll200sWith404sStripped() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars',
        ));

        $requestBody = array_replace([], $this->getMultipleCalendarObjectsFromPathsRequestBody);
        $requestBody['eventPaths'][count($requestBody['eventPaths']) - 1] = '/calendars/54b64eadf6d7d8e41d263e0f/user1Calendar2/nonExistentEvent.ics';
        $request->setBody(json_encode($requestBody));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals($response->status, 207);
        $items = $jsonResponse->_embedded->{'dav:item'};
        $this->assertCount(count($this->getMultipleCalendarObjectsFromPathsRequestBody['eventPaths']) - 1, $items);
        foreach($items as $item) {
            $this->assertEquals(200, $item->status);
        }
    }

    function testGetMultipleCalendarObjectsFromPathsShouldReturn404WhenOneCalendarNotFound() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars',
        ));

        $requestBody = array_replace([], $this->getMultipleCalendarObjectsFromPathsRequestBody);
        $requestBody['eventPaths'][] = '/calendars/54b64eadf6d7d8e41d263e0f/nonExistentCalendar/event1.ics';
        $request->setBody(json_encode($requestBody));
        $response = $this->request($request);

        $this->assertEquals($response->status, 404);
    }

    function testAddressBookACLShouldReturn400IfBodyIsEmpty() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ACL',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $body = '';

        $request->setBody($body);
        $response = $this->request($request);

        $this->assertEquals($response->status, 400);
    }

    function testSyncTokenInitialSync() {
        // Test initial sync (no sync-token provided = empty string)
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode(['sync-token' => '']));
        $response = $this->request($request);

        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());

        // Should return all events
        $this->assertTrue(isset($jsonResponse->_embedded->{'dav:item'}));
        $items = $jsonResponse->_embedded->{'dav:item'};
        $this->assertGreaterThan(0, count($items));

        // All items should have status 200 (not deleted)
        foreach ($items as $item) {
            $this->assertEquals(200, $item->status);
        }

        // Should return a new sync-token
        $this->assertTrue(isset($jsonResponse->{'sync-token'}));
        $this->assertStringContainsString('http://sabre.io/ns/sync/', $jsonResponse->{'sync-token'});
    }

    function testSyncTokenIncrementalSync() {
        // First, get initial sync-token
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode(['sync-token' => '']));
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $syncToken = $jsonResponse->{'sync-token'};

        // Add a new event
        $calendars = $this->caldavBackend->getCalendarsForUser('principals/users/54b64eadf6d7d8e41d263e0f');
        $calendarId = null;
        foreach ($calendars as $calendar) {
            if ($calendar['uri'] === 'calendar1') {
                $calendarId = $calendar['id'];
                break;
            }
        }

        $newEvent = 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:sync-new-event
DTSTART:20151201T100000Z
DTEND:20151201T110000Z
SUMMARY:New Event for Sync
DTSTAMP:20151201T100000Z
END:VEVENT
END:VCALENDAR
';
        $this->caldavBackend->createCalendarObject($calendarId, 'sync-new.ics', $newEvent);

        // Now do incremental sync
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode(['sync-token' => $syncToken]));
        $response = $this->request($request);

        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());

        // Should return only the new event
        $items = $jsonResponse->_embedded->{'dav:item'};
        $this->assertCount(1, $items);
        $this->assertEquals(200, $items[0]->status);
        $this->assertStringContainsString('sync-new.ics', $items[0]->{'_links'}->{'self'}->{'href'});

        // Should return a new sync-token
        $this->assertTrue(isset($jsonResponse->{'sync-token'}));
        $this->assertNotEquals($syncToken, $jsonResponse->{'sync-token'});
    }

    function testSyncTokenDeletedEvent() {
        // Get initial sync-token
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode(['sync-token' => '']));
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $syncToken = $jsonResponse->{'sync-token'};

        // Delete an event
        $calendars = $this->caldavBackend->getCalendarsForUser('principals/users/54b64eadf6d7d8e41d263e0f');
        $calendarId = null;
        foreach ($calendars as $calendar) {
            if ($calendar['uri'] === 'calendar1') {
                $calendarId = $calendar['id'];
                break;
            }
        }

        // Delete event1.ics
        $this->caldavBackend->deleteCalendarObject($calendarId, 'event1.ics');

        // Do incremental sync
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode(['sync-token' => $syncToken]));
        $response = $this->request($request);

        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());

        // Should return the deleted event with status 404
        $items = $jsonResponse->_embedded->{'dav:item'};
        $deletedItem = null;
        foreach ($items as $item) {
            if (strpos($item->{'_links'}->{'self'}->{'href'}, 'event1.ics') !== false) {
                $deletedItem = $item;
                break;
            }
        }

        $this->assertNotNull($deletedItem);
        $this->assertEquals(404, $deletedItem->status);
        $this->assertFalse(isset($deletedItem->etag));
        $this->assertFalse(isset($deletedItem->data));
    }

    function testSyncTokenFutureToken() {
        // Test with future sync-token (should return empty list, not error)
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        // Use a very high token number in the future
        $request->setBody(json_encode(['sync-token' => 'http://sabre.io/ns/sync/999999999']));
        $response = $this->request($request);

        // Should return 207 with empty changes (future token is valid, just no changes yet)
        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());
        $items = $jsonResponse->_embedded->{'dav:item'};

        // Should return empty list for future token
        $this->assertCount(0, $items);

        // Should still return a sync-token
        $this->assertTrue(isset($jsonResponse->{'sync-token'}));
    }

    function testSyncTokenUrlFormat() {
        // Test that sync-token can be provided in URL format
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        // Provide sync-token in URL format (should extract the numeric part)
        $request->setBody(json_encode(['sync-token' => 'http://example.com/sync/1']));
        $response = $this->request($request);

        // Should succeed (not return 400)
        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertTrue(isset($jsonResponse->{'sync-token'}));
    }

    function testSyncTokenUrlFormatWithTrailingSlash() {
        // Test that sync-token with trailing slash is handled correctly
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        // Provide sync-token with trailing slash (should still extract the numeric part)
        $request->setBody(json_encode(['sync-token' => 'http://sabre.io/ns/sync/1/']));
        $response = $this->request($request);

        // Should succeed (not return 400)
        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertTrue(isset($jsonResponse->{'sync-token'}));

        // Should not trigger initial sync (would have all items)
        // With token 1, we should get changes since token 1
        $items = $jsonResponse->_embedded->{'dav:item'};
        // The number of items depends on what changes happened since token 1
        // Just verify we got a response structure, not necessarily empty
        $this->assertTrue(is_array($items));
    }

    private function subscriptionSyncTokenRequest($syncToken) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json',
        ));
        $request->setBody(json_encode(['sync-token' => $syncToken]));

        return $this->request($request);
    }

    function testSyncTokenOnSubscriptionListsSourceCalendarObjects() {
        $response = $this->subscriptionSyncTokenRequest('');

        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());
        $items = $jsonResponse->_embedded->{'dav:item'};
        $this->assertCount(1, $items);
        $this->assertEquals(200, $items[0]->status);
        $this->assertEquals('/calendars/54b64eadf6d7d8e41d263e0f/subscription1/privateRecurEvent.ics', $items[0]->_links->self->href);
        $this->assertStringContainsString('http://sabre.io/ns/sync/', $jsonResponse->{'sync-token'});
    }

    function testSyncTokenOnSubscriptionReturnsSourceCalendarChanges() {
        $syncToken = json_decode($this->subscriptionSyncTokenRequest('')->getBodyAsString())->{'sync-token'};

        $this->caldavBackend->deleteCalendarObject($this->publicCal['id'], 'privateRecurEvent.ics');

        $response = $this->subscriptionSyncTokenRequest($syncToken);

        $this->assertEquals(207, $response->status);

        $items = json_decode($response->getBodyAsString())->_embedded->{'dav:item'};
        $this->assertCount(1, $items);
        $this->assertEquals(404, $items[0]->status);
        $this->assertEquals('/calendars/54b64eadf6d7d8e41d263e0f/subscription1/privateRecurEvent.ics', $items[0]->_links->self->href);
    }

    function testExpandEventWithTimeRange() {
        // Test expanding a single event with time-range
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event2.ics',
        ));

        $timeRangeData = [
            'match' => [
                'start' => '20130301T000000Z',
                'end' => '20130501T000000Z'
            ]
        ];

        $request->setBody(json_encode($timeRangeData));
        $response = $this->request($request);

        $this->assertEquals(200, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());

        // Should have data field with the event
        $this->assertTrue(isset($jsonResponse->data));
        $this->assertTrue(isset($jsonResponse->etag));
        $this->assertTrue(isset($jsonResponse->_links->self));

        // Verify the event is present in the response
        $vcalendar = \Sabre\VObject\Reader::readJson(json_encode($jsonResponse->data));
        $vevents = $vcalendar->select('VEVENT');
        $this->assertCount(1, $vevents);
        $this->assertEquals('Event 2', (string)$vevents[0]->SUMMARY);
    }

    function testExpandEventRecurringWithTimeRange() {
        // Test expanding a recurring event with time-range
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/recur.ics',
        ));

        // Request a 3-day window for the recurring event
        $timeRangeData = [
            'match' => [
                'start' => '20150227T000000Z',
                'end' => '20150302T000000Z'
            ]
        ];

        $request->setBody(json_encode($timeRangeData));
        $response = $this->request($request);

        $this->assertEquals(200, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());

        // Should have data field with the expanded event
        $this->assertTrue(isset($jsonResponse->data));
        $this->assertTrue(isset($jsonResponse->etag));

        // Verify the event is expanded (should have multiple occurrences)
        $vcalendar = \Sabre\VObject\Reader::readJson(json_encode($jsonResponse->data));
        $vevents = $vcalendar->select('VEVENT');

        // Should have 3 occurrences for the 3-day window (Feb 27, 28, Mar 1)
        $this->assertCount(3, $vevents);
    }

    function testExpandEventWithoutTimeRangeShouldReturn400() {
        // Test that requesting an event without time-range returns 400
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event2.ics',
        ));

        // No time-range parameters
        $request->setBody(json_encode([]));
        $response = $this->request($request);

        // Should return 400 Bad Request
        $this->assertEquals(400, $response->status);
    }

    function testExpandEventWithPartialTimeRangeShouldReturn400() {
        // Test that requesting an event with only start date returns 400
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event2.ics',
        ));

        // Only start, missing end
        $timeRangeData = [
            'match' => [
                'start' => '20130301T000000Z'
            ]
        ];

        $request->setBody(json_encode($timeRangeData));
        $response = $this->request($request);

        // Should return 400 Bad Request
        $this->assertEquals(400, $response->status);
    }

    function testExpandEventOutsideTimeRangeShouldReturnEmpty() {
        // Test that an event outside the time-range returns empty VEVENT array
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event2.ics',
        ));

        // event2 is on 2013-04-01, request a time-range in 2012 (before the event)
        $timeRangeData = [
            'match' => [
                'start' => '20120101T000000Z',
                'end' => '20121231T235959Z'
            ]
        ];

        $request->setBody(json_encode($timeRangeData));
        $response = $this->request($request);

        $this->assertEquals(200, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());

        // Should have data field but no VEVENT (event is outside time-range)
        $this->assertTrue(isset($jsonResponse->data));

        $vcalendar = \Sabre\VObject\Reader::readJson(json_encode($jsonResponse->data));
        $vevents = $vcalendar->select('VEVENT');

        // Should have 0 events since event2 is outside the requested time-range
        $this->assertCount(0, $vevents);
    }

    function testExpandEventWithoutVeventShouldNotFail() {
        // Issue #607: an object whose expansion yields no VEVENT and which has no VEVENT at all
        // (VTODO only) must not raise "foreach() argument must be of type array|object, null given"
        $ics = 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VTODO
UID:todo-only
DTSTAMP:20150101T000000Z
SUMMARY:Todo only
END:VTODO
END:VCALENDAR
';
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'todo-only.ics', $ics);

        $response = $this->expandEventRequest('todo-only.ics', '20350101T000000Z', '20351201T000000Z');

        $this->assertEquals(200, $response->status);
        $this->assertCount(0, $this->vEventsOfExpandResponse($response));
    }

    function testRemoveEventsOutsideTimeRangeShouldKeepEventWithoutDtstart() {
        // Issue #607: a VEVENT without DTSTART must not be dereferenced during post-filtering.
        // Such an object can neither be stored nor expanded, hence the post-filter is exercised directly.
        $vObject = \Sabre\VObject\Reader::read('BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:no-dtstart
DTSTAMP:20150101T000000Z
SUMMARY:No dtstart
END:VEVENT
END:VCALENDAR
');
        $handler = new CalDAV\CalendarObjectHandler($this->server, null);
        $removeEventsOutsideTimeRange = new \ReflectionMethod($handler, 'removeEventsOutsideTimeRange');

        $removeEventsOutsideTimeRange->invoke($handler, $vObject,
            new \DateTime('2035-01-01T00:00:00Z'), new \DateTime('2035-12-01T00:00:00Z'));

        $this->assertCount(1, $vObject->select('VEVENT'));
    }

    private function expandEventRequest($eventUri, $start, $end) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/' . $eventUri,
        ));
        $request->setBody(json_encode(['match' => ['start' => $start, 'end' => $end]]));

        return $this->request($request);
    }

    private function vEventsOfExpandResponse($response) {
        $jsonResponse = json_decode($response->getBodyAsString());
        $vcalendar = \Sabre\VObject\Reader::readJson(json_encode($jsonResponse->data));

        return $vcalendar->select('VEVENT');
    }

    private function addressBookSyncTokenRequest($syncToken) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $request->setBody(json_encode(['sync-token' => $syncToken]));
        return $this->request($request);
    }

    function testAddressBookSyncTokenInitialSync() {
        $response = $this->addressBookSyncTokenRequest('');

        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertTrue(isset($jsonResponse->_embedded->{'dav:item'}));
        $items = $jsonResponse->_embedded->{'dav:item'};
        $this->assertCount(count($this->carddavCards), $items);

        foreach ($items as $item) {
            $this->assertEquals(200, $item->status);
        }

        $this->assertTrue(isset($jsonResponse->{'sync-token'}));
        $this->assertStringContainsString('http://sabre.io/ns/sync/', $jsonResponse->{'sync-token'});
    }

    function testAddressBookSyncTokenIncrementalSync() {
        $response = $this->addressBookSyncTokenRequest('');
        $syncToken = json_decode($response->getBodyAsString())->{'sync-token'};

        $this->carddavBackend->createCard(
            $this->carddavAddressBook['id'],
            'sync-new.vcf',
            "BEGIN:VCARD\r\nFN:New\r\nEND:VCARD\r\n"
        );

        $response = $this->addressBookSyncTokenRequest($syncToken);

        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());
        $items = $jsonResponse->_embedded->{'dav:item'};

        $this->assertCount(1, $items);
        $this->assertEquals(200, $items[0]->status);
        $this->assertStringContainsString('sync-new.vcf', $items[0]->{'_links'}->{'self'}->{'href'});

        $this->assertTrue(isset($jsonResponse->{'sync-token'}));
        $this->assertNotEquals($syncToken, $jsonResponse->{'sync-token'});
    }

    function testAddressBookSyncTokenUpdate() {
        $response = $this->addressBookSyncTokenRequest('');
        $syncToken = json_decode($response->getBodyAsString())->{'sync-token'};

        $this->carddavBackend->updateCard(
            $this->carddavAddressBook['id'],
            'card1',
            "BEGIN:VCARD\r\nFN:Updated\r\nEND:VCARD\r\n"
        );

        $response = $this->addressBookSyncTokenRequest($syncToken);

        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());
        $items = $jsonResponse->_embedded->{'dav:item'};

        $this->assertCount(1, $items);
        $this->assertEquals(200, $items[0]->status);
        $this->assertStringContainsString('card1', $items[0]->{'_links'}->{'self'}->{'href'});
    }

    function testAddressBookSyncTokenDelete() {
        $response = $this->addressBookSyncTokenRequest('');
        $syncToken = json_decode($response->getBodyAsString())->{'sync-token'};

        $this->carddavBackend->deleteCard($this->carddavAddressBook['id'], 'card1');

        $response = $this->addressBookSyncTokenRequest($syncToken);

        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());
        $items = $jsonResponse->_embedded->{'dav:item'};

        $this->assertCount(1, $items);
        $this->assertEquals(404, $items[0]->status);
        $this->assertStringContainsString('card1', $items[0]->{'_links'}->{'self'}->{'href'});
    }

    function testAddressBookSyncTokenNoUpdate() {
        // A future sync-token: no changes happened since, expect an empty list.
        $response = $this->addressBookSyncTokenRequest('http://sabre.io/ns/sync/999999999');

        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertCount(0, $jsonResponse->_embedded->{'dav:item'});
        $this->assertTrue(isset($jsonResponse->{'sync-token'}));
    }

    function testAddressBookSyncTokenUrlFormat() {
        // sync-token provided in URL format must be extracted to its numeric part.
        $response = $this->addressBookSyncTokenRequest('http://sabre.io/ns/sync/1');

        $this->assertEquals(207, $response->status);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertTrue(isset($jsonResponse->{'sync-token'}));
    }

    function testAddressBookSyncTokenInvalidReturns400() {
        // Non-numeric sync-token must be rejected with 400.
        $response = $this->addressBookSyncTokenRequest('not-a-token');

        $this->assertEquals(400, $response->status);
    }

    public static function illTypedManagementBodies(): array {
        $calendar = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json';
        $subscription = '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json';
        $home = '/calendars/54b64eadf6d7d8e41d263e0f.json';

        return [
            'share.remove entry without href' => ['POST', $calendar, '{"share":{"remove":[{}]}}'],
            'share.set entry without href' => ['POST', $calendar, '{"share":{"set":[{"dav:read":true}]}}'],
            'share.set entry with non-string href' => ['POST', $calendar, '{"share":{"set":[{"dav:href":{},"dav:read":true}]}}'],
            'share.set entry not an object' => ['POST', $calendar, '{"share":{"set":["mailto:a@b.c"]}}'],
            'share.set not an array' => ['POST', $calendar, '{"share":{"set":"x"}}'],
            'share.remove not an array' => ['POST', $calendar, '{"share":{"remove":"x"}}'],
            'share not an object' => ['POST', $calendar, '{"share":"x"}'],
            'calendar POST body not an object' => ['POST', $calendar, '[]'],
            'calendar PROPPATCH null body' => ['PROPPATCH', $calendar, 'null'],
            'calendar PROPPATCH non-JSON body' => ['PROPPATCH', $calendar, 'not json'],
            'subscription PROPPATCH null body' => ['PROPPATCH', $subscription, 'null'],
            'PROPFIND without prop' => ['PROPFIND', $calendar, '{}'],
            'PROPFIND with string prop' => ['PROPFIND', $calendar, '{"prop":"acl"}'],
            'PROPFIND with non-string prop entries' => ['PROPFIND', $calendar, '{"prop":[{}]}'],
            'PROPFIND null body' => ['PROPFIND', $calendar, 'null'],
            'home POST with object id' => ['POST', $home, '{"id":{}}'],
            'home POST with array id' => ['POST', $home, '{"id":["a"]}'],
            'home POST body not an object' => ['POST', $home, '"x"'],
            'subscription creation with object id' => ['POST', $home, '{"id":{},"calendarserver:source":{"href":"/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json"}}'],
            'subscription creation with non-string source href' => ['POST', $home, '{"id":"sub2","calendarserver:source":{"href":{}}}'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('illTypedManagementBodies')]
    function testIllTypedManagementBodyIsBadRequest($method, $uri, $body) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => $method,
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => $uri,
        ));
        $request->setBody($body);

        $response = $this->request($request);

        $this->assertEquals(400, $response->status);
    }

    function testCreateCalendarWithIntegerIdIsStillAccepted() {
        $response = $this->makeRequest('POST', '/calendars/54b64eadf6d7d8e41d263e0f.json', ['id' => 42, 'dav:name' => 'Numeric']);

        $this->assertEquals(201, $response->status);
    }
}
