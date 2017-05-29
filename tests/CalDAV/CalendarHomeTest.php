<?php

namespace ESN\CalDAV;

/**
 * @medium
 */
class CalendarHomeTest extends \PHPUnit_Framework_TestCase {

    protected function getBackend() {
        $mc = new \MongoClient(ESN_MONGO_SABREURI);
        $db = $mc->selectDB(ESN_MONGO_SABREDB);
        $db->drop();
        return new \ESN\CalDAV\Backend\Esn($db);
    }

    function testChildTypeReturned() {
        $backend = $this->getBackend();
        $calendar = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2/userID']);

        $this->assertTrue($calendar->getChild('events') instanceof \ESN\CalDAV\SharedCalendar);
    }

    function testGetAcl() {
        $backend = $this->getBackend();
        $calendar = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2/userID']);

        $expected = [
            [
                'privilege' => '{DAV:}read',
                'principal' => '{DAV:}authenticated',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => 'principals/user2/userID',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/user2/userID/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => 'principals/user2/userID/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/user2/userID/calendar-proxy-read',
                'protected' => true,
            ],

        ];

        $this->assertEquals($calendar->getACL(), $expected);
    }
}
