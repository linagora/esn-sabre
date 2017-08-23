<?php

namespace ESN\DAV\Auth\Backend;

class EsnTest extends \PHPUnit_Framework_TestCase {
    function testAuthenticateTokenSuccess() {
        $authNotificationResult = [];
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();
        $eventEmitter = $esnauth->getEventEmitter();
        $eventEmitter->on("auth:success", function($principal) use (&$authNotificationResult) {
            $authNotificationResult[] = $principal;
        });

        $client->on('curlExec', function(&$return) {
            $return = "HTTP 200 OK\r\nSet-Cookie: test=passed\r\n\r\n{\"_id\":\"123456789\",\"type\":\"user\",\"firstname\":\"John\",\"lastname\":\"Doe\",\"emails\":[\"johndoe@linagora.com\"]}";
        });
        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 200, 'header_size' => 40 ], 0, '' ];
        });

        $request = new \Sabre\HTTP\Request('GET', '/foo/bar');
        $request->setHeader('ESNToken', '1234');
        $response = new \Sabre\HTTP\Response(200);

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals($esnauth->getCurrentPrincipal(), 'principals/users/123456789');
        $this->assertEquals($esnauth->getAuthCookies(), 'test=passed');
        $this->assertEquals(['principals/users/123456789'], $authNotificationResult);
    }

    function testAuthenticateTokenAsTechnicalUser() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();

        $client->on('curlExec', function(&$return) {
            $return = "HTTP 200 OK\r\nSet-Cookie: test=passed\r\n\r\n{\"_id\":\"123456789\",\"user_type\":\"technical\",\"firstname\":\"John\",\"lastname\":\"Doe\",\"emails\":[\"johndoe@linagora.com\"]}";
        });
        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 200, 'header_size' => 40 ], 0, '' ];
        });

        $request = new \Sabre\HTTP\Request('GET', '/foo/bar');
        $request->setHeader('ESNToken', '1234');
        $response = new \Sabre\HTTP\Response(200);

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals($msg, 'principals/technicalUser');
        $this->assertEquals($esnauth->getAuthCookies(), 'test=passed');
    }

    function testAuthenticatePasswordSuccess() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();

        $requestCount = 0;

        $client->on('curlExec', function(&$return) use (&$requestCount) {
            $return = "HTTP 200 OK\r\nSet-Cookie: test=passed\r\n\r\n{\"_id\":\"123456789\",\"firstname\":\"John\",\"lastname\":\"Doe\",\"emails\":[\"johndoe@linagora.com\"]}";
        });

        $client->on('curlStuff', function(&$return) use (&$requestCount) {
            $return = [ [ 'http_code' => 200, 'header_size' => 40 ], 0, '' ];
        });

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'PHP_AUTH_USER' => 'username',
            'PHP_AUTH_PW' => 'password',
        ));
        $response = new \Sabre\HTTP\Response();

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals($esnauth->getCurrentPrincipal(), 'principals/users/123456789');
        $this->assertEquals($esnauth->getAuthCookies(), 'test=passed');
    }

    function testAuthenticateFailedCode() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();

        $client->on('curlExec', function(&$return) {
            $return = 'HTTP/1.1 403 Authentication Required';
        });
        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 403, 'header_size' => 0 ], 0, '' ];
        });


        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'PHP_AUTH_USER' => 'username',
            'PHP_AUTH_PW' => 'password',
        ));
        $response = new \Sabre\HTTP\Response();

        $server = new \Sabre\DAV\Server([]);
        $server->httpRequest = $request;
        $server->httpResponse = $response;

        $this->assertFalse(
            $esnauth->check($request, $response)[0]
        );
    }

    function testAuthenticateFailedJSON() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();

        $client->on('curlExec', function(&$return) {
            $return = '{ THIS IS NOT JSON!! }';
        });
        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 200, 'header_size' => 0 ], 0, '' ];
        });

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'PHP_AUTH_USER' => 'username',
            'PHP_AUTH_PW' => 'password',
        ));
        $response = new \Sabre\HTTP\Response();

        $server = new \Sabre\DAV\Server([]);
        $server->httpRequest = $request;
        $server->httpResponse = $response;

        $this->assertFalse(
            $esnauth->check($request, $response)[0]
        );
    }

    function testPluginCalled() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();

        $client->on('curlExec', function(&$return) {
            $return = "HTTP 200 OK\r\nSet-Cookie: test=passed\r\n\r\n{\"_id\":\"123456789\",\"type\":\"user\",\"firstname\":\"John\",\"lastname\":\"Doe\",\"emails\":[\"johndoe@linagora.com\"]}";
        });
        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 200, 'header_size' => 40 ], 0, '' ];
        });

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'PHP_AUTH_USER' => 'username',
            'PHP_AUTH_PW' => 'password',
        ));
        $response = new \Sabre\HTTP\Response();

        $server = new \Sabre\DAV\Server([]);
        $server->httpRequest = $request;
        $server->httpResponse = $response;

        $plugin = new ESNHookPluginMock('/', $esnauth);
        $server->addPlugin($plugin);

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals($msg, 'principals/users/123456789');
        $this->assertEquals($esnauth->getAuthCookies(), 'test=passed');

        $pluginrequest = $plugin->createRequest('principals/communities/123456789', 'calendars/123123/events', 'body');
        $this->assertEquals($pluginrequest->getHeader('Cookie'), 'test=passed');
    }
}

class ESNHookPluginMock extends \ESN\CalDAV\ESNHookPlugin {
    public function createRequest($owner, $path, $body) {
        return parent::createRequest($owner, $path, $body);
    }
}

class EsnMock extends Esn {
    function __construct($apiroot) {
        require_once ESN_TEST_VENDOR . '/sabre/http/tests/HTTP/ClientTest.php';
        parent::__construct($apiroot, "Realm");
        $this->httpClient = new \Sabre\HTTP\ClientMock();
    }

    function getClient() {
        return $this->httpClient;
    }
}
