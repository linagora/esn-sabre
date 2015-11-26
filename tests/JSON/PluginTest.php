<?php

namespace ESN\JSON;

require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/ResponseMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/SapiMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVACL/PrincipalBackend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CalDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CardDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVServerTest.php';

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
END:VCALENDAR
',
    );

    protected $timeRangeData = [
          'match' => [ 'start' => '20120225T230000Z', 'end' => '20130228T225959Z' ],
          'scope' => [ 'calendars' => [ '/calendars/54b64eadf6d7d8e41d263e0f/calendar1' ] ]
        ];
    protected $timeRangeDataRecur = [
          'match' => [ 'start' => '20150227T000000Z', 'end' => '20150228T030000Z' ],
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

    function setUp() {
        $mcesn = new \MongoClient(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->selectDB(ESN_MONGO_ESNDB);

        $mcsabre = new \MongoClient(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->selectDB(ESN_MONGO_SABREDB);

        $this->sabredb->drop();
        $this->esndb->drop();

        $this->esndb->users->insert([ '_id' => new \MongoId('54b64eadf6d7d8e41d263e0f') ]);

        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);
        $this->caldavBackend = new \ESN\CalDAV\Backend\Mongo($this->sabredb);
        $this->carddavBackend = new \ESN\CardDAV\Backend\Mongo($this->sabredb);

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

        $plugin = new Plugin('json');
        $this->server->addPlugin($plugin);


        $cal = $this->caldavCalendar;
        $cal['id'] = $this->caldavBackend->createCalendar($cal['principaluri'], $cal['uri'], $cal);

        foreach ($this->caldavCalendarObjects as $eventUri => $data) {
            $this->caldavBackend->createCalendarObject($cal['id'], $eventUri, $data);
        }
        $book = $this->carddavAddressBook;
        $book['id'] = $this->carddavBackend->createAddressBook($book['principaluri'], $book['uri'], []);

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
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $this->assertCount(1, $jsonResponse->_embedded->{'dav:item'});
    }

    function testTimeRangeQueryRecur() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $request->setBody(json_encode($this->timeRangeDataRecur));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());

        $items = $jsonResponse->_embedded->{'dav:item'};
        $this->assertCount(1, $items);

        $vcalendar = \Sabre\VObject\Reader::readJson($items[0]->{'data'});

        $vevents = $vcalendar->select('VEVENT');
        $this->assertCount(2, $vevents);

        // All properties must contain a recurrence id
        foreach ($vevents as $vevent) {
            $this->assertTrue(!!$vevent->{'RECURRENCE-ID'});
        }
    }

    function testTimeRangeQueryMissingMatch() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $data = $this->timeRangeData;
        unset($data['match']);

        $request->setBody(json_encode($data));
        $response = $this->request($request);
        $this->assertEquals($response->status, 400);
    }

    function testTimeRangeQuery404() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
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
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/notfound.jaysun',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 501);
    }
    function testTimeRangeWrongNode() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
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
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/3');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');

        $items = $calendars[0]->_embedded->{'dav:item'};
        $this->assertCount(1, $items);
    }

    function testMultiQueryMissingScope() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
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
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json'
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testAllContacts() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
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
            'REQUEST_URI'       => '/calendars.json',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/calendars.json');

        $homes = $jsonResponse->{'_embedded'}->{'dav:home'};
        $this->assertCount(1, $homes);

        $this->assertEquals($homes[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f.json');

        $calendars = $homes[0]->{'_embedded'}->{'dav:calendar'};
        $this->assertCount(1, $calendars);

        $this->assertEquals($calendars[0]->{'_links'}->self->href, '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json');
        $this->assertEquals($calendars[0]->{'dav:name'}, 'Calendar');
        $this->assertEquals($calendars[0]->{'caldav:description'}, 'description');
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/3');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');
    }

    function testCalendarList() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
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
        $this->assertEquals($calendars[0]->{'calendarserver:ctag'}, 'http://sabre.io/ns/sync/3');
        $this->assertEquals($calendars[0]->{'apple:color'}, '#0190FFFF');
        $this->assertEquals($calendars[0]->{'apple:order'}, '2');
    }

    function testCreateCalendar() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
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
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f.json',
        ));

        $addressbook = [
            "id" => "ID",
            "dav:name" => "NAME",
            "carddav:description" => "DESCRIPTION"
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
    }

    function testCreateAddressbookMissingId() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
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
}
