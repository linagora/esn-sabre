<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * Integration tests for MOVE operations with delegated calendars.
 *
 * Reproduces the scenario described in https://github.com/linagora/esn-sabre/issues/273:
 * - Scenario 1: Bob (delegate) moves his own event into Camille's (owner) delegated calendar → should succeed with 201
 * - Scenario 2: Bob (delegate) moves Camille's event from her delegated calendar into his own → should succeed with 201
 *
 * @medium
 */
#[\AllowDynamicProperties]
class MoveWithDelegationTest extends \ESN\DAV\ServerMock {

    protected $simpleEventData =
        "BEGIN:VCALENDAR\r\n" .
        "VERSION:2.0\r\n" .
        "BEGIN:VEVENT\r\n" .
        "UID:move-test-uid\r\n" .
        "DTSTART:20260101T000000Z\r\n" .
        "DTEND:20260101T010000Z\r\n" .
        "SUMMARY:Move Test Event\r\n" .
        "END:VEVENT\r\n" .
        "END:VCALENDAR\r\n";

    /**
     * Override ServerMock::delegateCalendar() which uses a wrong URI.
     * Instead call the backend directly to properly share delegatedCal1 with user2.
     */
    protected function delegateCalendar() {
        $sharee = new \Sabre\DAV\Xml\Element\Sharee([
            'href'        => 'mailto:johndoe@example.org',
            'access'      => \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE,
            'principal'   => 'principals/users/54b64eadf6d7d8e41d263e0e',
            'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED,
        ]);

        $this->caldavBackend->updateInvites($this->delegatedCal['id'], [$sharee]);
    }

    /**
     * Scenario 1 from issue #273:
     * Bob (user2/delegate) moves his own event from his calendar into Camille's delegated calendar.
     * Should return 201 (Created), not 403.
     */
    function testMoveEventFromDelegateCalendarToDelegatedCalendar() {
        // Create an event in user2's own calendar
        $this->caldavBackend->createCalendarObject(
            $this->calUser2['id'],
            'move-event.ics',
            $this->simpleEventData
        );

        // Switch auth to user2 (Bob, the delegate)
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0e');

        // MOVE user2's event from his calendar to user1's delegated calendar
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'   => 'MOVE',
            'REQUEST_URI'      => '/calendars/54b64eadf6d7d8e41d263e0e/calendar2/move-event.ics',
            'HTTP_DESTINATION' => '/calendars/54b64eadf6d7d8e41d263e0f/delegatedCal1/move-event.ics',
            'HTTP_OVERWRITE'   => 'T',
        ]);

        $response = $this->request($request);

        $this->assertEquals(
            201,
            $response->getStatus(),
            'Bob (delegate) should be able to MOVE his own event into Camille\'s delegated calendar'
        );
    }

    /**
     * Scenario 2 from issue #273:
     * Bob (user2/delegate) moves an event from Camille's delegated calendar into his own calendar.
     * Should return 201 (Created), not 403.
     */
    function testMoveEventFromDelegatedCalendarToDelegateCalendar() {
        // Create an event in user1's delegated calendar
        $this->caldavBackend->createCalendarObject(
            $this->delegatedCal['id'],
            'owner-event.ics',
            $this->simpleEventData
        );

        // Switch auth to user2 (Bob, the delegate)
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0e');

        // MOVE user1's event from the delegated calendar to user2's own calendar
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'   => 'MOVE',
            'REQUEST_URI'      => '/calendars/54b64eadf6d7d8e41d263e0f/delegatedCal1/owner-event.ics',
            'HTTP_DESTINATION' => '/calendars/54b64eadf6d7d8e41d263e0e/calendar2/owner-event.ics',
            'HTTP_OVERWRITE'   => 'T',
        ]);

        $response = $this->request($request);

        $this->assertEquals(
            201,
            $response->getStatus(),
            'Bob (delegate) should be able to MOVE an event from Camille\'s delegated calendar into his own calendar'
        );
    }

    /**
     * Read-only delegate must NOT be able to MOVE events to the delegated calendar.
     * Should return 403 Forbidden.
     */
    function testMoveEventToDelegatedCalendarFailsWithReadOnlyRight() {
        // Re-share delegatedCal1 with user2 as READ-only
        $sharee = new \Sabre\DAV\Xml\Element\Sharee([
            'href'        => 'mailto:johndoe@example.org',
            'access'      => \Sabre\DAV\Sharing\Plugin::ACCESS_READ,
            'principal'   => 'principals/users/54b64eadf6d7d8e41d263e0e',
            'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED,
        ]);
        $this->caldavBackend->updateInvites($this->delegatedCal['id'], [$sharee]);

        // Create an event in user2's own calendar
        $this->caldavBackend->createCalendarObject(
            $this->calUser2['id'],
            'move-event.ics',
            $this->simpleEventData
        );

        // Switch auth to user2 (Bob, read-only delegate)
        $this->authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0e');

        // MOVE user2's event to user1's delegated calendar (read-only) — must fail
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'   => 'MOVE',
            'REQUEST_URI'      => '/calendars/54b64eadf6d7d8e41d263e0e/calendar2/move-event.ics',
            'HTTP_DESTINATION' => '/calendars/54b64eadf6d7d8e41d263e0f/delegatedCal1/move-event.ics',
            'HTTP_OVERWRITE'   => 'T',
        ]);

        $response = $this->request($request);

        $this->assertEquals(
            403,
            $response->getStatus(),
            'Read-only delegate must NOT be able to MOVE events into the owner\'s calendar'
        );
    }
}
