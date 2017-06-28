<?php

namespace ESN\CalDAV\Backend;

use ESN\CalDAV;
use \Sabre\DAV;
use \Sabre\DAV\PropPatch;
use Sabre\DAV\Xml\Element\Sharee;

abstract class AbstractDatabaseTest extends \PHPUnit_Framework_TestCase {

    abstract protected function getBackend();
    abstract protected function generateId();

    /**
     * @depends testConstruct
     */
    function testGetCalendarsForUserNoCalendars() {
        $backend = $this->getBackend();
        $calendars = $backend->getCalendarsForUser('principals/user2/userID');
        $this->assertEquals(array(),$calendars);
    }

    /**
     * @depends testConstruct
     */
    function testCreateCalendarAndFetch() {
        $backend = $this->getBackend();
        $returnedId = $backend->createCalendar('principals/user2/userID','somerandomid',array(
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(array('VEVENT')),
            '{DAV:}displayname' => 'Hello!',
            '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp('transparent'),
        ));
        $calendars = $backend->getCalendarsForUser('principals/user2/userID');

        $elementCheck = array(
            'uri'               => 'somerandomid',
            '{DAV:}displayname' => 'Hello!',
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => '',
            '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp('transparent'),
        );

        $this->assertInternalType('array',$calendars);
        $this->assertEquals(1,count($calendars));

        foreach($elementCheck as $name=>$value) {
            $this->assertArrayHasKey($name, $calendars[0]);
            $this->assertEquals($value,$calendars[0][$name]);
        }
    }

    /**
     * @depends testConstruct
     */
    function testUpdateCalendarAndFetch() {
        $backend = $this->getBackend();

        //Creating a new calendar
        $newId = $backend->createCalendar('principals/user2/userID','somerandomid',array());

        $propPatch = new PropPatch([
            '{DAV:}displayname' => 'myCalendar',
            '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp('transparent'),
        ]);

        // Updating the calendar
        $backend->updateCalendar($newId, $propPatch);
        $result = $propPatch->commit();

        // Verifying the result of the update
        $this->assertTrue($result);

        // Fetching all calendars from this user
        $calendars = $backend->getCalendarsForUser('principals/user2/userID');

        // Checking if all the information is still correct
        $elementCheck = array(
            'id'                => $newId,
            'uri'               => 'somerandomid',
            '{DAV:}displayname' => 'myCalendar',
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => '',
            '{urn:ietf:params:xml:ns:caldav}calendar-timezone' => '',
            '{http://calendarserver.org/ns/}getctag' => 'http://sabre.io/ns/sync/2',
            '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp('transparent'),
        );

        $this->assertInternalType('array',$calendars);
        $this->assertEquals(1,count($calendars));

        foreach($elementCheck as $name=>$value) {
            $this->assertArrayHasKey($name, $calendars[0]);
            $this->assertEquals($value,$calendars[0][$name]);
        }
    }

    /**
     * @depends testUpdateCalendarAndFetch
     */
    function testUpdateCalendarUnknownProperty() {
        $backend = $this->getBackend();

        //Creating a new calendar
        $newId = $backend->createCalendar('principals/user2/userID','somerandomid',array());

        $propPatch = new PropPatch([
            '{DAV:}displayname' => 'myCalendar',
            '{DAV:}yourmom'     => 'wittycomment',
        ]);

        // Updating the calendar
        $backend->updateCalendar($newId, $propPatch);
        $propPatch->commit();

        // Verifying the result of the update
        $this->assertEquals([
            '{DAV:}yourmom' => 403,
            '{DAV:}displayname' => 424,
        ], $propPatch->getResult());
    }

    /**
     * @depends testCreateCalendarAndFetch
     */
    function testDeleteCalendar() {
        $backend = $this->getBackend();
        $returnedId = $backend->createCalendar('principals/user2/userID','somerandomid',array(
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(array('VEVENT')),
            '{DAV:}displayname' => 'Hello!',
        ));

        $backend->deleteCalendar($returnedId);

        $calendars = $backend->getCalendarsForUser('principals/user2/userID');
        $this->assertEquals(array(),$calendars);
    }

    /**
     * @depends testCreateCalendarAndFetch
     * @expectedException \Sabre\DAV\Exception
     */
    function testCreateCalendarIncorrectComponentSet() {;
        $backend = $this->getBackend();

        //Creating a new calendar
        $newId = $backend->createCalendar('principals/user2/userID','somerandomid',array(
            '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' => 'blabla',
        ));
    }

    function testGetMultipleObjects() {
        $backend = $this->getBackend();
        $returnedId = $backend->createCalendar('principals/user2/userID','somerandomid',array());

        $object = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

        $backend->createCalendarObject($returnedId, 'id-1', $object);
        $backend->createCalendarObject($returnedId, 'id-2', $object);

        $check = [
            [
                'etag' => '"' . md5($object) . '"',
                'uri' => 'id-1',
                'size' => strlen($object),
                'calendardata' => $object,
                'lastmodified' => null
            ],
            [
                'etag' => '"' . md5($object) . '"',
                'uri' => 'id-2',
                'size' => strlen($object),
                'calendardata' => $object,
                'lastmodified' => null
            ],
        ];

        $result = $backend->getMultipleCalendarObjects($returnedId, [ 'id-1', 'id-2' ]);

        foreach($check as $index => $props) {
            foreach($props as $key=>$value) {
                if ($key!=='lastmodified') {
                    $this->assertEquals($value, $result[$index][$key]);
                } else {
                    $this->assertTrue(isset($result[$index][$key]));
                }
            }
        }
    }

    function testGetCalendarObjects() {
        $backend = $this->getBackend();
        $returnedId = $backend->createCalendar('principals/user2/userID','somerandomid',array());

        $object = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $backend->createCalendarObject($returnedId, 'random-id', $object);

        $data = $backend->getCalendarObjects($returnedId,'random-id');

        $this->assertEquals(1, count($data));
        $data = $data[0];

        $this->assertEquals('random-id', $data['uri']);
        $this->assertEquals('"' . md5($object) . '"', $data['etag']);
        $this->assertEquals(strlen($object),$data['size']);
        $this->assertEquals('vevent', $data['component']);
    }

    function testGetCalendarObjectByUID() {
        $backend = $this->getBackend();
        $returnedId = $backend->createCalendar('principals/user2/userID','somerandomid',[]);

        $object = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:foo\r\nDTSTART;VALUE=DATE:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $backend->createCalendarObject($returnedId, 'random-id', $object);
        $backend->createCalendarObject($returnedId, 'random-id2', $object);

        $this->assertNull(
            $backend->getCalendarObjectByUID('principals/user2/userID', 'bar')
        );
        $this->assertEquals(
            'somerandomid/random-id',
            $backend->getCalendarObjectByUID('principals/user2/userID', 'foo')
        );
    }

    function testUpdateCalendarObject() {
        $backend = $this->getBackend();
        $returnedId = $backend->createCalendar('principals/user2/userID','somerandomid',array());

        $object = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $object2 = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20130101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $backend->createCalendarObject($returnedId, 'random-id', $object);
        $backend->updateCalendarObject($returnedId, 'random-id', $object2);

        $data = $backend->getCalendarObject($returnedId,'random-id');

        $this->assertEquals($object2, $data['calendardata']);
        $this->assertEquals('random-id', $data['uri']);
    }

    function testDeleteCalendarObject() {
        $backend = $this->getBackend();
        $returnedId = $backend->createCalendar('principals/user2/userID','somerandomid',array());

        $object = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $backend->createCalendarObject($returnedId, 'random-id', $object);
        $backend->deleteCalendarObject($returnedId, 'random-id');

        $data = $backend->getCalendarObject($returnedId,'random-id');
        $this->assertNull($data);
    }

    function testCalendarQueryNoResult() {
        $backend  = $this->getBackend();
        $filters = array(
            'name' => 'VCALENDAR',
            'comp-filters' => array(
                array(
                    'name' => 'VJOURNAL',
                    'comp-filters' => array(),
                    'prop-filters' => array(),
                    'is-not-defined' => false,
                    'time-range' => null,
                ),
            ),
            'prop-filters' => array(),
            'is-not-defined' => false,
            'time-range' => null,
        );

        $this->assertEquals(array(
        ), $backend->calendarQuery([1, 1], $filters));
    }

    function testCalendarQueryTodo() {
        $id = $this->generateId();

        $backend = $this->getBackend();
        $backend->createCalendarObject($id, "todo", "BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nEND:VTODO\r\nEND:VCALENDAR\r\n");
        $backend->createCalendarObject($id, "event", "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        $filters = array(
            'name' => 'VCALENDAR',
            'comp-filters' => array(
                array(
                    'name' => 'VTODO',
                    'comp-filters' => array(),
                    'prop-filters' => array(),
                    'is-not-defined' => false,
                    'time-range' => null,
                ),
            ),
            'prop-filters' => array(),
            'is-not-defined' => false,
            'time-range' => null,
        );

        $this->assertEquals(array(
            "todo",
        ), $backend->calendarQuery($id, $filters));
    }

    function testCalendarQueryTodoNotMatch() {
        $id = $this->generateId();

        $backend = $this->getBackend();
        $backend->createCalendarObject($id, "todo", "BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nEND:VTODO\r\nEND:VCALENDAR\r\n");
        $backend->createCalendarObject($id, "event", "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        $filters = array(
            'name' => 'VCALENDAR',
            'comp-filters' => array(
                array(
                    'name' => 'VTODO',
                    'comp-filters' => array(),
                    'prop-filters' => array(
                        array(
                            'name' => 'summary',
                            'text-match' => null,
                            'time-range' => null,
                            'param-filters' => array(),
                            'is-not-defined' => false,
                        ),
                    ),
                    'is-not-defined' => false,
                    'time-range' => null,
                ),
            ),
            'prop-filters' => array(),
            'is-not-defined' => false,
            'time-range' => null,
        );

        $this->assertEquals(array(
        ), $backend->calendarQuery($id, $filters));
    }

    function testCalendarQueryNoFilter() {
        $id = $this->generateId();

        $backend = $this->getBackend();
        $backend->createCalendarObject($id, "todo", "BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nEND:VTODO\r\nEND:VCALENDAR\r\n");
        $backend->createCalendarObject($id, "event", "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        $filters = array(
            'name' => 'VCALENDAR',
            'comp-filters' => array(),
            'prop-filters' => array(),
            'is-not-defined' => false,
            'time-range' => null,
        );

        $result = $backend->calendarQuery($id, $filters);
        $this->assertTrue(in_array('todo', $result));
        $this->assertTrue(in_array('event', $result));
    }

    function testCalendarQueryTimeRange() {
        $id = $this->generateId();

        $backend = $this->getBackend();
        $backend->createCalendarObject($id, "todo", "BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nEND:VTODO\r\nEND:VCALENDAR\r\n");
        $backend->createCalendarObject($id, "event", "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $backend->createCalendarObject($id, "event2", "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART;VALUE=DATE:20120103\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        $filters = array(
            'name' => 'VCALENDAR',
            'comp-filters' => array(
                array(
                    'name' => 'VEVENT',
                    'comp-filters' => array(),
                    'prop-filters' => array(),
                    'is-not-defined' => false,
                    'time-range' => array(
                        'start' => new \DateTime('20120103'),
                        'end'   => new \DateTime('20120104'),
                    ),
                ),
            ),
            'prop-filters' => array(),
            'is-not-defined' => false,
            'time-range' => null,
        );

        $this->assertEquals(array(
            "event2",
        ), $backend->calendarQuery($id, $filters));
    }

    function testCalendarQueryTimeRangeNoEnd() {
        $id = $this->generateId();

        $backend = $this->getBackend();
        $backend->createCalendarObject($id, "todo", "BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nEND:VTODO\r\nEND:VCALENDAR\r\n");
        $backend->createCalendarObject($id, "event", "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:20120101\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");
        $backend->createCalendarObject($id, "event2", "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nDTSTART:20120103\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n");

        $filters = array(
            'name' => 'VCALENDAR',
            'comp-filters' => array(
                array(
                    'name' => 'VEVENT',
                    'comp-filters' => array(),
                    'prop-filters' => array(),
                    'is-not-defined' => false,
                    'time-range' => array(
                        'start' => new \DateTime('20120102'),
                        'end' => null,
                    ),
                ),
            ),
            'prop-filters' => array(),
            'is-not-defined' => false,
            'time-range' => null,
        );

        $this->assertEquals(array(
            "event2",
        ), $backend->calendarQuery($id, $filters));
    }

    function testGetChanges() {
        $backend = $this->getBackend();
        $id = $backend->createCalendar(
            'principals/user1/userID',
            'bla',
            []
        );
        $result = $backend->getChangesForCalendar($id, null, 1);

        $this->assertEquals([
            'syncToken' => 1,
            'modified' => [],
            'deleted' => [],
            'added' => [],
        ], $result);

        $currentToken = $result['syncToken'];

        $dummyTodo = "BEGIN:VCALENDAR\r\nBEGIN:VTODO\r\nEND:VTODO\r\nEND:VCALENDAR\r\n";

        $backend->createCalendarObject($id, "todo1.ics", $dummyTodo);
        $backend->createCalendarObject($id, "todo2.ics", $dummyTodo);
        $backend->createCalendarObject($id, "todo3.ics", $dummyTodo);
        $backend->updateCalendarObject($id, "todo1.ics", $dummyTodo);
        $backend->deleteCalendarObject($id, "todo2.ics");

        $result = $backend->getChangesForCalendar($id, $currentToken, 1);

        $this->assertEquals([
            'syncToken' => 6,
            'modified'  => ["todo1.ics"],
            'deleted'   => ["todo2.ics"],
            'added'     => ["todo3.ics"],
        ], $result);

        $result = $backend->getChangesForCalendar($id, null, 1);

        $this->assertEquals([
            'syncToken' => 6,
            'modified' => [],
            'deleted' => [],
            'added' => ["todo1.ics", "todo3.ics"],
        ], $result);

        $result = $backend->getChangesForCalendar($id, $currentToken, "1");

        $this->assertEquals([
            'syncToken' => 6,
            'modified'  => ["todo1.ics"],
            'deleted'   => ["todo2.ics"],
            'added'     => ["todo3.ics"],
        ], $result);
    }

    function testCreateSubscriptions() {
        $props = [
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href('http://example.org/cal.ics', false),
            '{DAV:}displayname' => 'cal',
            '{http://apple.com/ns/ical/}refreshrate' => 'P1W',
            '{http://apple.com/ns/ical/}calendar-color' => '#FF00FFFF',
            '{http://calendarserver.org/ns/}subscribed-strip-todos' => true,
            //'{http://calendarserver.org/ns/}subscribed-strip-alarms' => true,
            '{http://calendarserver.org/ns/}subscribed-strip-attachments' => true,
        ];

        $backend = $this->getBackend();
        $id = $backend->createSubscription('principals/user1/userID', 'sub1', $props);

        $subs = $backend->getSubscriptionsForUser('principals/user1/userID');

        $expected = $props;
        $expected['id'] = $id;
        $expected['uri'] = 'sub1';
        $expected['principaluri'] = 'principals/user1/userID';

        unset($expected['{http://calendarserver.org/ns/}source']);
        $expected['source'] = 'http://example.org/cal.ics';

        $this->assertEquals(1, count($subs));
        foreach($expected as $k=>$v) {
            $this->assertEquals($subs[0][$k], $expected[$k]);
        }
    }

    /**
     * @expectedException \Sabre\DAV\Exception\Forbidden
     */
    function testCreateSubscriptionFail() {
        $props = [];
        $backend = $this->getBackend();
        $backend->createSubscription('principals/user1/userID', 'sub1', $props);
    }

    function testUpdateSubscriptions() {
        $props = [
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href('http://example.org/cal.ics', false),
            '{DAV:}displayname' => 'cal',
            '{http://apple.com/ns/ical/}refreshrate' => 'P1W',
            '{http://apple.com/ns/ical/}calendar-color' => '#FF00FFFF',
            '{http://calendarserver.org/ns/}subscribed-strip-todos' => true,
            //'{http://calendarserver.org/ns/}subscribed-strip-alarms' => true,
            '{http://calendarserver.org/ns/}subscribed-strip-attachments' => true,
        ];

        $backend = $this->getBackend();
        $id = $backend->createSubscription('principals/user1/userID', 'sub1', $props);

        $newProps = [
            '{DAV:}displayname' => 'new displayname',
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href('http://example.org/cal2.ics', false),
        ];

        $propPatch = new DAV\PropPatch($newProps);
        $backend->updateSubscription($id, $propPatch);
        $result = $propPatch->commit();

        $this->assertTrue($result);

        $subs = $backend->getSubscriptionsForUser('principals/user1/userID');

        $expected = array_merge($props, $newProps);
        $expected['id'] = $id;
        $expected['uri'] = 'sub1';
        $expected['principaluri'] = 'principals/user1/userID';

        unset($expected['{http://calendarserver.org/ns/}source']);
        $expected['source'] = 'http://example.org/cal2.ics';

        $this->assertEquals(1, count($subs));
        foreach($expected as $k=>$v) {
            $this->assertEquals($subs[0][$k], $expected[$k]);
        }
    }

    function testUpdateSubscriptionsFail() {
        $props = [
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href('http://example.org/cal.ics', false),
            '{DAV:}displayname' => 'cal',
            '{http://apple.com/ns/ical/}refreshrate' => 'P1W',
            '{http://apple.com/ns/ical/}calendar-color' => '#FF00FFFF',
            '{http://calendarserver.org/ns/}subscribed-strip-todos' => true,
            //'{http://calendarserver.org/ns/}subscribed-strip-alarms' => true,
            '{http://calendarserver.org/ns/}subscribed-strip-attachments' => true,
        ];

        $backend = $this->getBackend();
        $backend->createSubscription('principals/user1/userID', 'sub1', $props);

        $propPatch = new DAV\PropPatch([
            '{DAV:}displayname' => 'new displayname',
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href('http://example.org/cal2.ics', false),
            '{DAV:}unknown' => 'foo',
        ]);

        $backend->updateSubscription(1, $propPatch);
        $propPatch->commit();

        $this->assertEquals([
            '{DAV:}unknown' => 403,
            '{DAV:}displayname' => 424,
            '{http://calendarserver.org/ns/}source' => 424,
        ], $propPatch->getResult());
    }

    function testDeleteSubscriptions() {
        $props = [
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href('http://example.org/cal.ics', false),
            '{DAV:}displayname' => 'cal',
            '{http://apple.com/ns/ical/}refreshrate' => 'P1W',
            '{http://apple.com/ns/ical/}calendar-color' => '#FF00FFFF',
            '{http://calendarserver.org/ns/}subscribed-strip-todos' => true,
            //'{http://calendarserver.org/ns/}subscribed-strip-alarms' => true,
            '{http://calendarserver.org/ns/}subscribed-strip-attachments' => true,
        ];

        $backend = $this->getBackend();
        $id = $backend->createSubscription('principals/user1/userID', 'sub1', $props);

        $newProps = [
            '{DAV:}displayname' => 'new displayname',
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href('http://example.org/cal2.ics', false),
        ];

        $backend->deleteSubscription($id);

        $subs = $backend->getSubscriptionsForUser('principals/user1/userID');
        $this->assertEquals(0, count($subs));
    }

    function testGetSubscribers() {
        $subscriptionSource = 'http://example.org/cal.ics';
        $props = [
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href($subscriptionSource, false),
            '{DAV:}displayname' => 'cal',
            '{http://apple.com/ns/ical/}refreshrate' => 'P1W',
            '{http://apple.com/ns/ical/}calendar-color' => '#FF00FFFF',
            '{http://calendarserver.org/ns/}subscribed-strip-todos' => true,
            //'{http://calendarserver.org/ns/}subscribed-strip-alarms' => true,
            '{http://calendarserver.org/ns/}subscribed-strip-attachments' => true,
        ];
        $subscriptionUri = 'sub1';
        $subscriptionPrincipal = 'principals/user1/userID';
        $backend = $this->getBackend();
        $id = $backend->createSubscription($subscriptionPrincipal, $subscriptionUri, $props);

        $subscribers = $backend->getSubscribers($subscriptionSource);

        $this->assertEquals(1, count($subscribers));
        $this->assertEquals($subscriptionPrincipal, $subscribers[0]['principaluri']);
        $this->assertEquals($subscriptionUri, $subscribers[0]['uri']);
    }

    function testSchedulingMethods() {
        $backend = $this->getBackend();

        $calData = "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n";

        $backend->createSchedulingObject(
            'principals/user1/userID',
            'schedule1.ics',
            $calData
        );

        $expected = [
            'calendardata' => $calData,
            'uri' => 'schedule1.ics',
            'etag' => '"' . md5($calData) . '"',
            'size' => strlen($calData)
        ];

        $result = $backend->getSchedulingObject('principals/user1/userID', 'schedule1.ics');
        foreach($expected as $k=>$v) {
            $this->assertArrayHasKey($k, $result);
            $this->assertEquals($v, $result[$k]);
        }

        $results = $backend->getSchedulingObjects('principals/user1/userID');

        $this->assertEquals(1, count($results));
        $result = $results[0];
        foreach($expected as $k=>$v) {
            $this->assertEquals($v, $result[$k]);
        }

        $backend->deleteSchedulingObject('principals/user1/userID', 'schedule1.ics');
        $result = $backend->getSchedulingObject('principals/user1/userID', 'schedule1.ics');

        $this->assertNull($result);
    }

    function testGetInvites() {
        $backend = $this->getBackend();
        $backend->createCalendar('principals/user1/userID', 'somerandomid', []);
        $calendar = $backend->getCalendarsForUser('principals/user1/userID')[0];
        $result = $backend->getInvites($calendar['id']);

        $expected = [
            new Sharee([
                'href'         => 'principals/user1/userID',
                'principal'    => 'principals/user1/userID',
                'access'       => \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER,
                'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED
            ])
        ];
        $this->assertEquals($expected, $result);
    }

    function testUpdateInvites() {
        $backend = $this->getBackend();

        // creating a new calendar
        $backend->createCalendar('principals/user1/userID', 'somerandomid', []);
        $calendar = $backend->getCalendarsForUser('principals/user1/userID')[0];
        $ownerSharee = new Sharee([
            'href'         => 'principals/user1/userID',
            'principal'    => 'principals/user1/userID',
            'access'       => \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER,
            'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED,
        ]);

        // Add a new invite
        $backend->updateInvites(
            $calendar['id'],
            [
                new Sharee([
                    'href'         => 'mailto:user@example.org',
                    'principal'    => 'principals/user2/userID',
                    'access'       => \Sabre\DAV\Sharing\Plugin::ACCESS_READ,
                    'properties'   => ['{DAV:}displayname' => 'User 2'],
                ])
            ]
        );
        $result = $backend->getInvites($calendar['id']);
        $expected = [
            $ownerSharee,
            new Sharee([
                'href'         => 'mailto:user@example.org',
                'principal'    => 'principals/user2/userID',
                'access'       => \Sabre\DAV\Sharing\Plugin::ACCESS_READ,
                'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_NORESPONSE,
                'properties'   => [
                    '{DAV:}displayname' => 'User 2',
                ],
            ])
        ];
        $this->assertEquals($expected, $result);

        // Checking calendar_instances too
        $expectedCalendar = [
            'id'                                     => 'foo',
            'principaluri'                           => 'principals/user2/userID',
            '{http://calendarserver.org/ns/}getctag' => 'http://sabre.io/ns/sync/1',
            '{http://sabredav.org/ns}sync-token'     => '1',
            'share-access'                           => \Sabre\DAV\Sharing\Plugin::ACCESS_READ,
            'read-only'                              => true,
            'share-resource-uri'                     => 'bar',
        ];
        $calendars = $backend->getCalendarsForUser('principals/user2/userID');
        foreach ($expectedCalendar as $k => $v) {
            if ($k == 'id') {
                $this->assertEquals($calendars[0][$k][0], $calendar['id'][0]);
            } else if ($k == 'share-resource-uri') {
                $this->assertEquals(strpos($calendars[0][$k], '/ns/share/'), 0);
            } else {
                $this->assertEquals(
                    $v,
                    $calendars[0][$k],
                    "Key " . $k . " in calendars array did not have the expected value."
                );
            }
        }

        // Updating an invite
        $backend->updateInvites(
            $calendar['id'],
            [
                new Sharee([
                    'href'         => 'mailto:user@example.org',
                    'principal'    => 'principals/user2/userID',
                    'access'       => \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE,
                ])
            ]
        );
        $result = $backend->getInvites($calendar['id']);
        $expected = [
            $ownerSharee,
            new Sharee([
                'href'         => 'mailto:user@example.org',
                'principal'    => 'principals/user2/userID',
                'access'       => \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE,
                'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_NORESPONSE,
                'properties'   => [
                    '{DAV:}displayname' => 'User 2',
                ],
            ])
        ];
        $this->assertEquals($expected, $result);

        // Removing an invite
        $backend->updateInvites(
            $calendar['id'],
            [
                new Sharee([
                    'href'         => 'mailto:user@example.org',
                    'access'       => \Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS,
                ])
            ]
        );
        $result = $backend->getInvites($calendar['id']);
        $expected = [
            $ownerSharee
        ];
        $this->assertEquals($expected, $result);

        // Preventing the owner share from being removed
        $backend->updateInvites(
            $calendar['id'],
            [
                new Sharee([
                    'href'         => 'principals/user2/userID',
                    'access'       => \Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS,
                ])
            ]
        );
        $result = $backend->getInvites($calendar['id']);
        $expected = [
            new Sharee([
                'href'         => 'principals/user1/userID',
                'principal'    => 'principals/user1/userID',
                'access'       => \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER,
                'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED,
            ]),
        ];
        $this->assertEquals($expected, $result);
    }

    function testGetCalendarWithNoPublicRight() {
        $publicRight = 'my public right';
        $backend = $this->getBackend();

        $newId = $backend->createCalendar('principals/user2/userID','somerandomid',array());

        $this->assertEquals('', $backend->getCalendarPublicRight($newId));
    }

    function testSetCalendarPublicRight() {
        $publicRight = 'my public right';
        $backend = $this->getBackend();

        $newId = $backend->createCalendar('principals/user2/userID','somerandomid',array());
        $backend->saveCalendarPublicRight($newId, $publicRight);

        $this->assertEquals($publicRight, $backend->getCalendarPublicRight($newId));
    }

    function testUpdateCalendarPublicRight() {
        $publicRight = 'my public right';
        $backend = $this->getBackend();

        $newId = $backend->createCalendar('principals/user2/userID','somerandomid',array());
        $backend->saveCalendarPublicRight($newId, $publicRight);

        $this->assertEquals($publicRight, $backend->getCalendarPublicRight($newId));

        $newPublicRight = 'my new public right';
        $backend->saveCalendarPublicRight($newId, $newPublicRight);

        $this->assertEquals($newPublicRight, $backend->getCalendarPublicRight($newId));
    }

    function testRemoveCalendarPublicRight() {
        $publicRight = 'my public right';
        $backend = $this->getBackend();

        $newId = $backend->createCalendar('principals/user2/userID','somerandomid',array());
        $backend->saveCalendarPublicRight($newId, $publicRight);

        $this->assertEquals($publicRight, $backend->getCalendarPublicRight($newId));

        $backend->saveCalendarPublicRight($newId, null);

        $this->assertEquals('', $backend->getCalendarPublicRight($newId));
    }

    function testSetCalendarPublicRightWithWrongAttribute() {
        $backend = $this->getBackend();
        try {
            $backend->saveCalendarPublicRight(null, '');
        } catch(\Exception $exception) {
            $this->assertTrue($exception instanceof \LogicException);
            $this->assertEquals($exception->getMessage(), 'The value passed to $calendarId is expected to be an array with a calendarId and an instanceId');
        }
    }
}
