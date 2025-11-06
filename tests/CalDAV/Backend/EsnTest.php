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

    /**
     * Test for issue #206: User with delegated calendar but no default calendar
     *
     * Scenario: User becomes a resource admin (gets delegated calendar) before
     * accessing their personal calendar. Should still create default calendar.
     */
    function testDefaultCalendarCreatedEvenWithDelegatedCalendar() {
        $backend = $this->getBackend();
        $userId = '54b64eadf6d7d8e41d263e0f';
        $principalUri = 'principals/user/' . $userId;

        // Simulate a delegated calendar (e.g., from being a resource admin)
        // by manually creating a calendar with a different URI
        $backend->createCalendar($principalUri, 'delegated-resource-calendar', [
            '{DAV:}displayname' => 'Delegated Resource Calendar'
        ]);

        // Now call getCalendarsForUser - it should create the default calendar
        // in addition to the existing delegated one
        $calendars = $backend->getCalendarsForUser($principalUri);

        // Should have 2 calendars now: delegated + default
        $this->assertIsArray($calendars);
        $this->assertEquals(2, count($calendars), 'Should have both delegated and default calendar');

        // Find the default calendar
        $defaultCalendar = null;
        $delegatedCalendar = null;

        foreach ($calendars as $calendar) {
            if ($calendar['uri'] === $userId || $calendar['uri'] === Esn::EVENTS_URI) {
                $defaultCalendar = $calendar;
            } elseif ($calendar['uri'] === 'delegated-resource-calendar') {
                $delegatedCalendar = $calendar;
            }
        }

        $this->assertNotNull($defaultCalendar, 'Default calendar should be created');
        $this->assertNotNull($delegatedCalendar, 'Delegated calendar should still exist');
        $this->assertEquals($userId, $defaultCalendar['uri'], 'Default calendar URI should match userId');
    }
}
