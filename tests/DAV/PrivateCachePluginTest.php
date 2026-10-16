<?php

namespace ESN\DAV;

#[\AllowDynamicProperties]
class PrivateCachePluginTest extends \PHPUnit\Framework\TestCase {

    private function prepareServer() {
        $server = new \Sabre\DAV\Server([
            new \Sabre\DAV\SimpleFile('event.ics', 'BEGIN:VCALENDAR', 'text/calendar')
        ]);
        $server->sapi = new PrivateCachePluginTestSapiMock();
        $server->addPlugin(new PrivateCachePlugin());

        return $server;
    }

    private function get($server) {
        $request = new \Sabre\HTTP\Request('GET', '/event.ics');
        $server->httpRequest = $request;
        $server->invokeMethod($request, $server->httpResponse);

        return $server->sapi->response;
    }

    function testGetResponseIsNotReusableAcrossCredentials() {
        $response = $this->get($this->prepareServer());

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('private, no-cache', $response->getHeader('Cache-Control'));
        $this->assertEquals('Authorization', $response->getHeader('Vary'));
    }

    function testShortCircuitedResponseIsNotReusableAcrossCredentials() {
        $server = $this->prepareServer();
        $server->on('beforeMethod:*', function($request, $response) use ($server) {
            $response->setStatus(200);
            $response->setBody('{}');
            $server->sapi->sendResponse($response);
            return false;
        }, 15);

        $response = $this->get($server);

        $this->assertEquals('{}', $response->getBodyAsString());
        $this->assertEquals('private, no-cache', $response->getHeader('Cache-Control'));
        $this->assertEquals('Authorization', $response->getHeader('Vary'));
    }
}

class PrivateCachePluginTestSapiMock {

    public $response;

    function sendResponse($response) {
        $this->response = $response;
    }
}
