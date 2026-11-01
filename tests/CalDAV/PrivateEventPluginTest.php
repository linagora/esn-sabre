<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * The current user 54b64eadf6d7d8e41d263e0f reads the public calendar of 54b64eadf6d7d8e41d263e0e,
 * which hides its private events from them.
 */
class PrivateEventPluginTest extends \ESN\DAV\ServerMock {

    const CALDAV_NS = 'urn:ietf:params:xml:ns:caldav';
    const PUBLIC_CALENDAR_PATH = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1/';
    const SUBSCRIPTION_PATH = '/calendars/54b64eadf6d7d8e41d263e0f/subscription1/';
    const HIDDEN_LOCATION = 'Hospital, a much longer hidden location';

    function setUp(): void {
        parent::setUp();

        $this->server->addPlugin(new PrivateEventPlugin());
        $this->server->addPlugin(new Subscriptions\Plugin());
        $this->caldavBackend->createCalendarObject($this->publicCal['id'], 'secret.ics', $this->privateEvent(self::HIDDEN_LOCATION));
    }

    function testContentLengthOfHiddenPrivateEventIsTheSanitisedOne() {
        $properties = $this->propFind(self::PUBLIC_CALENDAR_PATH . 'secret.ics', '0', '<c:calendar-data/><d:getcontentlength/>');

        $this->assertStringNotContainsString('Hospital', $properties['calendar-data']);
        $this->assertSame(strlen($properties['calendar-data']), $properties['getcontentlength']);
    }

    function testContentLengthOfHiddenPrivateEventIsTheSanitisedOneWithoutCalendarData() {
        $properties = $this->propFind(self::PUBLIC_CALENDAR_PATH . 'secret.ics', '0', '<d:getcontentlength/>');

        $this->assertSame(strlen($this->getBody(self::PUBLIC_CALENDAR_PATH . 'secret.ics')), $properties['getcontentlength']);
    }

    function testContentLengthOfHiddenPrivateEventDoesNotDependOnTheHiddenFields() {
        $longLength = $this->propFind(self::SUBSCRIPTION_PATH . 'secret.ics', '0', '<d:getcontentlength/>')['getcontentlength'];

        $this->caldavBackend->updateCalendarObject($this->publicCal['id'], 'secret.ics', $this->privateEvent('Home'));
        $shortLength = $this->propFind(self::SUBSCRIPTION_PATH . 'secret.ics', '0', '<d:getcontentlength/>')['getcontentlength'];

        $this->assertSame($longLength, $shortLength);
    }

    function testContentLengthOfHiddenPrivateEventIsTheSanitisedOneOnDepthOneOfASubscription() {
        $response = $this->propFindRequest(self::SUBSCRIPTION_PATH, '1', '<c:calendar-data/><d:getcontentlength/>');

        $this->assertStringNotContainsString('Hospital', $response);
        $properties = $this->propertiesOf($response, self::SUBSCRIPTION_PATH . 'secret.ics');
        $this->assertSame(strlen($properties['calendar-data']), $properties['getcontentlength']);
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
}
