<?php

namespace ESN\CalDAV;

/**
 * @medium
 */
class CalendarHomeTest extends \PHPUnit_Framework_TestCase {

    protected function getBackend() {
        $mc = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $db = $mc->{ESN_MONGO_SABREDB};
        $db->drop();

        $principalBackendMock = $this->createMock(\Sabre\DAVACL\PrincipalBackend\BackendInterface::class);

        return new \ESN\CalDAV\Backend\Esn($db, $principalBackendMock);
    }

    function testChildTypeReturned() {
        $backend = $this->getBackend();
        $calendar = new \ESN\CalDAV\CalendarHome($backend, ['uri' => 'principals/user2/userID']);

        $this->assertTrue($calendar->getChild('userID') instanceof \ESN\CalDAV\SharedCalendar);
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
