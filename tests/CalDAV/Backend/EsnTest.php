<?php

namespace ESN\CalDAV\Backend;

require_once ESN_TEST_BASE . '/DAV/MongoFindCounter.php';

/**
 * @medium
 */
#[\AllowDynamicProperties]
class EsnTest extends \PHPUnit\Framework\TestCase {
    protected function getBackend() {
        $mc = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $db = $mc->{ESN_MONGO_SABREDB};
        $db->drop();

        $principalBackendMock = $this->getMockBuilder(\Sabre\DAVACL\PrincipalBackend\BackendInterface::class)->onlyMethods(['getPrincipalByPath', 'getPrincipalsByPrefix', 'updatePrincipal', 'searchPrincipals', 'findByUri', 'getGroupMemberSet', 'getGroupMembership', 'setGroupMemberSet'])->getMock();
        $principalBackendMock->expects($this->any())->method('getPrincipalByPath')->willReturn(['{DAV:}displayname' => 'resourceName']);

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

    function testDefaultCalendarShouldBeCreatedWhenAnotherCalendarAlreadyExists() {
        $backend = $this->getBackend();
        $principalUri = 'principals/user/54b64eadf6d7d8e41d263e0f';
        // A calendar named #default is not necessarily the principal's own main calendar.
        $backend->createCalendar($principalUri, 'other-calendar', ['{DAV:}displayname' => '#default']);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $calendars = $backend->getCalendarsForUser($principalUri);

            $this->assertSame(['54b64eadf6d7d8e41d263e0f', 'other-calendar'], array_column($calendars, 'uri'));
        }
    }

    function testDefaultCalendarShouldNotBeDuplicatedOrResetOnRepeatedListings() {
        $backend = $this->getBackend();
        $principalUri = 'principals/user/54b64eadf6d7d8e41d263e0f';
        $backend->createCalendar($principalUri, '54b64eadf6d7d8e41d263e0f', ['{DAV:}displayname' => 'My calendar']);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $calendars = $backend->getCalendarsForUser($principalUri);

            $this->assertCount(1, $calendars);
            $this->assertSame('My calendar', $calendars[0]['{DAV:}displayname']);
        }
    }

    function testEnsureDefaultCalendarCreatesItWhenMissing() {
        $backend = $this->getBackend();
        $principalUri = 'principals/users/54b64eadf6d7d8e41d263e0f';
        $backend->createCalendar($principalUri, 'other-calendar', []);

        $backend->ensureDefaultCalendar($principalUri);

        $this->assertTrue($backend->calendarInstanceExists($principalUri, '54b64eadf6d7d8e41d263e0f'));
    }

    function testEnsureDefaultCalendarKeepsTheExistingOne() {
        $backend = $this->getBackend();
        $principalUri = 'principals/users/54b64eadf6d7d8e41d263e0f';
        $backend->createCalendar($principalUri, '54b64eadf6d7d8e41d263e0f', ['{DAV:}displayname' => 'My calendar']);

        $backend->ensureDefaultCalendar($principalUri);

        $calendars = $backend->getCalendarsForUser($principalUri);
        $this->assertCount(1, $calendars);
        $this->assertSame('My calendar', $calendars[0]['{DAV:}displayname']);
    }

    function testEnsureDefaultCalendarOnlyReadsTheDefaultInstanceWhenItExists() {
        $backend = $this->getBackend();
        $principalUri = 'principals/users/54b64eadf6d7d8e41d263e0f';
        $backend->ensureDefaultCalendar($principalUri);

        $instanceQueries = \ESN\DAV\MongoFindCounter::count('calendarinstances', function () use ($backend, $principalUri) {
            $calendarQueries = \ESN\DAV\MongoFindCounter::count('calendars', function () use ($backend, $principalUri) {
                $backend->ensureDefaultCalendar($principalUri);
            });
            $this->assertSame(0, $calendarQueries);
        });

        $this->assertSame(1, $instanceQueries);
    }

    function testEnsureDefaultCalendarNamesTheResourceCalendar() {
        $backend = $this->getBackend();

        $backend->ensureDefaultCalendar('principals/resources/resourceId');

        $calendars = $backend->getCalendarsForUser('principals/resources/resourceId');
        $this->assertSame('resourceName', $calendars[0]['{DAV:}displayname']);
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

    function testTeamCalendarShouldBeCreatedWhenRequesting() {
        $backend = $this->getBackend();
        $calendars = $backend->getCalendarsForUser('principals/team-calendars/teamCalendarId');

        $elementCheck = array(
            'uri'               => 'teamCalendarId',
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
