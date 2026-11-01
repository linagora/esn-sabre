<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * COPY and MOVE of PRIVATE / CONFIDENTIAL events by users their calendar hides the details from.
 *
 * The current user (54b64eadf6d7d8e41d263e0f) reads publicCal1 of 54b64eadf6d7d8e41d263e0e through
 * its public right, which hides the private events of publicCal1 from them.
 *
 * @medium
 */
class PrivateEventPluginTest extends \ESN\DAV\ServerMock {

    const PUBLIC_CAL = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1';
    const OWN_CAL = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1';

    function setUp(): void {
        parent::setUp();

        $this->server->addPlugin(new PrivateEventPlugin());
    }

    private function event($uid, $class) {
        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//test//EN',
            'BEGIN:VEVENT', 'UID:' . $uid, 'DTSTAMP:20260101T000000Z',
            'DTSTART:20260101T090000Z', 'DTEND:20260101T100000Z',
            'CLASS:' . $class, 'SUMMARY:Doctor', 'LOCATION:Hospital',
            'END:VEVENT', 'END:VCALENDAR', '']);
    }

    private function copyOrMove($method, $source, $destination) {
        return $this->request([
            'REQUEST_METHOD'   => $method,
            'REQUEST_URI'      => $source,
            'HTTP_DESTINATION' => $destination,
        ]);
    }

    private function get($uri) {
        return $this->request([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => $uri,
        ]);
    }

    function testCopyOfHiddenPrivateEventIsForbidden() {
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'secret.ics', $this->event('secret', 'PRIVATE'));

        $response = $this->copyOrMove('COPY', self::PUBLIC_CAL . '/secret.ics', self::OWN_CAL . '/copy.ics');

        $this->assertEquals(403, $response->status);
        $this->assertNull($this->caldavBackend->getCalendarObject($this->cal['id'], 'copy.ics'));
    }

    function testCopyOfHiddenConfidentialEventIsForbidden() {
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'secret.ics', $this->event('secret', 'CONFIDENTIAL'));

        $response = $this->copyOrMove('COPY', self::PUBLIC_CAL . '/secret.ics', self::OWN_CAL . '/copy.ics');

        $this->assertEquals(403, $response->status);
        $this->assertNull($this->caldavBackend->getCalendarObject($this->cal['id'], 'copy.ics'));
    }

    function testCopyOfHiddenPrivateEventThroughSubscriptionIsForbidden() {
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'secret.ics', $this->event('secret', 'PRIVATE'));

        $response = $this->copyOrMove(
            'COPY',
            '/calendars/54b64eadf6d7d8e41d263e0f/subscription1/secret.ics',
            self::OWN_CAL . '/copy.ics'
        );

        $this->assertEquals(403, $response->status);
        $this->assertNull($this->caldavBackend->getCalendarObject($this->cal['id'], 'copy.ics'));
    }

    function testCopyOfPublicEventIsAllowed() {
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'meeting.ics', $this->event('meeting', 'PUBLIC'));

        $response = $this->copyOrMove('COPY', self::PUBLIC_CAL . '/meeting.ics', self::OWN_CAL . '/copy.ics');

        $this->assertEquals(201, $response->status);
        $this->assertStringContainsString('Hospital', $this->get(self::OWN_CAL . '/copy.ics')->getBodyAsString());
    }

    function testOwnerCanCopyTheirPrivateEvent() {
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'secret.ics', $this->event('secret', 'PRIVATE'));

        $response = $this->copyOrMove(
            'COPY',
            self::OWN_CAL . '/secret.ics',
            '/calendars/54b64eadf6d7d8e41d263e0f/user1Calendar2/copy.ics'
        );

        $this->assertEquals(201, $response->status);
        $this->assertStringContainsString(
            'Hospital',
            $this->get('/calendars/54b64eadf6d7d8e41d263e0f/user1Calendar2/copy.ics')->getBodyAsString()
        );
    }

    function testMoveOfHiddenPrivateEventOutOfItsCalendarIsForbidden() {
        $this->caldavBackend->saveCalendarPublicRight($this->calUser2['id'], '{DAV:}write', [
            'principaluri' => $this->calUser2['principaluri'],
            'uri' => $this->calUser2['uri']
        ]);
        $this->caldavBackend->createCalendarObject($this->calUser2['id'], 'secret.ics', $this->event('secret', 'PRIVATE'));

        $response = $this->copyOrMove(
            'MOVE',
            '/calendars/54b64eadf6d7d8e41d263e0e/calendar2/secret.ics',
            self::OWN_CAL . '/moved.ics'
        );

        $this->assertEquals(403, $response->status);
        $this->assertNull($this->caldavBackend->getCalendarObject($this->cal['id'], 'moved.ics'));
        $this->assertNotNull($this->caldavBackend->getCalendarObject($this->calUser2['id'], 'secret.ics'));
    }
}
