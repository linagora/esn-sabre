<?php

namespace ESN\CalDAV\Backend;

/**
 * @medium
 */
class EsnTest extends \PHPUnit_Framework_TestCase {
    protected function getBackend() {
        $mc = new \MongoClient(ESN_MONGO_SABREURI);
        $db = $mc->selectDB(ESN_MONGO_SABREDB);
        $db->drop();
        return new Esn($db);
    }

    function testGetCalendarsForUserNoCalendars() {
        $backend = $this->getBackend();
        $calendars = $backend->getCalendarsForUser('principals/user/54b64eadf6d7d8e41d263e0f');

        $elementCheck = array(
            'uri'               => '54b64eadf6d7d8e41d263e0f',
            '{DAV:}displayname' => 'Events',
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => '',
            '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp('opaque'),
        );

        $this->assertInternalType('array', $calendars);
        $this->assertEquals(1, count($calendars));

        foreach ($elementCheck as $name => $value) {
            $this->assertArrayHasKey($name, $calendars[0]);
            $this->assertEquals($value, $calendars[0][$name]);
        }
    }
}
