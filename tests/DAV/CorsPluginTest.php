<?php

namespace ESN\DAV;

class CorsPluginTest extends \PHPUnit_Framework_TestCase {

    private function prepareServer() {
        $corsplugin = new CorsPlugin();

        $server = new \Sabre\DAV\Server([]);
        $server->sapi = new MockSapi();
        $server->addPlugin($corsplugin);

        return array($corsplugin, $server);
    }

    function testOPTIONSPreflight() {
        list($corsplugin, $server) = $this->prepareServer();

        $corsplugin->allowMethods = ['POST', 'PUT'];
        $corsplugin->allowHeaders = ['X-Frobnicate'];
        $corsplugin->allowOrigin = ['http://localhost'];
        $corsplugin->allowCredentials = false;

        $server->httpRequest->setMethod("OPTIONS");
        $server->httpRequest->setHeader("Origin", "http://localhost");

        $server->invokeMethod($server->httpRequest, $server->httpResponse);
        $response = $server->sapi->response;

        $this->assertEquals($response->getHeader('Access-Control-Allow-Origin'), 'http://localhost');
        $this->assertEquals($response->getHeader('Access-Control-Allow-Headers'), 'X-Frobnicate');
        $this->assertEquals($response->getHeader('Access-Control-Allow-Methods'), 'POST, PUT');
        $this->assertNull($response->getHeader('Access-Control-Allow-Credentials'));
    }

    function testOPTIONSPreflightCredentials() {
        list($corsplugin, $server) = $this->prepareServer();

        $corsplugin->allowCredentials = true;
        $server->httpRequest->setMethod("OPTIONS");
        $server->httpRequest->setHeader("Origin", "http://localhost");

        $server->invokeMethod($server->httpRequest, $server->httpResponse);

        $response = $server->sapi->response;
        $this->assertEquals($response->getHeader('Access-Control-Allow-Credentials'), 'true');
    }

    function testOPTIONSNoPreflight() {
        list($corsplugin, $server) = $this->prepareServer();

        $corsplugin->allowCredentials = true;
        $server->httpRequest->setMethod("OPTIONS");

        $server->invokeMethod($server->httpRequest, $server->httpResponse);

        $response = $server->sapi->response;
        $this->assertNull($response->getHeader('Access-Control-Allow-Credentials'));
    }
}

class MockSapi {

    public $response;

    function sendResponse($response) {
        $this->response = $response;
    }
}
