<?php

namespace ESN\DAVACL;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';
require_once ESN_TEST_BASE. '/DAV/MongoFindCounter.php';

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

        $queries = \ESN\DAV\MongoFindCounter::count('calendarobjects', function () use ($hrefs, &$response) {
            $response = $this->multiget('/calendars/54b64eadf6d7d8e41d263e0f/calendar1/', $hrefs);
        });

        $this->assertEquals(207, $response->status);
        // The missing href gets its own 404 response (RFC 4791 section 7.9)
        $this->assertEquals(21, substr_count($response->getBodyAsString(), '<d:response>'));
        // One lookup for the ACL check, one for the report itself, whatever the number of hrefs
        $this->assertLessThanOrEqual(2, $queries);
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

    private function propfindHrefs($path) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_DEPTH'        => '1',
            'REQUEST_URI'       => $path,
        ));
        $request->setBody('<d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>');

        $response = $this->request($request);
        $this->assertEquals(207, $response->status);
        preg_match_all('#<d:href>([^<]*)</d:href>#', $response->getBodyAsString(), $matches);

        return $matches[1];
    }

    function testPROPFINDOfAnotherUserCalendarHomeListsOnlyTheReadableCalendars() {
        $hrefs = $this->propfindHrefs('/calendars/54b64eadf6d7d8e41d263e0e/');

        $this->assertContains('/calendars/54b64eadf6d7d8e41d263e0e/', $hrefs);
        $this->assertContains('/calendars/54b64eadf6d7d8e41d263e0e/publicCal1/', $hrefs);
        $this->assertNotContains('/calendars/54b64eadf6d7d8e41d263e0e/calendar2/', $hrefs);
        $this->assertNotContains('/calendars/54b64eadf6d7d8e41d263e0e/outbox/', $hrefs);
    }

    function testPROPFINDOfAnotherUserReadableCalendarStillListsItsEvents() {
        $hrefs = $this->propfindHrefs('/calendars/54b64eadf6d7d8e41d263e0e/publicCal1/');

        $this->assertContains('/calendars/54b64eadf6d7d8e41d263e0e/publicCal1/privateRecurEvent.ics', $hrefs);
    }

    function testPROPFINDOfOwnCalendarHomeStillListsOwnedAndDelegatedCalendars() {
        $this->caldavBackend->updateInvites($this->delegatedCal['id'], [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:johndoe@example.org',
                'principal' => 'principals/users/54b64eadf6d7d8e41d263e0e',
                'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE,
                'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED,
            ])
        ]);
        $this->server->tree->markDirty('calendars');
        $this->authBackend->setAuthTenant(new \ESN\Utils\AuthTenant('54b64eadf6d7d8e41d263e0e', SERVER_MOCK_DOMAIN_ID));
        $hrefs = $this->propfindHrefs('/calendars/54b64eadf6d7d8e41d263e0e/');

        $this->assertContains('/calendars/54b64eadf6d7d8e41d263e0e/calendar2/', $hrefs);
        $this->assertContains('/calendars/54b64eadf6d7d8e41d263e0e/publicCal1/', $hrefs);
        $this->assertContains('/calendars/54b64eadf6d7d8e41d263e0e/inbox/', $hrefs);
        $known = [
            '/calendars/54b64eadf6d7d8e41d263e0e/',
            '/calendars/54b64eadf6d7d8e41d263e0e/calendar2/',
            '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1/',
            '/calendars/54b64eadf6d7d8e41d263e0e/inbox/',
            '/calendars/54b64eadf6d7d8e41d263e0e/outbox/',
        ];
        $this->assertNotEmpty(array_diff($hrefs, $known), 'the calendar delegated by user1 is listed');
    }
}
