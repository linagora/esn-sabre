<?php

namespace ESN\CalDAV\Subscriptions;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class SubscriptionTest extends \ESN\DAV\ServerMock {

    const SUBSCRIPTION_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/subscription1/';

    function setUp(): void {
        parent::setUp();

        $this->server->addPlugin(new \ESN\CalDAV\PrivateEventPlugin());
        $this->server->addPlugin(new \Sabre\DAV\Sync\Plugin());
    }

    function testSyncCollectionShouldListTheEventsOfTheSourceCalendar() {
        $response = $this->syncCollection('');

        $this->assertEquals(207, $response->status);
        $this->assertStringContainsString(self::SUBSCRIPTION_PATH . 'privateRecurEvent.ics', $response->getBodyAsString());
        $this->assertStringContainsString('<d:sync-token>', $response->getBodyAsString());
    }

    function testSyncCollectionShouldHidePrivateEventDetailsFromTheSubscriber() {
        $response = $this->syncCollection('');

        $this->assertEquals(207, $response->status);
        $this->assertStringContainsString('75EE3C60-34AC-4A97-953D-56CC004D6706', $response->getBodyAsString());
        $this->assertStringNotContainsString('RecurringPrivate', $response->getBodyAsString());
        $this->assertStringNotContainsString('Paris', $response->getBodyAsString());
    }

    function testSyncCollectionShouldReportTheChangesOfTheSourceCalendar() {
        $syncToken = $this->server->tree->getNodeForPath(self::SUBSCRIPTION_PATH)->getSyncToken();
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'newEvent.ics', $this->caldavCalendarObjects['event1.ics']);

        $response = $this->syncCollection('http://sabre.io/ns/sync/' . $syncToken);

        $this->assertEquals(207, $response->status);
        $this->assertStringContainsString(self::SUBSCRIPTION_PATH . 'newEvent.ics', $response->getBodyAsString());
        $this->assertStringNotContainsString('privateRecurEvent.ics', $response->getBodyAsString());
    }

    private function syncCollection($syncToken) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'REQUEST_URI'       => self::SUBSCRIPTION_PATH,
        ]);
        $request->setBody('<d:sync-collection xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:sync-token>' . $syncToken . '</d:sync-token><d:sync-level>1</d:sync-level>'
            . '<d:prop><d:getetag/><c:calendar-data/></d:prop></d:sync-collection>');

        return $this->request($request);
    }
}
