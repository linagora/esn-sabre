<?php

namespace ESN\CalDAV\Backend;

/**
 * @medium
 */
class EsnTest extends \PHPUnit\Framework\TestCase {
    protected function getBackend() {
        $mc = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $db = $mc->{ESN_MONGO_SABREDB};
        $db->drop();

        $principalBackendMock = $this->getMockBuilder(\Sabre\DAVACL\PrincipalBackend\BackendInterface::class)->setMethods(['getPrincipalByPath', 'getPrincipalsByPrefix', 'updatePrincipal', 'searchPrincipals', 'findByUri', 'getGroupMemberSet', 'getGroupMembership', 'setGroupMemberSet'])->getMock();
        $principalBackendMock->expects($this->any())->method('getPrincipalByPath')->will($this->returnValue(['{DAV:}displayname' => 'resourceName']));

        return new Esn($db, $principalBackendMock);
    }

    function testGetCalendarsForUserNoCalendars() {
        $backend = $this->getBackend();
        $calendars = $backend->getCalendarsForUser('principals/user/54b64eadf6d7d8e41d263e0f');

        $elementCheck = array(
            'uri'               => '54b64eadf6d7d8e41d263e0f',
            '{DAV:}displayname' => '#default',
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => '',
            '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp('opaque'),
        );

        $this->assertIsArray( $calendars);
        $this->assertEquals(1, count($calendars));

        foreach ($elementCheck as $name => $value) {
            $this->assertArrayHasKey($name, $calendars[0]);
            $this->assertEquals($value, $calendars[0][$name]);
        }
    }

    function testResourceCalendarShouldBeCreatedWhenRequesting() {
        $backend = $this->getBackend();
        $calendars = $backend->getCalendarsForUser('principals/resources/resourceId');

        $elementCheck = array(
            'uri'               => 'resourceId',
            '{DAV:}displayname' => 'resourceName',
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => '',
            '{urn:ietf:params:xml:ns:caldav}schedule-calendar-transp' => new \Sabre\CalDAV\Xml\Property\ScheduleCalendarTransp('opaque'),
        );

        $this->assertIsArray( $calendars);
        $this->assertEquals(1, count($calendars));

        foreach ($elementCheck as $name => $value) {
            $this->assertArrayHasKey($name, $calendars[0]);
            $this->assertEquals($value, $calendars[0][$name]);
        }
    }
}
