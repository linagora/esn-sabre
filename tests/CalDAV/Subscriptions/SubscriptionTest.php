<?php

namespace ESN\CalDAV\Subscriptions;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class SubscriptionTest extends \ESN\DAV\ServerMock {

    const SUBSCRIPTION_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/subscription1/';

    function setUp(): void {
        parent::setUp();

        $this->server->addPlugin(new \ESN\CalDAV\PrivateEventPlugin());
        $this->server->addPlugin(new \Sabre\DAV\Sync\Plugin());
        $this->server->addPlugin(new Plugin());
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

    function testMkcolSubscriptionShouldStoreTheCheckedPathRatherThanDotSegments() {
        $source = 'calendars/' . $this->user2Id() . '/' . $this->calUser2['uri'] . '/../' . $this->publicCal['uri'];

        $response = $this->mkcolSubscription('newsub', $source);

        $this->assertEquals(201, $response->status);
        $this->assertEquals($this->publicCalPath(), $this->storedSource('newsub'));
        $children = $this->server->tree->getNodeForPath('calendars/54b64eadf6d7d8e41d263e0f/newsub')->getChildren();
        $this->assertEquals(['privateRecurEvent.ics'], array_map(fn($child) => $child->getName(), $children));
    }

    function testMkcolSubscriptionShouldStoreAnAbsolutePathWithoutItsLeadingSlash() {
        $response = $this->mkcolSubscription('newsub', '/' . $this->publicCalPath() . '/');

        $this->assertEquals(201, $response->status);
        $this->assertEquals($this->publicCalPath(), $this->storedSource('newsub'));
    }

    function testMkcolSubscriptionShouldRejectAPrivateSource() {
        $response = $this->mkcolSubscription('newsub', 'calendars/' . $this->user2Id() . '/' . $this->calUser2['uri']);

        $this->assertEquals(403, $response->status);
        $this->assertNull($this->storedSource('newsub'));
    }

    function testMkcolSubscriptionShouldRejectASourceThatIsNotACalendar() {
        $response = $this->mkcolSubscription('newsub', 'calendars/' . $this->user2Id());

        $this->assertEquals(400, $response->status);
        $this->assertNull($this->storedSource('newsub'));
    }

    function testProppatchSubscriptionSourceShouldStoreTheCheckedPathRatherThanDotSegments() {
        $source = 'calendars/' . $this->user2Id() . '/' . $this->calUser2['uri'] . '/../' . $this->publicCal['uri'];

        $response = $this->proppatchSubscriptionSource($source);

        $this->assertEquals(207, $response->status);
        $this->assertStringContainsString('HTTP/1.1 200', $response->getBodyAsString());
        $this->assertEquals($this->publicCalPath(), $this->storedSource('subscription1'));
    }

    function testProppatchSubscriptionSourceShouldRejectAPrivateSource() {
        $response = $this->proppatchSubscriptionSource('calendars/' . $this->user2Id() . '/' . $this->calUser2['uri']);

        $this->assertEquals(403, $response->status);
        $this->assertEquals($this->publicCalPath(), $this->storedSource('subscription1'));
    }

    function testSubscriptionShouldNotResolveAStoredSourceWithDotSegments() {
        $propPatch = new \Sabre\DAV\PropPatch([
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href(
                'calendars/' . $this->user2Id() . '/' . $this->publicCal['uri'] . '/../' . $this->calUser2['uri'], false)
        ]);
        $this->caldavBackend->updateSubscription($this->subscription['id'], $propPatch);
        $propPatch->commit();

        $this->assertEquals([], $this->server->tree->getNodeForPath(self::SUBSCRIPTION_PATH)->getChildren());
    }

    private function user2Id() {
        return explode('/', $this->publicCal['principaluri'])[2];
    }

    private function publicCalPath() {
        return 'calendars/' . $this->user2Id() . '/' . $this->publicCal['uri'];
    }

    private function storedSource($uri) {
        foreach ($this->caldavBackend->getSubscriptionsForUser('principals/users/54b64eadf6d7d8e41d263e0f') as $subscription) {
            if ($subscription['uri'] === $uri) {
                return $subscription['source'];
            }
        }

        return null;
    }

    private function mkcolSubscription($uri, $source) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'MKCOL',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/' . $uri,
        ]);
        $request->setBody('<?xml version="1.0" encoding="utf-8"?>'
            . '<D:mkcol xmlns:D="DAV:" xmlns:CS="http://calendarserver.org/ns/"><D:set><D:prop>'
            . '<D:resourcetype><D:collection/><CS:subscribed/></D:resourcetype>'
            . '<D:displayname>New subscription</D:displayname>'
            . '<CS:source><D:href>' . $source . '</D:href></CS:source></D:prop></D:set></D:mkcol>');

        return $this->request($request);
    }

    private function proppatchSubscriptionSource($source) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'PROPPATCH',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'REQUEST_URI'       => self::SUBSCRIPTION_PATH,
        ]);
        $request->setBody('<?xml version="1.0" encoding="utf-8"?>'
            . '<D:propertyupdate xmlns:D="DAV:" xmlns:CS="http://calendarserver.org/ns/"><D:set><D:prop>'
            . '<CS:source><D:href>' . $source . '</D:href></CS:source></D:prop></D:set></D:propertyupdate>');

        return $this->request($request);
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
