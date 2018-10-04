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

    function setUp() {
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
