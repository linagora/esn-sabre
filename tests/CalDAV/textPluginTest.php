<?php

namespace ESN\CalDAV;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

/**
 * @medium
 */
class TextPluginTest extends \ESN\DAV\ServerMock {

    protected $userTestId = '5aa1f6639751b711008b4567';

    function setUp(): void {
        parent::setUp();

        $plugin = new TextPlugin("text");
        $this->server->addPlugin($plugin);
    }

    function testGetSubscriptionCalendarText() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'HTTP_ACCEPT'       => 'text/calendar',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1.json',
        ));

        $response = $this->request($request);

        $Res =  \Sabre\VObject\Reader::read($response->getBodyAsString());
        $expected = \Sabre\VObject\Reader::read($this->privateRecurEvent);

        $this->assertEquals($Res, $expected);
    }

    private function eventWithZone($uid, $offset, $day) {
        return join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Test//EN',
            'BEGIN:VTIMEZONE',
            'TZID:Custom',
            'BEGIN:STANDARD',
            'DTSTART:19700101T000000',
            'TZOFFSETFROM:' . $offset,
            'TZOFFSETTO:' . $offset,
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTAMP:20261201T000000Z',
            'DTSTART;TZID=Custom:202612' . $day . 'T100000',
            'DURATION:PT1H',
            'SUMMARY:' . $uid,
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);
    }

    function testGetCalendarTextKeepsEveryDefinitionOfATzid() {
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'zone1.ics', $this->eventWithZone('zone1', '+0100', '12'));
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'zone2.ics', $this->eventWithZone('zone2', '+0500', '13'));

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_ACCEPT'       => 'text/calendar',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1',
        ));

        $response = $this->request($request);
        $this->assertEquals(200, $response->status);

        $vcal = \Sabre\VObject\Reader::read($response->getBodyAsString());
        $offsets = [];
        foreach ($vcal->select('VTIMEZONE') as $vtimezone) {
            $offsets[(string) $vtimezone->TZID] = (string) $vtimezone->STANDARD->TZOFFSETTO;
        }
        $this->assertEquals(['Custom' => '+0100', 'Custom-2' => '+0500'], $offsets);

        $starts = [];
        foreach ($vcal->VEVENT as $vevent) {
            if (in_array((string) $vevent->UID, ['zone1', 'zone2'], true)) {
                $starts[(string) $vevent->UID] = [
                    (string) $vevent->DTSTART['TZID'],
                    $vevent->DTSTART->getDateTime()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z'),
                ];
            }
        }
        $this->assertEquals([
            'zone1' => ['Custom', '20261212T090000Z'],
            'zone2' => ['Custom-2', '20261213T050000Z'],
        ], $starts);
    }

    function testGetSubscriptionCalendarTextReturnNotImplementedWhenRequestIsNotCalendarObjectContainer() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'HTTP_ACCEPT'       => 'text/calendar',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/subscription1',
        ));

        $response = $this->request($request);
        $this->assertEquals($response->status, 204);
        $this->assertEquals($response->body, null);
    }

    function testGetSubscriptionCalendarTextReturnNotFoundWhenWrongRequest() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'text/calendar',
            'HTTP_ACCEPT'       => 'text/calendar',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar36.json',
        ));

        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testGetSubscriptionCalendarTextReturnNotImplementedWhenWrongHeader() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'text/plain',
            'HTTP_ACCEPT'       => 'text/plain',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1.json',
        ));

        $response = $this->request($request);
        $this->assertEquals($response->status, 501);
    }
}
