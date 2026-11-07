<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * The current user 54b64eadf6d7d8e41d263e0f reads the public calendar of 54b64eadf6d7d8e41d263e0e,
 * which hides its private events from them.
 *
 * @medium
 */
class PrivateEventPluginTest extends \ESN\DAV\ServerMock {

    const PUBLIC_CAL = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1';
    const OWN_CAL = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1';

    const CALDAV_NS = 'urn:ietf:params:xml:ns:caldav';
    const PUBLIC_CALENDAR_PATH = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1/';
    const SUBSCRIPTION_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/subscription1/';
    const HIDDEN_LOCATION = 'Hospital, a much longer hidden location';

    function setUp(): void {
        parent::setUp();

        $this->server->addPlugin(new PrivateEventPlugin());
        $this->server->addPlugin(new Subscriptions\Plugin());
    }

    function testContentLengthOfHiddenPrivateEventIsTheSanitisedOne() {
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'secret.ics', $this->privateEvent(self::HIDDEN_LOCATION));

        $properties = $this->propFind(self::PUBLIC_CALENDAR_PATH . 'secret.ics', '0', '<c:calendar-data/><d:getcontentlength/>');

        $this->assertStringNotContainsString('Hospital', $properties['calendar-data']);
        $this->assertSame(strlen($properties['calendar-data']), $properties['getcontentlength']);
    }

    function testContentLengthOfHiddenPrivateEventIsTheSanitisedOneWithoutCalendarData() {
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'secret.ics', $this->privateEvent(self::HIDDEN_LOCATION));

        $properties = $this->propFind(self::PUBLIC_CALENDAR_PATH . 'secret.ics', '0', '<d:getcontentlength/>');

        $this->assertSame(strlen($this->getBody(self::PUBLIC_CALENDAR_PATH . 'secret.ics')), $properties['getcontentlength']);
    }

    function testContentLengthOfHiddenPrivateEventDoesNotDependOnTheHiddenFields() {
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'secret.ics', $this->privateEvent(self::HIDDEN_LOCATION));

        $longLength = $this->propFind(self::SUBSCRIPTION_PATH . 'secret.ics', '0', '<d:getcontentlength/>')['getcontentlength'];

        $this->caldavBackend->updateCalendarObject($this->publicCal['id'], 'secret.ics', $this->privateEvent('Home'));
        $shortLength = $this->propFind(self::SUBSCRIPTION_PATH . 'secret.ics', '0', '<d:getcontentlength/>')['getcontentlength'];

        $this->assertSame($longLength, $shortLength);
    }

    function testContentLengthOfHiddenPrivateEventIsTheSanitisedOneOnDepthOneOfASubscription() {
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'secret.ics', $this->privateEvent(self::HIDDEN_LOCATION));

        $response = $this->propFindRequest(self::SUBSCRIPTION_PATH, '1', '<c:calendar-data/><d:getcontentlength/>');

        $this->assertStringNotContainsString('Hospital', $response);
        $properties = $this->propertiesOf($response, self::SUBSCRIPTION_PATH . 'secret.ics');
        $this->assertSame(strlen($properties['calendar-data']), $properties['getcontentlength']);
    }

    public static function privateOverrideOrders() {
        return [
            'private override after public master' => [false],
            'private override before public master' => [true],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('privateOverrideOrders')]
    function testGetHidesPrivateOverrideOfPublicSeries($overrideFirst) {
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'series.ics', $this->seriesWithPrivateOverride($overrideFirst));

        $body = $this->getBody(self::PUBLIC_CALENDAR_PATH . 'series.ics');

        $this->assertStringContainsString('Weekly sync', $body);
        $this->assertStringNotContainsString('Hospital', $body);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('privateOverrideOrders')]
    function testPropFindHidesPrivateOverrideOfPublicSeries($overrideFirst) {
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'series.ics', $this->seriesWithPrivateOverride($overrideFirst));

        $properties = $this->propFind(self::PUBLIC_CALENDAR_PATH . 'series.ics', '0', '<c:calendar-data/><d:getcontentlength/>');

        $this->assertStringNotContainsString('Hospital', $properties['calendar-data']);
        $this->assertSame(strlen($properties['calendar-data']), $properties['getcontentlength']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('privateOverrideOrders')]
    function testCopyOfPublicSeriesWithPrivateOverrideIsForbidden($overrideFirst) {
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'series.ics', $this->seriesWithPrivateOverride($overrideFirst));

        $response = $this->copyOrMove('COPY', self::PUBLIC_CAL . '/series.ics', self::OWN_CAL . '/copy.ics');

        $this->assertEquals(403, $response->status);
        $this->assertNull($this->caldavBackend->getCalendarObject($this->cal['id'], 'copy.ics'));
    }

    private function seriesWithPrivateOverride($overrideFirst) {
        $master = ['BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20260101T000000Z',
            'DTSTART:20260101T090000Z', 'DTEND:20260101T100000Z', 'RRULE:FREQ=WEEKLY;COUNT=3',
            'CLASS:PUBLIC', 'SUMMARY:Weekly sync', 'END:VEVENT'];
        $override = ['BEGIN:VEVENT', 'UID:series', 'DTSTAMP:20260101T000000Z',
            'RECURRENCE-ID:20260108T090000Z', 'DTSTART:20260108T090000Z', 'DTEND:20260108T100000Z',
            'CLASS:PRIVATE', 'SUMMARY:Doctor', 'LOCATION:Hospital', 'END:VEVENT'];

        return implode("\r\n", array_merge(
            ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//test//EN'],
            $overrideFirst ? array_merge($override, $master) : array_merge($master, $override),
            ['END:VCALENDAR', '']));
    }

    private function privateEvent($location) {
        return implode("\r\n", [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//test//EN',
            'BEGIN:VEVENT', 'UID:secret', 'DTSTAMP:20260101T000000Z',
            'DTSTART:20260101T090000Z', 'DTEND:20260101T100000Z',
            'CLASS:PRIVATE', 'LOCATION:' . $location,
            'END:VEVENT', 'END:VCALENDAR', '']);
    }

    private function getBody($uri) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI'    => $uri,
        ]);

        return $this->request($request)->getBodyAsString();
    }

    private function propFind($uri, $depth, $props) {
        return $this->propertiesOf($this->propFindRequest($uri, $depth, $props), $uri);
    }

    private function propFindRequest($uri, $depth, $props) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_DEPTH'        => $depth,
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'REQUEST_URI'       => $uri,
        ]);
        $request->setBody('<?xml version="1.0"?><d:propfind xmlns:d="DAV:" xmlns:c="' . self::CALDAV_NS . '">'
            . '<d:prop>' . $props . '</d:prop></d:propfind>');

        $response = $this->request($request);
        $this->assertEquals(207, $response->status);

        return $response->getBodyAsString();
    }

    private function propertiesOf($body, $href) {
        $xml = simplexml_load_string($body);
        $xml->registerXPathNamespace('d', 'DAV:');
        $xml->registerXPathNamespace('c', self::CALDAV_NS);
        $response = '//d:response[d:href="' . $href . '"]';
        $calendarData = $xml->xpath($response . '//c:calendar-data');
        $contentLength = $xml->xpath($response . '//d:getcontentlength');

        return [
            'calendar-data'    => $calendarData ? (string) $calendarData[0] : null,
            'getcontentlength' => $contentLength ? (int) (string) $contentLength[0] : null,
        ];
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
