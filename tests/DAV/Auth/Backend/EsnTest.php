<?php

namespace ESN\DAV\Auth\Backend;

class EsnTest extends \PHPUnit_Framework_TestCase {
    function testAuthenticateTokenSuccess() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();

        $client->on('curlExec', function(&$return) {
            $return = "HTTP 200 OK\r\nSet-Cookie: test=passed\r\n\r\n{\"_id\":\"123456789\",\"firstname\":\"John\",\"lastname\":\"Doe\",\"emails\":[\"johndoe@linagora.com\"]}";
        });
        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 200, 'header_size' => 40 ], 0, '' ];
        });

        $this->continueAuthTest($esnauth);
    }

    function testAuthenticatePasswordSuccess() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();

        $requestCount = 0;

        $client->on('curlExec', function(&$return) use (&$requestCount) {
            if ($requestCount == 0) {
                $return = 'HTTP/1.1 403 Authentication Required';
            } else if ($requestCount == 1) {
                $return = "HTTP 200 OK\r\nSet-Cookie: test=passed\r\n\r\n{\"_id\":\"123456789\",\"firstname\":\"John\",\"lastname\":\"Doe\",\"emails\":[\"johndoe@linagora.com\"]}";
            }
        });

        $client->on('curlStuff', function(&$return) use (&$requestCount) {
            if ($requestCount == 0) {
                $return = [ [ 'http_code' => 403, 'header_size' => 0 ], 0, '' ];
            } else if ($requestCount == 1) {
                $return = [ [ 'http_code' => 200, 'header_size' => 40 ], 0, '' ];
            }
            $requestCount++;
        });

        $this->continueAuthTest($esnauth);
    }

    private function continueAuthTest($esnauth) {
        $base64 = base64_encode('username:password');

        $request = new \Sabre\HTTP\Request('GET', '/foo/bar');
        $request->setHeader('Authorization', 'Basic ' .$base64);
        $response = new \Sabre\HTTP\Response(200);

        $server = new \Sabre\DAV\Server([]);
        $server->httpRequest = $request;
        $server->httpResponse = $response;

        $handlerCalled = false;
        $self = $this;

        $server->on(Esn::AFTER_LOGIN_EVENT, function($cookie) use (&$handlerCalled, $self) {
            $self->assertEquals($cookie, 'test=passed');
            $handlerCalled = true;
        });

        $rv = $esnauth->authenticate($server, 'TestRealm');

        $this->assertTrue($rv);
        $this->assertTrue($handlerCalled);
        $this->assertEquals($esnauth->getCurrentUser(), '123456789');
        $this->assertEquals($esnauth->getAuthCookies(), 'test=passed');
    }

    /**
     * @expectedException \Sabre\DAV\Exception\NotAuthenticated
     */
    function testAuthenticateFailedCode() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();

        $client->on('curlExec', function(&$return) {
            $return = 'HTTP/1.1 403 Authentication Required';
        });
        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 403, 'header_size' => 0 ], 0, '' ];
        });


        $base64 = base64_encode('username:password');

        $request = new \Sabre\HTTP\Request('GET', '/foo/bar');
        $request->setHeader('Authorization', 'Basic ' .$base64);
        $response = new \Sabre\HTTP\Response(200);

        $server = new \Sabre\DAV\Server([]);
        $server->httpRequest = $request;
        $server->httpResponse = $response;

        $esnauth->authenticate($server, 'TestRealm');
    }

    /**
     * @expectedException \Sabre\DAV\Exception\NotAuthenticated
     */
    function testAuthenticateFailedJSON() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();

        $client->on('curlExec', function(&$return) {
            $return = '{ THIS IS NOT JSON!! }';
        });
        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 200, 'header_size' => 0 ], 0, '' ];
        });

        $base64 = base64_encode('username:password');

        $request = new \Sabre\HTTP\Request('GET', '/foo/bar');
        $request->setHeader('Authorization', 'Basic ' .$base64);
        $response = new \Sabre\HTTP\Response(200);

        $server = new \Sabre\DAV\Server([]);
        $server->httpRequest = $request;
        $server->httpResponse = $response;

        $esnauth->authenticate($server, 'TestRealm');
    }

    function testPluginCalled() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();

        $client->on('curlExec', function(&$return) {
            $return = "HTTP 200 OK\r\nSet-Cookie: test=passed\r\n\r\n{\"_id\":\"123456789\",\"firstname\":\"John\",\"lastname\":\"Doe\",\"emails\":[\"johndoe@linagora.com\"]}";
        });
        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 200, 'header_size' => 40 ], 0, '' ];
        });

        $base64 = base64_encode('username:password');

        $request = new \Sabre\HTTP\Request('GET', '/foo/bar');
        $request->setHeader('Authorization', 'Basic ' .$base64);
        $response = new \Sabre\HTTP\Response(200);

        $server = new \Sabre\DAV\Server([]);
        $server->httpRequest = $request;
        $server->httpResponse = $response;

        $plugin = new ESNHookPluginMock('/', 'principals');
        $server->addPlugin($plugin);

        $rv = $esnauth->authenticate($server, 'TestRealm');

        $this->assertTrue($rv);
        $this->assertEquals($esnauth->getAuthCookies(), 'test=passed');

        $pluginrequest = $plugin->createRequest('123123', 'body');
        $this->assertEquals($pluginrequest->getHeader('Cookie'), 'test=passed');
    }
}

class ESNHookPluginMock extends \ESN\CalDAV\ESNHookPlugin {
    public function createRequest($community_id, $body) {
        return parent::createRequest($community_id, $body);
    }
}

class EsnMock extends Esn {
    function __construct($apiroot) {
        require_once '../vendor/sabre/http/tests/HTTP/ClientTest.php';
        parent::__construct($apiroot);
        $this->httpClient = new \Sabre\HTTP\ClientMock();
    }

    function getClient() {
        return $this->httpClient;
    }
}
