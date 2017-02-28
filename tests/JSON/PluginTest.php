<?php

namespace ESN\JSON;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/ResponseMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/SapiMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVACL/PrincipalBackend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CalDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CardDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVServerTest.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAV/Auth/Backend/Mock.php';

/**
 * @medium
 */
class PluginTest extends \PHPUnit_Framework_TestCase {

    protected $caldavCalendar = array(
        '{DAV:}displayname' => 'Calendar',
        '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
        '{http://apple.com/ns/ical/}calendar-color' => '#0190FFFF',
        '{http://apple.com/ns/ical/}calendar-order' => '2',
        'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
        'uri' => 'calendar1',
    );

    protected $caldavCalendarUser2 = array(
        '{DAV:}displayname' => 'Calendar',
        '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
        '{http://apple.com/ns/ical/}calendar-color' => '#0190FFFF',
        '{http://apple.com/ns/ical/}calendar-order' => '2',
        'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0e',
        'uri' => 'calendar2',
    );

    protected $publicCaldavCalendar = array(
        '{DAV:}displayname' => 'Calendar',
        '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
        '{http://apple.com/ns/ical/}calendar-color' => '#0190FFFF',
        '{http://apple.com/ns/ical/}calendar-order' => '2',
        'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0e',
        'uri' => 'publicCal1',
    );

    protected $caldavCalendarObjects = array(
        'event1.ics' =>
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20120313T142342Z
UID:171EBEFC-C951-499D-B234-7BA7D677B45D
DTEND;TZID=Europe/Berlin:20120227T000000
TRANSP:OPAQUE
SUMMARY:Monday 0h
DTSTART;TZID=Europe/Berlin:20120227T000000
DTSTAMP:20120313T142416Z
SEQUENCE:4
END:VEVENT
END:VCALENDAR
',
        'event2.ics' =>
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20120313T142342Z
UID:28CCB90C-0F2F-48FC-B1D9-33A2BA3D9594
TRANSP:OPAQUE
SUMMARY:Event 2
DTSTART:20130401T000000Z
DTEND:20130401T010000Z
DTSTAMP:20120313T142416Z
SEQUENCE:1
END:VEVENT
END:VCALENDAR
',
        'recur.ics' =>
            'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:75EE3C60-34AC-4A97-953D-56CC004D6705
SUMMARY:Recurring
DTSTART:20150227T010000
DTEND:20150227T020000
RRULE:FREQ=DAILY
END:VEVENT
BEGIN:VEVENT
UID:75EE3C60-34AC-4A97-953D-56CC004D6705
RECURRENCE-ID:20150228T010000
SUMMARY:Recurring
DTSTART:20150228T030000
DTEND:20150228T040000
END:VEVENT
END:VCALENDAR
',
    );

    protected $privateRecurEvent =
    'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:75EE3C60-34AC-4A97-953D-56CC004D6706
SUMMARY:RecurringPrivate
DTSTART:20150227T010000
DTEND:20150227T020000
LOCATION:Paris
RRULE:FREQ=DAILY
CLASS:PRIVATE
END:VEVENT
BEGIN:VEVENT
UID:75EE3C60-34AC-4A97-953D-56CC004D6706
RECURRENCE-ID:20150228T010000
SUMMARY:Exception
DTSTART:20150228T030000
DTEND:20150228T040000
END:VEVENT
END:VCALENDAR
';

    protected $timeRangeData = [
          'match' => [ 'start' => '20120225T230000Z', 'end' => '20130228T225959Z' ],
          'scope' => [ 'calendars' => [ '/calendars/54b64eadf6d7d8e41d263e0f/calendar1' ] ]
        ];

    protected $timeRangeDataBothEvents = [
        'match' => [ 'start' => '20120101T000000Z', 'end' => '20150101T000000Z' ],
        'scope' => [ 'calendars' => [ '/calendars/54b64eadf6d7d8e41d263e0f/calendar1' ] ]
    ];

    protected $timeRangeDataRecur = [
          'match' => [ 'start' => '20150227T000000Z', 'end' => '20150229T030000Z' ],
          'scope' => [ 'calendars' => [ '/calendars/54b64eadf6d7d8e41d263e0f/calendar1' ] ]
        ];

    protected $carddavAddressBook = array(
        'uri' => 'book1',
        'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
    );

    protected $carddavCards = array(
        "card1" => "BEGIN:VCARD\r\nFN:d\r\nEND:VCARD\r\n",
        "card2" => "BEGIN:VCARD\r\nFN:c\r\nEND:VCARD",
        "card3" => "BEGIN:VCARD\r\nFN:b\r\nEND:VCARD\r\n",
        "card4" => "BEGIN:VCARD\nFN:a\nEND:VCARD\n",
    );

    protected $uidQueryData = [ 'uid' => '171EBEFC-C951-499D-B234-7BA7D677B45D' ];

    protected $uidQueryDataRecur = [ 'uid' => '75EE3C60-34AC-4A97-953D-56CC004D6705' ];

    protected $itipRequestData = [
        'method' => 'REPLY',
        'uid' => '75EE3C60-34AC-4A97-953D-56CC004D6705',
        'sequence' => '1',
        'sender' => 'a@linagora.com',
        'recipient' => 'b@linagora.com',
        'ical' => 'BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20120313T142342Z
UID:171EBEFC-C951-499D-B234-7BA7D677B45D
DTEND;TZID=Europe/Berlin:20120227T000000
TRANSP:OPAQUE
SUMMARY:Monday 0h
DTSTART;TZID=Europe/Berlin:20120227T000000
DTSTAMP:20120313T142416Z
SEQUENCE:4
END:VEVENT
END:VCALENDAR'
    ];

    protected $cal;

    function setUp() {
        $mcesn = new \MongoClient(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->selectDB(ESN_MONGO_ESNDB);

        $mcsabre = new \MongoClient(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->selectDB(ESN_MONGO_SABREDB);

        $this->sabredb->drop();
        $this->esndb->drop();

        $this->esndb->users->insert([
            '_id' => new \MongoId('54b64eadf6d7d8e41d263e0f'),
            "firstname" => "Roberto",
            "lastname" => "Carlos"
        ]);
        $this->esndb->users->insert([
            '_id' => new \MongoId('54b64eadf6d7d8e41d263e0e'),
            "accounts" => [
                [
                    "type" => "email",
                    "emails" => [
                      "johndoe@example.org"
                    ]
                ]
            ]
        ]);
        $this->esndb->users->insert([
            '_id' => new \MongoId('54b64eadf6d7d8e41d263e0d'),
            "accounts" => [
                [
                    "type" => "email",
                    "emails" => [
                      "johndoe2@example.org"
                    ]
                ]
            ]
        ]);
        $this->esndb->users->insert([
            '_id' => new \MongoId('54b64eadf6d7d8e41d263e0c'),
            "accounts" => [
                [
                    "type" => "email",
                    "emails" => [
                      "janedoe@example.org"
                    ]
                ]
            ]
        ]);

        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);
        $this->caldavBackend = new \ESN\CalDAV\Backend\Mongo($this->sabredb);
        $this->carddavBackend = new \ESN\CardDAV\Backend\Mongo($this->sabredb);

        $this->tree[] = new \Sabre\DAV\SimpleCollection('principals', [
          new \Sabre\CalDAV\Principal\Collection($this->principalBackend, 'principals/users')
        ]);
        $this->tree[] = new \ESN\CardDAV\AddressBookRoot(
            $this->principalBackend,
            $this->carddavBackend,
            $this->esndb
        );
        $this->tree[] = new \ESN\CalDAV\CalendarRoot(
            $this->principalBackend,
            $this->caldavBackend,
            $this->esndb
        );

        $this->server = new \Sabre\DAV\Server($this->tree);
        $this->server->sapi = new \Sabre\HTTP\SapiMock();
        $this->server->debugExceptions = true;

        $caldavPlugin = new \Sabre\CalDAV\Plugin();
        $this->server->addPlugin($caldavPlugin);

        $this->carddavPlugin = new \Sabre\CardDAV\Plugin();
        $this->server->addPlugin($this->carddavPlugin);

        $this->server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
        $this->server->addPlugin(new \Sabre\CalDAV\SharingPlugin());

        $authBackend = new \Sabre\DAV\Auth\Backend\Mock();
        $authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0f');
        $authPlugin = new \Sabre\DAV\Auth\Plugin($authBackend);
        $this->server->addPlugin($authPlugin);

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals/users'];
        $this->server->addPlugin($aclPlugin);

        $plugin = new Plugin('json');
        $this->server->addPlugin($plugin);

        $this->cal = $this->caldavCalendar;
        $this->cal['id'] = $this->caldavBackend->createCalendar($this->cal['principaluri'], $this->cal['uri'], $this->cal);
        foreach ($this->caldavCalendarObjects as $eventUri => $data) {
            $this->caldavBackend->createCalendarObject($this->cal['id'], $eventUri, $data);
        }

        $this->calUser2 = $this->caldavCalendarUser2;
        $this->calUser2['id'] = $this->caldavBackend->createCalendar($this->calUser2['principaluri'], $this->calUser2['uri'], $this->calUser2);

        $this->publicCal = $this->publicCaldavCalendar;
        $this->publicCal['id'] = $this->caldavBackend->createCalendar($this->publicCal['principaluri'], $this->publicCal['uri'], $this->publicCal);
        $this->caldavBackend->saveCalendarPublicRight($this->publicCal['id'], '{DAV:}read');
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'privateRecurEvent.ics', $this->privateRecurEvent);

        $book = $this->carddavAddressBook;
        $book['id'] = $this->carddavBackend->createAddressBook($book['principaluri'],
            $book['uri'],
            [
                '{DAV:}displayname' => 'Book 1',
                '{urn:ietf:params:xml:ns:carddav}addressbook-description' => 'Book 1 description',
                '{http://open-paas.org/contacts}type' => 'social'
            ]);

        foreach ($this->carddavCards as $card => $data) {
            $this->carddavBackend->createCard($book['id'], $card, $data);
        }
    }

    function request($request) {

        if (is_array($request)) {
            $request = HTTP\Request::createFromServerArray($request);
        }
        $this->server->httpRequest = $request;
        $this->server->httpResponse = new \Sabre\HTTP\ResponseMock();
        $this->server->exec();

        return $this->server->httpResponse;

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
        $this->assertNull($vevents[0]->SUMMARY);
        $this->assertEquals($vevents[1]->SUMMARY, 'Exception');
        $this->assertNull($vevents[2]->SUMMARY);
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
        $this->assertNull($vevents[0]->SUMMARY);
        $this->assertEquals($vevents[1]->SUMMARY, 'Exception');
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

        $this->assertEquals("/query.json", $jsonResponse->_links->self->href);
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

    function testCalendarRoot() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars.json',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars.json');

        $homes = $jsonResponse->{'_embedded'}->{'dav:home'};
        $this->assertCount(4, $homes);

        $this->assertEquals($homes[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');

        $calendars = $homes[0]->{'_embedded'}->{'dav:calendar'};
        $this->assertCount(1, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/4');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');

        $this->assertEquals($homes[1]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0e.json');

        $calendars = $homes[1]->{'_embedded'}->{'dav:calendar'};
        $this->assertCount(1, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/2');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');
    }

    function testCalendarList() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f.json',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');
        $calendars = $jsonResponse->{'_embedded'}->{'dav:calendar'};
        $this->assertCount(1, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/4');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');
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
            "id" => "ID",
            "dav:name" => "NAME",
            "caldav:description" => "DESCRIPTION",
            "apple:color" => "#0190FFFF",
            "apple:order" => "99"
        ];

        $request->setBody(json_encode($calendar));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 201);

        $calendars = $this->caldavBackend->getCalendarsForUser($this->caldavCalendar['principaluri']);
        $this->assertCount(2, $calendars);

        $cal = $calendars[1];
        $this->assertEquals('NAME', $cal['{DAV:}displayname']);
        $this->assertEquals('DESCRIPTION', $cal['{urn:ietf:params:xml:ns:caldav}calendar-description']);
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
            "id" => "",
            "dav:name" => "NAME",
            "caldav:description" => "DESCRIPTION",
            "apple:color" => "#0190FFFF",
            "apple:order" => "99"
        ];

        $request->setBody(json_encode($calendar));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 400);

        $calendars = $this->caldavBackend->getCalendarsForUser($this->caldavCalendar['principaluri']);
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
            "id" => "ID",
            "dav:name" => "NAME",
            "carddav:description" => "DESCRIPTION",
            "dav:acl" => ['dav:read'],
            "type" => 'social'
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
            "id" => ""
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
        $this->assertCount(1, $calendars);

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);

        $calendars = $this->caldavBackend->getCalendarsForUser($this->caldavCalendar['principaluri']);
        $this->assertCount(0, $calendars);
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
        $this->assertCount(2, $calendars);

        $response = $this->request($request);
        $this->assertEquals(403, $response->status);

        $calendars = $this->caldavBackend->getCalendarsForUser($mainCalDavCalendar['principaluri']);
        $this->assertCount(2, $calendars);
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

        $data = [ "dav:name" => "tested" ];
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

        $data = [ "dav:getetag" => "no" ];
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

        $data = [ "dav:name" => "tested" ];
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
            "share" => [
                "set" => [
                    [
                        "dav:href"       => "mailto:johndoe@example.org",
                        "common-name"    => "With John Doe",
                        "summary"        => "Delegation",
                        "dav:read-write" => true
                    ],
                    [
                        "dav:href" => "mailto:johndoe2@example.org",
                        "dav:read" => true
                    ]
                ],
                "remove" => [
                    [
                        "dav:href" => "mailto:janedoe@example.org",
                    ]
                ]
            ]
        ];

        $request->setBody(json_encode($sharees));
        $response = $this->request($request);

        $this->assertEquals(200, $response->status);

        $sharees = $this->caldavBackend->getInvites($this->cal['id']);
        $this->assertEquals(count($sharees), 3);
        $this->assertEquals($sharees[1]->href, "mailto:johndoe@example.org");
        $this->assertEquals($sharees[1]->properties['{DAV:}displayname'], "With John Doe");
        $this->assertEquals($sharees[1]->access, 3);
        $this->assertEquals($sharees[2]->href, "mailto:johndoe2@example.org");
        $this->assertEquals($sharees[2]->access, 2);
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
