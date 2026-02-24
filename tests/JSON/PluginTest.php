<?php

namespace ESN\JSON;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

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
        $this->server->addPlugin($aclPlugin);

        $plugin = new Plugin('json');
        $this->server->addPlugin($plugin);
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

    function testMultiQuery() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/query.json',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 200);
        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertEquals('/query.json', $jsonResponse->_links->self->href);
        $calendars = $jsonResponse->_embedded->{'dav:calendar'};
        $this->assertCount(1, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/4');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');

        $items = $calendars[0]->_embedded->{'dav:item'};
        $this->assertCount(1, $items);
    }

    function testMultiQueryMissingScope() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/query.json',
        ));

        $data = $this->timeRangeData;
        unset($data['scope']['calendars']);

        $request->setBody(json_encode($data));
        $response = $this->request($request);
        $this->assertEquals($response->status, 400);
    }

    function testMultiQueryWithJsonSuffix() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/query.json',
        ));

        $data = $this->timeRangeData;
        $data['scope']['calendars'][0] .= '.json';

        $request->setBody(json_encode($data));
        $response = $this->request($request);
        $this->assertEquals($response->status, 200);
        $jsonResponse = json_decode($response->getBodyAsString());

        $calendars = $jsonResponse->_embedded->{'dav:calendar'};
        $this->assertCount(1, $calendars);

        $items = $calendars[0]->_embedded->{'dav:item'};
        $this->assertCount(1, $items);
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

    function testGETAddressBookHomesWithTechnicalUser() {
        $this->authBackend->setPrincipal('principals/technicalUser');

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals([
            // 4 users
            '54b64eadf6d7d8e41d263e0f' => [ 'calendar1', 'delegatedCal1', 'user1Calendar2' ],
            '54b64eadf6d7d8e41d263e0e' => [ 'calendar2', 'publicCal1' ],
            '54b64eadf6d7d8e41d263e0d' => [],
            '54b64eadf6d7d8e41d263e0c' => [ '54b64eadf6d7d8e41d263e0c' ],
            // 1 resource
            '62b64eadf6d7d8e41d263e0c' => []
        ], $jsonResponse);

        $this->assertEquals(200, $response->status);
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

        $this->assertCount(count($this->user1Calendars['ownedCalendars']), $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/4');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');

        $this->assertEquals($calendars[1]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/delegatedCal1.json');
        $this->assertEquals($calendars[1]->{'dav:name'}, 'delegatedCalendar');
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
        $response = $this->request($delegationRequest);

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

        $this->assertNull($jsonResponse);
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

    function testGetAllEventsInCalendar() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json?allEvents=true',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->_links->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals(sizeof($jsonResponse->_embedded->{'dav:item'}), 3);
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
        $this->assertNull($jsonResponse);
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
        $response = $this->request($delegationRequest);

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
        $this->assertEquals($jsonResponse, null);
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

    function testCalendarUpdateInviteStatus() {
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
}
