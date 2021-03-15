<?php

namespace ESN\DAV;

class XHttpMethodOverridePluginTest extends \PHPUnit_Framework_TestCase {

    private function prepareServer() {
        $plugin = new XHttpMethodOverridePlugin();
        $itipPlugin = new MockItipPlugin();
        $postPlugin = new MockPostPlugin();
        $server = new \Sabre\DAV\Server([]);
        $server->sapi = new XHttpMethodOverridePluginTestSapiMock();
        $server->addPlugin($plugin);
        $server->addPlugin($itipPlugin);
        $server->addPlugin($postPlugin);

        return array($plugin, $server);
    }

    function testMethodOverride() {
        list($corsplugin, $server) = $this->prepareServer();

        $corsplugin->allowCredentials = true;
        $server->httpRequest->setMethod("POST");
        $server->httpRequest->setHeader("X-Http-Method-Override", "ITIP");

        $server->invokeMethod($server->httpRequest, $server->httpResponse);

        $response = $server->sapi->response;
        $this->assertEquals($response->getBodyAsString(), 'OK');
    }

    function testStandardWithoutMethodOverride() {
        list($corsplugin, $server) = $this->prepareServer();

        $corsplugin->allowCredentials = true;
        $server->httpRequest->setMethod("POST");
        $server->invokeMethod($server->httpRequest, $server->httpResponse);

        $response = $server->sapi->response;
        $this->assertEquals($response->getBodyAsString(), 'POST');
    }
}

class XHttpMethodOverridePluginTestSapiMock {

    public $response;

    function sendResponse($response) {
        $this->response = $response;
    }
}

class MockItipPlugin extends \Sabre\DAV\ServerPlugin {

    function initialize(\Sabre\DAV\Server $server) {
        $this->server = $server;
        $this->server->on('method:ITIP', [$this, 'send'], 90);
    }

    function send(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) {
        $this->server->httpResponse->setBody("OK");
        $this->server->httpResponse->setStatus(200);
        return false;
    }
}

class MockPostPlugin extends \Sabre\DAV\ServerPlugin {

    function initialize(\Sabre\DAV\Server $server) {
        $this->server = $server;
        $this->server->on('method:POST', [$this, 'send'], 90);
    }

    function send(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) {
        $this->server->httpResponse->setBody("POST");
        $this->server->httpResponse->setStatus(200);
        return false;
    }
}
