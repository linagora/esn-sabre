<?php

namespace ESN\CalDAV;

require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CalDAV/Backend/MockSharing.php';

/**
 * @medium
 */
class SharedCalendarTest extends \PHPUnit_Framework_TestCase {

    protected function getBackend() {
        $mc = new \MongoClient(ESN_MONGO_SABREURI);
        $db = $mc->selectDB(ESN_MONGO_SABREDB);
        $db->drop();

        return new \ESN\CalDAV\Backend\Esn($db);
    }

    function testGetACLIfNoPublicRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2']);
        $calendarSabre = new \Sabre\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2']);

        $sharedCalendarESN =  $calendarESN->getChild('events');
        $sharedCalendarSabre =  $calendarSabre->getChild('events');

        $this->assertTrue($sharedCalendarESN->getACL() == $sharedCalendarSabre->getACL());
    }


    function testGetACLAdministrationShareAccess() {
        $props = [
            'id'                                        => 1,
            '{http://calendarserver.org/ns/}shared-url' => 'calendars/owner/original',
            '{http://sabredav.org/ns}owner-principal'   => 'principals/owner',
            '{http://sabredav.org/ns}read-only'         => false,
            'share-access'                              => \ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION,
            'principaluri'                              => 'principals/sharee',
        ];

        $backend = new SimpleBackendMock([$props], [], []);

        $sharedCalendar = new SharedCalendar(new \Sabre\CalDAV\SharedCalendar($backend, $props));

        $expected = [
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/sharee',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/sharee' . '/calendar-proxy-read',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/sharee' . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => 'principals/sharee',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => 'principals/sharee' . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write-properties',
                'principal' => 'principals/sharee',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write-properties',
                'principal' => 'principals/sharee' . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read-acl',
                'principal' => 'principals/sharee',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read-acl',
                'principal' => 'principals/sharee' . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write-acl',
                'principal' => 'principals/sharee',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write-acl',
                'principal' => 'principals/sharee' . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{' . Plugin::NS_CALDAV . '}read-free-busy',
                'principal' => '{DAV:}authenticated',
                'protected' => true,
            ]
        ];

        $this->assertEquals($sharedCalendar->getACL(), $expected);
    }

    function testGetACLFreeBusyShareAccess() {
        $props = [
            'id'                                        => 1,
            '{http://calendarserver.org/ns/}shared-url' => 'calendars/owner/original',
            '{http://sabredav.org/ns}owner-principal'   => 'principals/owner',
            '{http://sabredav.org/ns}read-only'         => false,
            'share-access'                              => \ESN\DAV\Sharing\Plugin::ACCESS_FREEBUSY          ,
            'principaluri'                              => 'principals/sharee',
        ];

        $backend = new SimpleBackendMock([$props], [], []);

        $sharedCalendar = new SharedCalendar(new \Sabre\CalDAV\SharedCalendar($backend, $props));

        $expected = [
            [
                'privilege' => '{' . Plugin::NS_CALDAV . '}read-free-busy',
                'principal' => '{DAV:}authenticated',
                'protected' => true,
            ]
        ];

        $this->assertEquals($sharedCalendar->getACL(), $expected);
    }


    function testGetChildACLAdministrationShareAccess() {
        $props = [
            'id'                                        => 1,
            '{http://calendarserver.org/ns/}shared-url' => 'calendars/owner/original',
            '{http://sabredav.org/ns}owner-principal'   => 'principals/owner',
            '{http://sabredav.org/ns}read-only'         => false,
            'share-access'                              => \ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION,
            'principaluri'                              => 'principals/sharee',
        ];

        $backend = new SimpleBackendMock([$props], [], []);

        $sharedCalendar = new SharedCalendar(new \Sabre\CalDAV\SharedCalendar($backend, $props));

        $expected = [
            [
                'privilege' => '{DAV:}write',
                'principal' => 'principals/sharee',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => 'principals/sharee/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/sharee',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/sharee/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/sharee/calendar-proxy-read',
                'protected' => true,
            ],
            [
                'privilege' => '{' . Plugin::NS_CALDAV . '}read-free-busy',
                'principal' => '{DAV:}authenticated',
                'protected' => true,
            ]
        ];

        $this->assertEquals($sharedCalendar->getChildACL(), $expected);
    }

    function getAuthentificated($calendar) {
        $acl = $calendar->getACL();

        $authenticatedACE = null;
        foreach ($acl as &$ace) {
            if (strcmp($ace['principal'], '{DAV:}authenticated') === 0) {
                $authenticatedACE = $ace;
            }
        }

        return $authenticatedACE;
    }

    function testGetACLCalendarWithPublicRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2']);
        $calendarSabre = new \Sabre\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2']);

        $sharedCalendarESN =  $calendarESN->getChild('events');
        $sharedCalendarSabre =  $calendarSabre->getChild('events');

        $childACLOrig = $sharedCalendarSabre->getChildACL();
        array_push($childACLOrig, $this->getAuthentificated($sharedCalendarESN));

        $this->assertTrue($childACLOrig == $sharedCalendarESN->getChildACL());
    }

    function testSavePublicRight() {
        $backend = $this->getBackend();

        $calendarESN = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2']);
        $sharedCalendarESN =  $calendarESN->getChild('events');

        $privilege = 'droit';
        $sharedCalendarESN->savePublicRight($privilege);

        $acl = $sharedCalendarESN->getACL();
        $isPresent = true;
        foreach ($acl as &$ace) {
            if (strcmp($ace['privilege'], $privilege) === 0) {
                $isPresent = true;
            }
        }

        $this->assertTrue($isPresent);
    }
}

class SimpleBackendMock extends \Sabre\CalDAV\Backend\MockSharing {
    function getCalendarPublicRight() {
        return null;
    }
}