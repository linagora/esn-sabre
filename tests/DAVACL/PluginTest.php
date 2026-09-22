<?php

namespace ESN\DAVACL;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class PluginTest extends \ESN\DAV\ServerMock {
    function testPROPFINDPrincipal() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/principals/users/54b64eadf6d7d8e41d263e0f',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(200, $response->status);
        $this->assertEquals($jsonResponse, [
          'alternate-URI-set' => [
            'mailto:robertocarlos@realmadrid.com'
          ],
          'principal-URL' => 'principals/users/54b64eadf6d7d8e41d263e0f/',
          'group-member-set' => [],
          'group-membership' => ['principals/domains/' . SERVER_MOCK_DOMAIN_ID . '/']
        ]);
    }

    private function multiget($calendarPath, array $hrefs) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_DEPTH'        => '1',
            'REQUEST_URI'       => $calendarPath,
        ));
        $request->setBody('<c:calendar-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"><d:prop><d:getetag/></d:prop>'
            . implode('', array_map(function ($href) { return '<d:href>' . $href . '</d:href>'; }, $hrefs))
            . '</c:calendar-multiget>');

        return $this->request($request);
    }

    function testMultigetOfOwnCalendarLooksUpTheHrefsOnce() {
        $hrefs = [];
        for ($i = 0; $i < 20; $i++) {
            $this->caldavBackend->createCalendarObject($this->cal['id'], 'multiget' . $i . '.ics',
                str_replace('UID:event2', 'UID:multiget' . $i, $this->caldavCalendarObjects['event2.ics']));
            $hrefs[] = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/multiget' . $i . '.ics';
        }
        $hrefs[] = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/missing.ics';

        $counter = new CalendarObjectQueryCounter();
        \MongoDB\Driver\Monitoring\addSubscriber($counter);
        try {
            $response = $this->multiget('/calendars/54b64eadf6d7d8e41d263e0f/calendar1/', $hrefs);
        } finally {
            \MongoDB\Driver\Monitoring\removeSubscriber($counter);
        }

        $this->assertEquals(207, $response->status);
        // Sabre leaves the missing href out of the response
        $this->assertEquals(20, substr_count($response->getBodyAsString(), '<d:response>'));
        // One lookup for the ACL check, one for the report itself, whatever the number of hrefs
        $this->assertLessThanOrEqual(2, $counter->queries);
    }

    function testMultigetOfUnreadableCalendarIsForbidden() {
        $this->caldavBackend->createCalendarObject($this->calUser2['id'], 'event1.ics', $this->caldavCalendarObjects['event1.ics']);
        $this->caldavBackend->createCalendarObject($this->calUser2['id'], 'event2.ics', $this->caldavCalendarObjects['event2.ics']);

        $response = $this->multiget('/calendars/54b64eadf6d7d8e41d263e0e/calendar2/', [
            '/calendars/54b64eadf6d7d8e41d263e0e/calendar2/event1.ics',
            '/calendars/54b64eadf6d7d8e41d263e0e/calendar2/event2.ics',
        ]);

        $this->assertEquals(403, $response->status);
    }

    function testMultigetMixingReadableAndUnreadableCalendarsIsForbidden() {
        $this->caldavBackend->createCalendarObject($this->calUser2['id'], 'event1.ics', $this->caldavCalendarObjects['event1.ics']);

        $response = $this->multiget('/calendars/54b64eadf6d7d8e41d263e0f/calendar1/', [
            '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event1.ics',
            '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/event2.ics',
            '/calendars/54b64eadf6d7d8e41d263e0e/calendar2/event1.ics',
        ]);

        $this->assertEquals(403, $response->status);
    }
}

class CalendarObjectQueryCounter implements \MongoDB\Driver\Monitoring\CommandSubscriber {
    public $queries = 0;

    function commandStarted(\MongoDB\Driver\Monitoring\CommandStartedEvent $event): void {
        if ($event->getCommandName() === 'find' && $event->getCommand()->find === 'calendarobjects') {
            $this->queries++;
        }
    }

    function commandSucceeded(\MongoDB\Driver\Monitoring\CommandSucceededEvent $event): void {}

    function commandFailed(\MongoDB\Driver\Monitoring\CommandFailedEvent $event): void {}
}
