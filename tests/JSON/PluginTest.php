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

    function setUp() {
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
            $this->assertTrue(!!$vevent->{'RECURRENCE-ID'});
        }
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

    function testTimeRangeWrongNode() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 501);
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

    function testContactsUnknown() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.jaysun'
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testContactsWrongCollection() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar2.json'
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testAllContacts() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json');
        $cards = $jsonResponse->{'_embedded'}->{'dav:item'};
        $this->assertEquals(count($cards), 4);
        $this->assertEquals($cards[0]->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1/card1');
        $this->assertEquals($cards[0]->data[0], 'vcard');
        $this->assertEquals($cards[0]->data[1][0][3], 'd');
    }

    function testOffsetContacts() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json?limit=1&offset=1&sort=fn'
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json');
        $cards = $jsonResponse->{'_embedded'}->{'dav:item'};
        $this->assertCount(1, $cards);
        $this->assertEquals($cards[0]->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1/card3');
        $this->assertEquals($cards[0]->data[0], 'vcard');
        $this->assertEquals($cards[0]->data[1][0][3], 'b');
    }

    private function checkCalendars($calendars) {
        $this->assertCount(3, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/4');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');

        $this->assertEquals($calendars[1]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/delegatedCal1.json');
        $this->assertEquals($calendars[1]->{'dav:name'}, 'delegatedCalendar');

        $this->assertEquals($calendars[2]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json');
        $this->assertEquals($calendars[2]->{'dav:name'}, 'Subscription');
        $this->assertEquals($calendars[2]->{'calendarserver:source'}->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json');

        return $calendars;
    }

    function _testCalendarRoot($withRightsParam = null) {
        $requestUri = $withRightsParam ? '/calendars.json?withRights=' . $withRightsParam : '/calendars.json';
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => $requestUri,
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars.json');

        $homes = $jsonResponse->{'_embedded'}->{'dav:home'};
        $this->assertCount(2, $homes);

        $this->assertEquals($homes[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');

        $this->checkCalendars($homes[0]->{'_embedded'}->{'dav:calendar'});

        $calendars = $homes[1]->{'_embedded'}->{'dav:calendar'};
        $this->assertCount(1, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/2');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');

        return $homes;
    }

    function testCalendarRoot() {
        $this->_testCalendarRoot();
    }

    function testCalendarRootWithRights() {
        $homes = $this->_testCalendarRoot('true');

        $calendars = $homes[1]->{'_embedded'}->{'dav:calendar'};
        $this->assertNotNull($calendars[0]->{'acl'});
        $publicACE = null;
        foreach ($calendars[0]->{'acl'} as $ace) {
            if ($ace->principal === '{DAV:}authenticated') {
                $publicACE = $ace;
                break;
            }
        }
        $this->assertEquals($publicACE->privilege, '{DAV:}all');
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

        $this->assertCount(3, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/4');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');

        $this->assertEquals($calendars[1]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/delegatedCal1.json');
        $this->assertEquals($calendars[1]->{'dav:name'}, 'delegatedCalendar');

        $this->assertEquals($calendars[2]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json');
        $this->assertEquals($calendars[2]->{'dav:name'}, 'Subscription');
        $this->assertEquals($calendars[2]->{'calendarserver:source'}->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json');
       
    }

    function testFilteredCalendarLisWithPersonalOnly() {
        $jsonResponse = $this->_testFilteredCalendarList('true');
        $calendars = $jsonResponse->{'_embedded'}->{'dav:calendar'};

        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');
        $this->assertCount(2, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/4');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');

        $this->assertEquals($calendars[1]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/delegatedCal1.json');
        $this->assertEquals($calendars[1]->{'dav:name'}, 'delegatedCalendar');
    }

    function testFilteredCalendarLisWithSubscriptionOnly() {
        $jsonResponse = $this->_testFilteredCalendarList(null, 'true');
        $calendars = $jsonResponse->{'_embedded'}->{'dav:calendar'};

        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');
        $this->assertCount(1, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Subscription');
        $this->assertEquals($calendars[0]->{'calendarserver:source'}->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json');

    }

    function testFilteredCalendarLisWithSharedNoResponseOnly() {
        $jsonResponse = $this->_testFilteredCalendarList(null, null, 'noresponse');
        $calendars = $jsonResponse->{'_embedded'}->{'dav:calendar'};

        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');
        $this->assertCount(1, $calendars);

        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
    }

    function testFilteredCalendarLisWithSharedAcceptedOnly() {
        $jsonResponse = $this->_testFilteredCalendarList(null, null, 'accepted');
        
        $this->assertNull($jsonResponse);
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

        $calendars = $this->caldavBackend->getCalendarsForUser($this->caldavCalendar['principaluri']);
        $this->assertCount(3, $calendars);

        $cal = $calendars[2];
        $this->assertEquals('NAME', $cal['{DAV:}displayname']);
        $this->assertEquals('DESCRIPTION', $cal['{urn:ietf:params:xml:ns:caldav}calendar-description']);
        $this->assertEquals('#0190FFFF', $cal['{http://apple.com/ns/ical/}calendar-color']);
        $this->assertEquals('99', $cal['{http://apple.com/ns/ical/}calendar-order']);
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

        $calendars = $this->caldavBackend->getSubscriptionsForUser($this->caldavCalendar['principaluri']);
        $this->assertCount(2, $calendars);

        $cal = $calendars[1];
        $this->assertEquals('SUB NAME', $cal['{DAV:}displayname']);
        $this->assertEquals('/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json', $cal['source']);
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

        $calendars = $this->caldavBackend->getCalendarsForUser($this->caldavCalendar['principaluri']);
        $this->assertCount(2, $calendars);
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

        $calendars = $this->caldavBackend->getSubscriptionsForUser($this->caldavCalendar['principaluri']);
        $this->assertCount(1, $calendars);
    }

    function testCreateAddressbook() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f.json',
        ));

        $addressbook = [
            'id' => 'ID',
            'dav:name' => 'NAME',
            'carddav:description' => 'DESCRIPTION',
            'dav:acl' => ['dav:read'],
            'type' => 'social'
        ];

        $request->setBody(json_encode($addressbook));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals(201, $response->status);

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(2, $addressbooks);

        $book = $addressbooks[1];
        $this->assertEquals('NAME', $book['{DAV:}displayname']);
        $this->assertEquals('DESCRIPTION', $book['{urn:ietf:params:xml:ns:carddav}addressbook-description']);
        $this->assertEquals(['dav:read'], $book['{DAV:}acl']);
        $this->assertEquals('social', $book['{http://open-paas.org/contacts}type']);
    }

    function testCreateAddressbookMissingId() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f.json',
        ));

        $addressbook = [
            'id' => ''
        ];

        $request->setBody(json_encode($addressbook));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals(400, $response->status);

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(1, $addressbooks);
    }

    function testDeleteCalendar() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $calendars = $this->caldavBackend->getCalendarsForUser($this->caldavCalendar['principaluri']);
        $this->assertCount(2, $calendars);

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);

        $calendars = $this->caldavBackend->getCalendarsForUser($this->caldavCalendar['principaluri']);
        $this->assertCount(1, $calendars);
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

        $subscriptions = $this->caldavBackend->getSubscriptionsForUser($this->caldavCalendar['principaluri']);
        $this->assertEquals($nameUpdated, $subscriptions[0]['{DAV:}displayname']);
    }

    function testDeleteSubscription() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/subscription1.json',
        ));

        $subscriptions = $this->caldavBackend->getSubscriptionsForUser($this->caldavCalendar['principaluri']);
        $this->assertCount(1, $subscriptions);

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);

        $subscriptions = $this->caldavBackend->getSubscriptionsForUser($this->caldavCalendar['principaluri']);
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

        $publicCaldavCalendar['id'] = $this->caldavBackend->createCalendar($publicCaldavCalendar['principaluri'], $publicCaldavCalendar['uri'], $publicCaldavCalendar);
        $this->caldavBackend->saveCalendarPublicRight($publicCaldavCalendar['id'], '{DAV:}read');

        $subscriptionBody = [
            'id' => 'publicCalToRemoveSubscription',
            'dav:name' => 'SUB NAME',
            'calendarserver:source' => [
                'href' => '/calendars/54b64eadf6d7d8e41d263e0e/publicCalToRemove.json'
            ],
            'apple:color' => '#0190FFFF',
            'apple:order' => '99'
        ];

        $subscriptionResponse = $this->makeRequest('POST', '/calendars/54b64eadf6d7d8e41d263e0f.json', $subscriptionBody);

        $this->assertEquals($subscriptionResponse->status, 201);

        $getAllCalendarResponse = $this->makeRequest('GET', '/calendars/54b64eadf6d7d8e41d263e0f.json?withRights=true', null);
        $jsonResponse = json_decode($getAllCalendarResponse->getBodyAsString());
        $allCalendarsAfterSubscription = count($jsonResponse->{'_embedded'}->{'dav:calendar'});

        $deleteResponse = $this->makeRequest('DELETE', '/calendars/54b64eadf6d7d8e41d263e0e/'.$publicCaldavCalendar['uri'].'.json', null);
        $this->assertEquals($deleteResponse->status, 204);

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
        $this->assertCount(3, $calendars);

        $response = $this->request($request);
        $this->assertEquals(403, $response->status);

        $calendars = $this->caldavBackend->getCalendarsForUser($mainCalDavCalendar['principaluri']);
        $this->assertCount(3, $calendars);
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
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book2.json',
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
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 400);
    }

    function testPropFindRequest() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $body = '{"properties": ["{DAV:}acl","uri"]}';
        $request->setBody($body);
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(200, $response->status);
        $this->assertEquals(['dav:read', 'dav:write'], $jsonResponse['{DAV:}acl']);
        $this->assertEquals('book1', $jsonResponse['uri']);
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

    function testGetAddressBookList() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f.json',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f.json');
        $addressBooks = $jsonResponse->{'_embedded'}->{'dav:addressbook'};
        $this->assertCount(1, $addressBooks);

        $this->assertEquals($addressBooks[0]->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json');
        $this->assertEquals($addressBooks[0]->{'dav:name'}, 'Book 1');
        $this->assertEquals($addressBooks[0]->{'carddav:description'}, 'Book 1 description');
        $this->assertEquals($addressBooks[0]->{'dav:acl'}, ['dav:read', 'dav:write']);
        $this->assertEquals($addressBooks[0]->{'type'}, 'social');
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
        $this->assertEquals(count($sharees), 5);
        $this->assertEquals($sharees[1]->href, 'mailto:johndoe@example.org');
        $this->assertEquals($sharees[1]->properties['{DAV:}displayname'], 'With John Doe');
        $this->assertEquals($sharees[1]->access, 3);
        $this->assertEquals($sharees[2]->href, 'mailto:johndoe2@example.org');
        $this->assertEquals($sharees[2]->access, 2);
        $this->assertEquals($sharees[3]->href, 'mailto:johndoe3@example.org');
        $this->assertEquals($sharees[3]->access, 5);
        $this->assertEquals($sharees[4]->href, 'mailto:johndoe4@example.org');
        $this->assertEquals($sharees[4]->access, 6);
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

    function testITIPShouldReturn400IfUIDIsMissing() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ITIP',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));
        $this->itipRequestData['uid'] = null;

        $request->setBody(json_encode($this->itipRequestData));
        $response = $this->request($request);

        $this->assertEquals($response->status, 400);
    }

    function testITIPShouldReturn400IfSenderIsMissing() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ITIP',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));
        $this->itipRequestData['sender'] = null;

        $request->setBody(json_encode($this->itipRequestData));
        $response = $this->request($request);

        $this->assertEquals($response->status, 400);
    }

    function testITIPShouldReturn400IfRecipientIsMissing() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ITIP',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));
        $this->itipRequestData['recipient'] = null;

        $request->setBody(json_encode($this->itipRequestData));
        $response = $this->request($request);

        $this->assertEquals($response->status, 400);
    }

    function testITIPShouldReturn400IfICalIsMissing() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ITIP',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));
        $this->itipRequestData['ical'] = null;

        $request->setBody(json_encode($this->itipRequestData));
        $response = $this->request($request);

        $this->assertEquals($response->status, 400);
    }

    function testITIPShouldDelegateToSchedulingPluginAndReturn200() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ITIP',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));
        $schedulePlugin = $this->getMock(ServerPlugin::class, ['getPluginName', 'scheduleLocalDelivery', 'initialize']);
        $schedulePlugin->expects($this->any())->method('getPluginName')->will($this->returnValue('caldav-schedule'));
        $schedulePlugin->expects($this->once())->method('scheduleLocalDelivery')->will($this->returnCallback(function($message) {
            $this->assertInstanceOf(Message::class, $message);
            $this->assertEquals('REPLY', $message->method);
            $this->assertEquals('75EE3C60-34AC-4A97-953D-56CC004D6705', $message->uid);
            $this->assertEquals('1', $message->sequence);
            $this->assertEquals('mailto:a@linagora.com', $message->sender);
            $this->assertEquals('mailto:b@linagora.com', $message->recipient);
            $this->assertInstanceOf(Document::class, $message->message);
        }));

        $this->server->addPlugin($schedulePlugin);

        $request->setBody(json_encode($this->itipRequestData));
        $response = $this->request($request);

        $this->assertEquals($response->status, 204);
    }

    function testITIPShouldDefaultForRequestAndSequence() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'ITIP',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f',
        ));
        $this->itipRequestData['method'] = null;
        $this->itipRequestData['sequence'] = null;

        $schedulePlugin = $this->getMock(ServerPlugin::class, ['getPluginName', 'scheduleLocalDelivery', 'initialize']);
        $schedulePlugin->expects($this->any())->method('getPluginName')->will($this->returnValue('caldav-schedule'));
        $schedulePlugin->expects($this->once())->method('scheduleLocalDelivery')->will($this->returnCallback(function($message) {
            $this->assertInstanceOf(Message::class, $message);
            $this->assertEquals('REQUEST', $message->method);
            $this->assertEquals('75EE3C60-34AC-4A97-953D-56CC004D6705', $message->uid);
            $this->assertEquals('0', $message->sequence);
            $this->assertEquals('mailto:a@linagora.com', $message->sender);
            $this->assertEquals('mailto:b@linagora.com', $message->recipient);
            $this->assertInstanceOf(Document::class, $message->message);
        }));

        $this->server->addPlugin($schedulePlugin);

        $request->setBody(json_encode($this->itipRequestData));
        $response = $this->request($request);

        $this->assertEquals($response->status, 204);
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
}
