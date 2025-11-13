<?php

namespace ESN\DAV\Auth\Backend;

$GLOBALS['__ldap_bind_behavior'] = 'default';

function ldap_bind($conn, $dn = null, $pwd = null) {
    switch ($GLOBALS['__ldap_bind_behavior']) {
        case 'return_false':
            return false;
        case 'throw':
            throw new \ErrorException("Simulated LDAP exception");
        default:
            return \ldap_bind($conn, $dn, $pwd);
    }
}

namespace ESN\DAV\Auth\Backend;

#[\AllowDynamicProperties]
class EsnTest extends \PHPUnit\Framework\TestCase {
    const USER_ID = '54313fcc398fef406b0041b6';
    const DOMAIN_ID = '5a095e2c46b72521d03f6d75';

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
    }

    function testAuthenticatePasswordSuccess() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();

        $requestCount = 0;

        $client->on('curlExec', function(&$return) use (&$requestCount) {
            $return = "HTTP 200 OK\r\nSet-Cookie: test=passed\r\n\r\n[{\"_id\":\"123456789\",\"firstname\":\"John\",\"lastname\":\"Doe\",\"emails\":[\"johndoe@linagora.com\"]}]";
        });

        $client->on('curlStuff', function(&$return) use (&$requestCount) {
            $return = [ [ 'http_code' => 200, 'header_size' => 40 ], 0, '' ];
        });

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'PHP_AUTH_USER' => 'username',
            'PHP_AUTH_PW' => 'password',
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
        ));
        $response = new \Sabre\HTTP\Response();

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals($esnauth->getCurrentPrincipal(), 'principals/users/123456789');
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
            'REQUEST_URI' => '/foo/bar',
            'REQUEST_METHOD' => 'GET',
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
            'REQUEST_URI' => '/foo/bar',
            'REQUEST_METHOD' => 'GET',
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
            $return = "HTTP 200 OK\r\nSet-Cookie: test=passed\r\n\r\n[{\"_id\":\"123456789\",\"type\":\"user\",\"firstname\":\"John\",\"lastname\":\"Doe\",\"emails\":[\"johndoe@linagora.com\"]}]";
        });
        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 200, 'header_size' => 40 ], 0, '' ];
        });

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'PHP_AUTH_USER' => 'username',
            'PHP_AUTH_PW' => 'password',
            'REQUEST_URI' => '/foo/bar',
            'REQUEST_METHOD' => 'GET',
        ));
        $response = new \Sabre\HTTP\Response();

        $server = new \Sabre\DAV\Server([]);
        $server->httpRequest = $request;
        $server->httpResponse = $response;

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals($msg, 'principals/users/123456789');
    }

    function testAuthenticationSuccessWithJWT() {
        $authNotificationResult = [];
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();
        $eventEmitter = $esnauth->getEventEmitter();
        $eventEmitter->on("auth:success", function($principal) use (&$authNotificationResult) {
            $authNotificationResult[] = $principal;
        });

        // insert a user into the db
        $esnDb = $esnauth->getDb();
        $esnDb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER_ID),
            'firstname' => 'first',
            'lastname' => 'last',
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ 'user1@open-paas.org' ] ]
            ],
            'domains' => [
                [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ]
            ]
        ]);
        // make a request
        $request = new \Sabre\HTTP\Request('GET', '/foo/bar');
  
        // Decoded token used in the Authorization Header
        // {
        //     ["sub"]=>
        //     string(19) "user1@open-paas.org"
        //     ["iat"]=>
        //     int(1611593604)
        // }
        $request->setHeader('Authorization', 'Bearer eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ1c2VyMUBvcGVuLXBhYXMub3JnIiwiaWF0IjoxNjExNTkzNjA0fQ.FddHtNdatKnSub_zAWxLFfFyf-azfGnpL-eBjAulLtIzPvDMDfYY0W5VWe4FIkmA59Gi0JxBq_topZM6mrrQyMtWEVBIb3IFHbHGYWtKcnrBmQN7UdLuaa7V5EEQ5_8JZU4l-qkXFeqYr-LTq2KTz3NiZPZgSaEcam4_C9ByzUBQ_-jMiMK5nb_gGGPMEUYGmxobrf7I9tXQHSqSHK58Igg67FnqtEMHsfrya4g_Fs13zSYk_TkUY9x0IpiU5g-PgjEN6-7ts1Or8maBQsmKgn9pqmsYEg3VM2QZIyw7MMNG9gLQRE6QRchlc1ReJZRJk6eZY2d-xenzX8lqn5e40A');
        $response = new \Sabre\HTTP\Response(200);

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals($esnauth->getCurrentPrincipal(), 'principals/users/' . self::USER_ID);
        $this->assertEquals(['principals/users/' . self::USER_ID], $authNotificationResult);
    }

    function testAuthenticationFailWithJwt() {
        $authNotificationResult = [];
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();
        $eventEmitter = $esnauth->getEventEmitter();
        $eventEmitter->on("auth:success", function($principal) use (&$authNotificationResult) {
            $authNotificationResult[] = $principal;
        });

        // make a request
        $request = new \Sabre\HTTP\Request('GET', '/foo/bar');
  
        // Decoded token used in the Authorization Header
        // {
        //     ["sub"]=>
        //     string(19) "user1@open-paas.org"
        //     ["iat"]=>
        //     int(1611593604)
        // }
        $request->setHeader('Authorization', 'Bearer eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ1c2VyMUBvcGVuLXBhYXMub3JnIiwiaWF0IjoxNjExNTkzNjA0fQ.FddHtNdatKnSub_zAWxLFfFyf-azfGnpL-eBjAulLtIzPvDMDfYY0W5VWe4FIkmA59Gi0JxBq_topZM6mrrQyMtWEVBIb3IFHbHGYWtKcnrBmQN7UdLuaa7V5EEQ5_8JZU4l-qkXFeqYr-LTq2KTz3NiZPZgSaEcam4_C9ByzUBQ_-jMiMK5nb_gGGPMEUYGmxobrf7I9tXQHSqSHK58Igg67FnqtEMHsfrya4g_Fs13zSYk_TkUY9x0IpiU5g-PgjEN6-7ts1Or8maBQsmKgn9pqmsYEg3VM2QZIyw7MMNG9gLQRE6QRchlc1ReJZRJk6eZY2d-xenzX8lqn5e40A');
        $response = new \Sabre\HTTP\Response(200);

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertFalse($rv);
        $this->assertNull($esnauth->getCurrentPrincipal());
        $this->assertEquals([], $authNotificationResult);
    }

    function testAuthenticationSuccessWithJwtAndFallbackToESNTOKEN() {
        $authNotificationResult = [];
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();
        $eventEmitter = $esnauth->getEventEmitter();
        $eventEmitter->on("auth:success", function($principal) use (&$authNotificationResult) {
            $authNotificationResult[] = $principal;
        });

        // simulate ESN API response
        $client->on('curlExec', function(&$return) {
            $return = "HTTP 200 OK\r\nSet-Cookie: test=passed\r\n\r\n{\"_id\":\"123456789\",\"user_type\":\"technical\",\"firstname\":\"John\",\"lastname\":\"Doe\",\"emails\":[\"johndoe@linagora.com\"]}";
        });

        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 200, 'header_size' => 40 ], 0, '' ];
        });

        // make a request
        $request = new \Sabre\HTTP\Request('GET', '/foo/bar');
        
        // Use ESNTOKEN as fallback
        $request->setHeader('ESNToken', '1234');
        // Decoded token used in the Authorization Header
        // {
        //     ["sub"]=>
        //     string(19) "user1@open-paas.org"
        //     ["iat"]=>
        //     int(1611593604)
        // }
        $request->setHeader('Authorization', 'Bearer eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ1c2VyMUBvcGVuLXBhYXMub3JnIiwiaWF0IjoxNjExNTkzNjA0fQ.FddHtNdatKnSub_zAWxLFfFyf-azfGnpL-eBjAulLtIzPvDMDfYY0W5VWe4FIkmA59Gi0JxBq_topZM6mrrQyMtWEVBIb3IFHbHGYWtKcnrBmQN7UdLuaa7V5EEQ5_8JZU4l-qkXFeqYr-LTq2KTz3NiZPZgSaEcam4_C9ByzUBQ_-jMiMK5nb_gGGPMEUYGmxobrf7I9tXQHSqSHK58Igg67FnqtEMHsfrya4g_Fs13zSYk_TkUY9x0IpiU5g-PgjEN6-7ts1Or8maBQsmKgn9pqmsYEg3VM2QZIyw7MMNG9gLQRE6QRchlc1ReJZRJk6eZY2d-xenzX8lqn5e40A');
        $response = new \Sabre\HTTP\Response(200);

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals($esnauth->getCurrentPrincipal(), 'principals/users/123456789');
        $this->assertEquals(['principals/users/123456789'], $authNotificationResult);
    }

    function testAuthenticationFailureWithExpiredJWT() {
        $authNotificationResult = [];
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();
        $eventEmitter = $esnauth->getEventEmitter();
        $eventEmitter->on("auth:success", function($principal) use (&$authNotificationResult) {
            $authNotificationResult[] = $principal;
        });

        // insert a user into the db
        $esnDb = $esnauth->getDb();
        $esnDb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER_ID),
            'firstname' => 'first',
            'lastname' => 'last',
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ 'user1@open-paas.org' ] ]
            ],
            'domains' => [
                [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ]
            ]
        ]);
        // make a request
        $request = new \Sabre\HTTP\Request('GET', '/foo/bar');
  
        // Decoded token used in the Authorization Header, this is an expired token!
        // {
        //     ["sub"]=>
        //     string(19) "user1@open-paas.org"
        //     ["iat"]=>
        //     int(1516239021)
        //     ["exp"]=>
        //     int(1516239123) // 18 Jan 2018
        // }
        $request->setHeader('Authorization', 'Bearer eyJhbGciOiJSUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiJ1c2VyMUBvcGVuLXBhYXMub3JnIiwiaWF0IjoxNTE2MjM5MDIxLCJleHAiOjE1MTYyMzkxMjN9.rShcskc7MbQ8tCNfka15vf2quVEa6brx1HvSfXGUWF-1aY8Lx-7UzxluW-E545CA0plnmNb_8vIGvp2UFuEZ219GiopVVswVXQTXlU6_XjJWv6SRc-uAXbF0XHd1plcrtK5VhUWBmaWCC0TUokvIXDpX67V4gC5urCPhfgw8ekbLX1jTeApe62ZIPaSCfbrqx1zIgwoXBEUpejp7L-LW3_syRIhp2Q1Tg1C59ryiNSQnIgrDrHhO8SEmrJepn6lcjXBtlvpp9kPCOM8IlrKUdXNrOaPwVW1naVlLmjM-IaTv6WTKfjUu4xQSCyOqs5V5G_GJJGgnXTRplHleCWqfpg');
        $response = new \Sabre\HTTP\Response(200);

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertFalse($rv);
        $this->assertNull($esnauth->getCurrentPrincipal());
        $this->assertEquals([], $authNotificationResult);
    }


    function testAuthenticateInvalidLdapCredential() {
        $GLOBALS['__ldap_bind_behavior'] = 'return_false';

        $esnauth = new EsnMock('http://localhost:8080/');
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'PHP_AUTH_USER' => 'wronguser',
            'PHP_AUTH_PW'   => 'wrongpass',
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
        ]);
        $response = new \Sabre\HTTP\Response();

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertFalse($rv);
        $this->assertEquals("Username or password was incorrect", $msg);

        $GLOBALS['__ldap_bind_behavior'] = 'default';
    }

    function testAuthenticateLdapBindThrowsException() {
        $GLOBALS['__ldap_bind_behavior'] = 'throw';

        $esnauth = new EsnMock('http://localhost:8080/');
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'PHP_AUTH_USER' => 'wronguser',
            'PHP_AUTH_PW'   => 'wrongpass',
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
        ]);
        $response = new \Sabre\HTTP\Response();

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertFalse($rv);
        $this->assertEquals("Username or password was incorrect", $msg);

        $GLOBALS['__ldap_bind_behavior'] = 'default';
    }

}

class EsnMock extends Esn {
    function __construct($apiroot) {
        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->{ESN_MONGO_ESNDB};
        $this->esndb->drop();
        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);

        $logger = \ESN\Log\EsnLoggerFactory::initLogger(null);
        $loggerPlugin = new \ESN\Log\ExceptionLoggerPlugin($logger);

        $server = new \Sabre\DAV\Server([]);
        $server->addPlugin($loggerPlugin);

        require_once ESN_TEST_VENDOR . '/sabre/http/tests/HTTP/ClientTest.php';
        parent::__construct($apiroot, "Realm", $this->principalBackend, $server);
        $this->httpClient = new \Sabre\HTTP\ClientMock();
    }

    function getClient() {
        return $this->httpClient;
    }

    function getDb() {
        return $this->esndb;
    }
}
