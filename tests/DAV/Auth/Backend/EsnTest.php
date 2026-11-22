<?php

namespace ESN\DAV\Auth\Backend;

$GLOBALS['__ldap_bind_behavior'] = 'default';
$GLOBALS['__ldap_mock_enabled'] = false;
$GLOBALS['__ldap_mock_email'] = 'johndoe@linagora.com';
$GLOBALS['__ldap_mock_entries_empty'] = false;
$GLOBALS['__ldap_mock_entry_without_mail'] = false;
$GLOBALS['__ldap_mock_search_fails'] = false;
$GLOBALS['__ldap_mock_duplicate_entries'] = false;
$GLOBALS['__ldap_last_search_filter'] = null;
$GLOBALS['__ldap_errno'] = 49;
$GLOBALS['__ldap_bind_calls'] = 0;

function ldap_connect($host) {
    if ($GLOBALS['__ldap_mock_enabled']) {
        return 'mock_ldap_connection';
    }
    return \ldap_connect($host);
}

function ldap_set_option($conn, $option, $value) {
    if ($GLOBALS['__ldap_mock_enabled']) {
        return true;
    }
    return \ldap_set_option($conn, $option, $value);
}

function ldap_bind($conn, $dn = null, $pwd = null) {
    $GLOBALS['__ldap_bind_calls']++;
    switch ($GLOBALS['__ldap_bind_behavior']) {
        case 'return_false':
            return false;
        case 'throw':
            throw new \ErrorException("Simulated LDAP exception");
        case 'admin_false':
            // User bind succeeds, service account bind fails
            return str_starts_with((string) $dn, 'uid=');
        default:
            if ($GLOBALS['__ldap_mock_enabled']) {
                return true;
            }
            return \ldap_bind($conn, $dn, $pwd);
    }
}

function ldap_search($conn, $base, $filter) {
    if ($GLOBALS['__ldap_mock_enabled']) {
        $GLOBALS['__ldap_last_search_filter'] = $filter;
        if ($GLOBALS['__ldap_mock_search_fails']) {
            return false;
        }
        return 'mock_ldap_search_result';
    }
    return \ldap_search($conn, $base, $filter);
}

function ldap_get_entries($conn, $result) {
    if ($GLOBALS['__ldap_mock_enabled']) {
        if ($GLOBALS['__ldap_mock_entries_empty']) {
            return [ 'count' => 0 ];
        }
        if ($GLOBALS['__ldap_mock_entry_without_mail']) {
            return [ 'count' => 1, 0 => [ 'uid' => ['alice', 'count' => 1], 'count' => 1 ] ];
        }
        $entry = [
            'mail' => [$GLOBALS['__ldap_mock_email'], 'count' => 1],
            'givenname' => ['John', 'count' => 1],
            'sn' => ['Doe', 'count' => 1],
            'count' => 3
        ];
        if ($GLOBALS['__ldap_mock_duplicate_entries']) {
            return [ 'count' => 2, 0 => $entry, 1 => $entry ];
        }
        return [ 'count' => 1, 0 => $entry ];
    }
    return \ldap_get_entries($conn, $result);
}

function ldap_close($conn) {
    if ($GLOBALS['__ldap_mock_enabled']) {
        return true;
    }
    return \ldap_close($conn);
}

function ldap_errno($conn) {
    return $GLOBALS['__ldap_errno'];
}

function ldap_error($conn) {
    return "Simulated LDAP error";
}

function ldap_escape($str, $ignore = '', $flags = 0) {
    if ($GLOBALS['__ldap_mock_enabled']) {
        return $str;
    }
    return \ldap_escape($str, $ignore, $flags);
}

namespace ESN\DAV\Auth\Backend;

#[\AllowDynamicProperties]
class EsnTest extends \PHPUnit\Framework\TestCase {
    const USER_ID = '54313fcc398fef406b0041b6';
    const DOMAIN_ID = '5a095e2c46b72521d03f6d75';
    const OTHER_USER_ID = '54313fcc398fef406b0041b7';


    function testAuthenticatePasswordSuccess() {
        $GLOBALS['__ldap_mock_enabled'] = true;

        $esnauth = new EsnMock('http://localhost:8080/');

        // Insert a user into the db with email that matches LDAP
        $esnDb = $esnauth->getDb();
        $userId = new \MongoDB\BSON\ObjectId('123456789012345678901234');
        $domainId = new \MongoDB\BSON\ObjectId('098765432109876543210987');
        $esnDb->users->insertOne([
            '_id' => $userId,
            'firstname' => 'John',
            'lastname' => 'Doe',
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ 'johndoe@linagora.com' ] ]
            ],
            'domains' => [
                [ 'domain_id' => $domainId ]
            ]
        ]);
        $esnDb->domains->insertOne([
            '_id' => $domainId,
            'name' => 'linagora.com',
            'administrators' => [
                [
                    'user_id' => $userId
                ]
            ]
        ]);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'PHP_AUTH_USER' => 'username',
            'PHP_AUTH_PW' => 'password',
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
        ));
        $response = new \Sabre\HTTP\Response();

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals($esnauth->getCurrentPrincipal(), 'principals/users/123456789012345678901234');

        $GLOBALS['__ldap_mock_enabled'] = false;
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
        $GLOBALS['__ldap_mock_enabled'] = true;

        $esnauth = new EsnMock('http://localhost:8080/');

        // Insert a user into the db with email that matches LDAP
        $esnDb = $esnauth->getDb();
        $userId = new \MongoDB\BSON\ObjectId('123456789012345678901234');
        $domainId = new \MongoDB\BSON\ObjectId('098765432109876543210987');
        $esnDb->users->insertOne([
            '_id' => $userId,
            'firstname' => 'John',
            'lastname' => 'Doe',
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ 'johndoe@linagora.com' ] ]
            ],
            'domains' => [
                [ 'domain_id' => $domainId ]
            ]
        ]);
        $esnDb->domains->insertOne([
            '_id' => $domainId,
            'name' => 'linagora.com',
            'administrators' => [
                [
                    'user_id' => $userId
                ]
            ]
        ]);


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
        $this->assertEquals($msg, 'principals/users/123456789012345678901234');

        $GLOBALS['__ldap_mock_enabled'] = false;
    }

    function testAuthenticationSuccessWithJWT() {
        $authNotificationResult = [];
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();
        $esnauth->getServer()->on("auth:success", function($tenant) use (&$authNotificationResult) {
            $authNotificationResult[] = $tenant->getPrincipal();
        });

        // insert a user into the db
        $esnDb = $esnauth->getDb();
        $userId = new \MongoDB\BSON\ObjectId(self::USER_ID);
        $domainId = new \MongoDB\BSON\ObjectId(self::DOMAIN_ID);
        $esnDb->users->insertOne([
            '_id' => $userId,
            'firstname' => 'first',
            'lastname' => 'last',
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ 'user1@open-paas.org' ] ]
            ],
            'domains' => [
                [ 'domain_id' => $domainId ]
            ]
        ]);
        $esnDb->domains->insertOne([
            '_id' => $domainId,
            'name' => 'open-paas.org',
            'administrators' => [
                [
                    'user_id' => $userId
                ]
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
        $esnauth->getServer()->on("auth:success", function($tenant) use (&$authNotificationResult) {
            $authNotificationResult[] = $tenant->getPrincipal();
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


    function testAuthenticationFailureWithExpiredJWT() {
        $authNotificationResult = [];
        $esnauth = new EsnMock('http://localhost:8080/');
        $client = $esnauth->getClient();
        $esnauth->getServer()->on("auth:success", function($tenant) use (&$authNotificationResult) {
            $authNotificationResult[] = $tenant->getPrincipal();
        });

        // insert a user into the db
        $esnDb = $esnauth->getDb();
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
        $this->assertEquals("Bad credentials", $msg);

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
        $this->assertEquals("Bad credentials", $msg);

        $GLOBALS['__ldap_bind_behavior'] = 'default';
    }


    function testAuthenticateLdapServerDownReturns503() {
        $GLOBALS['__ldap_mock_enabled'] = true;
        $GLOBALS['__ldap_bind_behavior'] = 'return_false';
        $GLOBALS['__ldap_errno'] = -1; // LDAP_SERVER_DOWN

        try {
            $this->assertLdapUnavailable();
        } finally {
            $this->resetLdapMock();
        }
    }

    function testAuthenticateLdapBindThrowsWhileServerDownReturns503() {
        $GLOBALS['__ldap_mock_enabled'] = true;
        $GLOBALS['__ldap_bind_behavior'] = 'throw';
        $GLOBALS['__ldap_errno'] = -1; // LDAP_SERVER_DOWN

        try {
            $this->assertLdapUnavailable();
        } finally {
            $this->resetLdapMock();
        }
    }

    function testAuthenticateLdapBusyReturns503() {
        $GLOBALS['__ldap_mock_enabled'] = true;
        $GLOBALS['__ldap_bind_behavior'] = 'return_false';
        $GLOBALS['__ldap_errno'] = 51; // LDAP_BUSY

        try {
            $this->assertLdapUnavailable();
        } finally {
            $this->resetLdapMock();
        }
    }

    function testAuthenticateLdapAdminBindFailureReturns503() {
        $GLOBALS['__ldap_mock_enabled'] = true;
        $GLOBALS['__ldap_bind_behavior'] = 'admin_false';

        try {
            $this->assertLdapUnavailable();
        } finally {
            $this->resetLdapMock();
        }
    }

    function testAuthenticateLdapSearchFailureReturns503() {
        $GLOBALS['__ldap_mock_enabled'] = true;
        $GLOBALS['__ldap_mock_search_fails'] = true;

        try {
            $this->assertLdapUnavailable();
        } finally {
            $this->resetLdapMock();
        }
    }

    function testAuthenticateWithoutLdapServerReturns401() {
        $ldapServer = getenv('LDAP_SERVER');
        putenv('LDAP_SERVER');

        try {
            $esnauth = new EsnMock('http://localhost:8080/');
            $request = \Sabre\HTTP\Sapi::createFromServerArray([
                'PHP_AUTH_USER' => 'username',
                'PHP_AUTH_PW'   => 'password',
                'REQUEST_URI' => '/',
                'REQUEST_METHOD' => 'GET',
            ]);
            $response = new \Sabre\HTTP\Response();

            list($rv, $msg) = $esnauth->check($request, $response);

            $this->assertFalse($rv);
            $this->assertEquals('LDAP authentication is disabled', $msg);
        } finally {
            if ($ldapServer !== false) {
                putenv("LDAP_SERVER=$ldapServer");
            }
        }
    }

    private function assertLdapUnavailable() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'PHP_AUTH_USER' => 'username',
            'PHP_AUTH_PW'   => 'password',
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
        ]);
        $response = new \Sabre\HTTP\Response();

        try {
            $esnauth->check($request, $response);
            $this->fail('Expected LdapUnavailableException');
        } catch (LdapUnavailableException $e) {
            $this->assertEquals(503, $e->getHTTPCode());
        }
    }

    private function resetLdapMock() {
        $GLOBALS['__ldap_mock_enabled'] = false;
        $GLOBALS['__ldap_bind_behavior'] = 'default';
        $GLOBALS['__ldap_mock_search_fails'] = false;
        $GLOBALS['__ldap_mock_entry_without_mail'] = false;
        $GLOBALS['__ldap_mock_entries_empty'] = false;
        $GLOBALS['__ldap_mock_duplicate_entries'] = false;
        $GLOBALS['__ldap_mock_email'] = 'johndoe@linagora.com';
        $GLOBALS['__ldap_last_search_filter'] = null;
        $GLOBALS['__ldap_errno'] = 49;
        $GLOBALS['__ldap_bind_calls'] = 0;
    }

    private function mockIntrospectionAnswer(EsnMock $esnauth, string $body) {
        $client = $esnauth->getClient();
        $client->on('curlExec', function(&$return) use ($body) {
            $return = $body;
        });
        $client->on('curlStuff', function(&$return) {
            $return = [ [ 'http_code' => 200, 'header_size' => 0 ], 0, '' ];
        });
    }

    private function checkWithTechnicalToken(EsnMock $esnauth): array {
        $request = new \Sabre\HTTP\Request('GET', '/', ['TwakeCalendarToken' => '1']);
        return $esnauth->check($request, new \Sabre\HTTP\Response());
    }

    private function checkWithBasicAuth(EsnMock $esnauth): array {
        $request = new \Sabre\HTTP\Request('GET', '/', [
            'Authorization' => 'Basic ' . base64_encode('alice:secret'),
        ]);
        return $esnauth->check($request, new \Sabre\HTTP\Response());
    }

    function testIntrospectionAnswerWithNullIdIsAnAuthFailure() {
        $esnauth = new EsnMock('http://localhost:8080/', false);
        $this->mockIntrospectionAnswer($esnauth, '{"_id":null,"domainId":"dd"}');

        $this->assertSame([false, 'Authentification failure'], $this->checkWithTechnicalToken($esnauth));
        $this->assertNull($esnauth->getCurrentPrincipal());
    }

    function testIntrospectionAnswerWithoutIdIsAnAuthFailure() {
        $esnauth = new EsnMock('http://localhost:8080/', false);
        $this->mockIntrospectionAnswer($esnauth, '{"domainId":"dd","email":"a@b.com"}');

        $this->assertSame([false, 'Authentification failure'], $this->checkWithTechnicalToken($esnauth));
    }

    function testIntrospectionAnswerWithNonStringDomainIdIsAnAuthFailure() {
        $esnauth = new EsnMock('http://localhost:8080/', false);
        $this->mockIntrospectionAnswer($esnauth, '{"_id":"id","domainId":{"a":1},"email":"a@b.com"}');

        $this->assertSame([false, 'Authentification failure'], $this->checkWithTechnicalToken($esnauth));
    }

    function testIntrospectionAnswerWithObjectDomainIsAnAuthFailure() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->mockIntrospectionAnswer($esnauth, '{"_id":"id","domainId":"dd","domain":{"a":1}}');

        $this->assertSame([false, 'decodeResponse(): invalid domain'], $this->checkWithTechnicalToken($esnauth));
    }

    function testIntrospectionAnswerWithObjectEmailIsAnAuthFailure() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->mockIntrospectionAnswer($esnauth, '{"_id":"id","domainId":"dd","email":["a@b.com"]}');

        $this->assertSame([false, 'decodeResponse(): no user found'], $this->checkWithTechnicalToken($esnauth));
    }

    function testIntrospectionAnswerWhichIsNotAnObjectIsAnAuthFailure() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->mockIntrospectionAnswer($esnauth, '"just a string"');

        $this->assertSame([false, 'decodeResponse(): no user found'], $this->checkWithTechnicalToken($esnauth));
    }

    function testLdapEntryWithoutMailIsAnAuthFailure() {
        $GLOBALS['__ldap_mock_enabled'] = true;
        $GLOBALS['__ldap_mock_entry_without_mail'] = true;

        try {
            $esnauth = new EsnMock('http://localhost:8080/', false);

            $this->assertSame([false, 'Authentification failure'], $this->checkWithBasicAuth($esnauth));
        } finally {
            $this->resetLdapMock();
        }
    }

    function testInternalErrorTextIsNotReturnedInNonDebugMode() {
        $GLOBALS['__ldap_mock_enabled'] = true;

        try {
            $esnauth = new EsnMock('http://localhost:8080/', false);
            $esnauth->setPrincipalBackend(new FailingPrincipalBackend());

            $this->assertSame([false, 'Authentification failure'], $this->checkWithBasicAuth($esnauth));
        } finally {
            $this->resetLdapMock();
        }
    }

    function testInternalErrorTextIsReturnedInDebugMode() {
        $GLOBALS['__ldap_mock_enabled'] = true;

        try {
            $esnauth = new EsnMock('http://localhost:8080/');
            $esnauth->setPrincipalBackend(new FailingPrincipalBackend());

            $this->assertSame([false, FailingPrincipalBackend::MESSAGE], $this->checkWithBasicAuth($esnauth));
        } finally {
            $this->resetLdapMock();
        }
    }

    function testAuthenticateEmptyPasswordIsRejectedWithoutBinding() {
        $GLOBALS['__ldap_mock_enabled'] = true;
        $GLOBALS['__ldap_bind_calls'] = 0;

        try {
            $esnauth = new EsnMock('http://localhost:8080/');
            $request = new \Sabre\HTTP\Request('GET', '/', [
                'Authorization' => 'Basic ' . base64_encode('username:'),
            ]);
            $response = new \Sabre\HTTP\Response();

            list($rv, $msg) = $esnauth->check($request, $response);

            $this->assertFalse($rv);
            $this->assertEquals("Bad credentials", $msg);
            $this->assertNull($esnauth->getCurrentPrincipal());
            $this->assertEquals(0, $GLOBALS['__ldap_bind_calls']);
        } finally {
            $this->resetLdapMock();
        }
    }

    function testAdminImpersonationEnabledWithCorrectPassword() {
        putenv('SABRE_IMPERSONATION_ENABLED=true');

        $esnauth = new EsnMock('http://localhost:8080/');

        // Insert a user into the db
        $esnDb = $esnauth->getDb();
        $userId = new \MongoDB\BSON\ObjectId('999999999999999999999999');
        $domainId = new \MongoDB\BSON\ObjectId('888888888888888888888888');
        $esnDb->users->insertOne([
            '_id' => $userId,
            'firstname' => 'Test',
            'lastname' => 'User',
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ 'user@example.com' ] ]
            ],
            'domains' => [
                [ 'domain_id' => $domainId ]
            ]
        ]);
        $esnDb->domains->insertOne([
            '_id' => $domainId,
            'name' => 'example.com',
            'administrators' => [
                [
                    'user_id' => $userId
                ]
            ]
        ]);

        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'PHP_AUTH_USER' => 'admin&user@example.com',
            'PHP_AUTH_PW'   => 'test-admin-password',
            'REQUEST_URI'   => '/',
            'REQUEST_METHOD'=> 'GET',
        ]);
        $response = new \Sabre\HTTP\Response();

        [$rv, $msg] = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals('principals/users/999999999999999999999999', $msg);
    }

    function testAdminImpersonationEnabledWithWrongPassword() {
        putenv('SABRE_IMPERSONATION_ENABLED=true');

        $esnauth = new EsnMock('http://localhost:8080/');

        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'PHP_AUTH_USER' => 'admin&user@example.com',
            'PHP_AUTH_PW'   => 'wrong-password',
            'REQUEST_URI'   => '/',
            'REQUEST_METHOD'=> 'GET',
        ]);
        $response = new \Sabre\HTTP\Response();

        [$rv, $msg] = $esnauth->check($request, $response);

        $this->assertFalse($rv);
        $this->assertEquals('Bad admin password', $msg);
    }

    function testAdminImpersonationWithResourceEmail() {
        putenv('SABRE_IMPERSONATION_ENABLED=true');

        $esnauth = new EsnMock('http://localhost:8080/');

        $esnDb = $esnauth->getDb();
        $resourceId = new \MongoDB\BSON\ObjectId('888888888888888888888888');
        $domainId = new \MongoDB\BSON\ObjectId('098765432109876543210987');
        $esnDb->resources->insertOne([
            '_id' => $resourceId,
            'name' => 'Test Resource',
            'domain' => $domainId
        ]);
        $esnDb->domains->insertOne([
            '_id' => $domainId,
            'name' => 'example.com',
            'administrators' => [
            ]
        ]);


        $resourceEmail = '888888888888888888888888@example.com';
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'PHP_AUTH_USER' => 'admin&' . $resourceEmail,
            'PHP_AUTH_PW'   => 'test-admin-password',
            'REQUEST_URI'   => '/',
            'REQUEST_METHOD'=> 'GET',
        ]);
        $response = new \Sabre\HTTP\Response();

        [$rv, $msg] = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals('principals/resources/888888888888888888888888', $msg);
    }

    function testAdminImpersonationWithTeamCalendarEmail() {
        putenv('SABRE_IMPERSONATION_ENABLED=true');

        $esnauth = new EsnMock('http://localhost:8080/');

        $esnDb = $esnauth->getDb();
        $teamCalendarId = new \MongoDB\BSON\ObjectId('777777777777777777777777');
        $domainId = new \MongoDB\BSON\ObjectId('098765432109876543210987');
        $esnDb->team_calendars->insertOne([
            '_id' => $teamCalendarId,
            'domainId' => $domainId,
            'domainName' => 'example.com',
            'name' => 'sales',
            'displayName' => 'Sales Team'
        ]);

        $teamCalendarEmail = '777777777777777777777777@example.com';
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'PHP_AUTH_USER' => 'admin&' . $teamCalendarEmail,
            'PHP_AUTH_PW'   => 'test-admin-password',
            'REQUEST_URI'   => '/',
            'REQUEST_METHOD'=> 'GET',
        ]);
        $response = new \Sabre\HTTP\Response();

        [$rv, $msg] = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals('principals/team-calendars/777777777777777777777777', $msg);
    }

    function testAdminImpersonationWithResourceEmailNotFound() {
        putenv('SABRE_IMPERSONATION_ENABLED=true');

        $esnauth = new EsnMock('http://localhost:8080/');

        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'PHP_AUTH_USER' => 'admin&000000000000000000000000@example.com',
            'PHP_AUTH_PW'   => 'test-admin-password',
            'REQUEST_URI'   => '/',
            'REQUEST_METHOD'=> 'GET',
        ]);
        $response = new \Sabre\HTTP\Response();

        [$rv, $msg] = $esnauth->check($request, $response);

        $this->assertFalse($rv);
    }

    function testAutoProvisionOnLdapWhenUserMissing() {
        $GLOBALS['__ldap_mock_enabled'] = true;
        putenv('AUTO_PROVISION');

        $esnauth = new EsnMock('http://localhost:8080/');

        // The domain exists but the user does not yet: it must be auto-provisioned.
        $esnDb = $esnauth->getDb();
        $domainId = new \MongoDB\BSON\ObjectId('098765432109876543210987');
        $esnDb->domains->insertOne([
            '_id' => $domainId,
            'name' => 'linagora.com',
            'administrators' => []
        ]);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'PHP_AUTH_USER' => 'username',
            'PHP_AUTH_PW' => 'password',
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
        ));
        $response = new \Sabre\HTTP\Response();

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertTrue($rv);

        $created = $esnDb->users->findOne([ 'accounts.emails' => 'johndoe@linagora.com' ]);
        $this->assertNotNull($created);
        $this->assertEquals('principals/users/' . (string) $created['_id'], $msg);
        $this->assertEquals('johndoe@linagora.com', $created['email']);
        $this->assertEquals($domainId, $created['domains'][0]['domain_id']);
        // firstname/lastname are backed by the LDAP entry content.
        $this->assertEquals('John', $created['firstname']);
        $this->assertEquals('Doe', $created['lastname']);

        $GLOBALS['__ldap_mock_enabled'] = false;
    }

    function testAutoProvisionDisabledReturns401() {
        $GLOBALS['__ldap_mock_enabled'] = true;
        putenv('AUTO_PROVISION=false');

        $esnauth = new EsnMock('http://localhost:8080/');

        $esnDb = $esnauth->getDb();
        $domainId = new \MongoDB\BSON\ObjectId('098765432109876543210987');
        $esnDb->domains->insertOne([
            '_id' => $domainId,
            'name' => 'linagora.com',
            'administrators' => []
        ]);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'PHP_AUTH_USER' => 'username',
            'PHP_AUTH_PW' => 'password',
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
        ));
        $response = new \Sabre\HTTP\Response();

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertFalse($rv);
        $this->assertNull($esnDb->users->findOne([ 'accounts.emails' => 'johndoe@linagora.com' ]));

        putenv('AUTO_PROVISION');
        $GLOBALS['__ldap_mock_enabled'] = false;
    }

    function testAutoProvisionSkippedWhenDomainMissing() {
        $GLOBALS['__ldap_mock_enabled'] = true;
        putenv('AUTO_PROVISION=true');

        $esnauth = new EsnMock('http://localhost:8080/');
        // No domain inserted: the user cannot be attached to a tenant.

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'PHP_AUTH_USER' => 'username',
            'PHP_AUTH_PW' => 'password',
            'REQUEST_URI' => '/',
            'REQUEST_METHOD' => 'GET',
        ));
        $response = new \Sabre\HTTP\Response();

        list($rv, $msg) = $esnauth->check($request, $response);

        $this->assertFalse($rv);
        $this->assertNull($esnauth->getDb()->users->findOne([ 'accounts.emails' => 'johndoe@linagora.com' ]));

        putenv('AUTO_PROVISION');
        $GLOBALS['__ldap_mock_enabled'] = false;
    }

    function testAutoProvisionOnImpersonationWhenUserMissing() {
        putenv('SABRE_IMPERSONATION_ENABLED=true');
        putenv('AUTO_PROVISION=true');
        // The impersonated user must exist in the LDAP directory to be provisioned.
        $GLOBALS['__ldap_mock_enabled'] = true;

        $esnauth = new EsnMock('http://localhost:8080/');

        $esnDb = $esnauth->getDb();
        $domainId = new \MongoDB\BSON\ObjectId('888888888888888888888888');
        $esnDb->domains->insertOne([
            '_id' => $domainId,
            'name' => 'example.com',
            'administrators' => []
        ]);

        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'PHP_AUTH_USER' => 'admin&newcomer@example.com',
            'PHP_AUTH_PW'   => 'test-admin-password',
            'REQUEST_URI'   => '/',
            'REQUEST_METHOD'=> 'GET',
        ]);
        $response = new \Sabre\HTTP\Response();

        [$rv, $msg] = $esnauth->check($request, $response);

        $this->assertTrue($rv);

        $created = $esnDb->users->findOne([ 'accounts.emails' => 'newcomer@example.com' ]);
        $this->assertNotNull($created);
        $this->assertEquals('principals/users/' . (string) $created['_id'], $msg);
        $this->assertEquals($domainId, $created['domains'][0]['domain_id']);
        $this->assertEquals('John', $created['firstname']);
        $this->assertEquals('Doe', $created['lastname']);

        $GLOBALS['__ldap_mock_enabled'] = false;
        putenv('AUTO_PROVISION');
        putenv('SABRE_IMPERSONATION_ENABLED=false');
    }

    function testAutoProvisionSkippedWhenUserNotInLdap() {
        putenv('SABRE_IMPERSONATION_ENABLED=true');
        putenv('AUTO_PROVISION=true');
        // The LDAP directory returns no entry: the user must not be provisioned.
        $GLOBALS['__ldap_mock_enabled'] = true;
        $GLOBALS['__ldap_mock_entries_empty'] = true;

        $esnauth = new EsnMock('http://localhost:8080/');

        $esnDb = $esnauth->getDb();
        $domainId = new \MongoDB\BSON\ObjectId('888888888888888888888888');
        $esnDb->domains->insertOne([
            '_id' => $domainId,
            'name' => 'example.com',
            'administrators' => []
        ]);

        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'PHP_AUTH_USER' => 'admin&ghost@example.com',
            'PHP_AUTH_PW'   => 'test-admin-password',
            'REQUEST_URI'   => '/',
            'REQUEST_METHOD'=> 'GET',
        ]);
        $response = new \Sabre\HTTP\Response();

        [$rv, $msg] = $esnauth->check($request, $response);

        $this->assertFalse($rv);
        $this->assertNull($esnDb->users->findOne([ 'accounts.emails' => 'ghost@example.com' ]));

        $GLOBALS['__ldap_mock_entries_empty'] = false;
        $GLOBALS['__ldap_mock_enabled'] = false;
        putenv('AUTO_PROVISION');
        putenv('SABRE_IMPERSONATION_ENABLED=false');
    }

    function testAdminImpersonationDisabled() {
        putenv('SABRE_IMPERSONATION_ENABLED=false');

        $esnauth = new EsnMock('http://localhost:8080/');

        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'PHP_AUTH_USER' => 'admin&user@example.com',
            'PHP_AUTH_PW'   => 'test-admin-password',
            'REQUEST_URI'   => '/',
            'REQUEST_METHOD'=> 'GET',
        ]);
        $response = new \Sabre\HTTP\Response();

        [$rv, $msg] = $esnauth->check($request, $response);

        $this->assertFalse($rv);
        $this->assertEquals('Bad credentials', $msg);
    }

    static function nonAdminImpersonationLogins() {
        return [
            'user then other user' => ['alice@example.com&bob@example.com', 'true'],
            'user then admin' => ['bob@example.com&admin', 'true'],
            'impersonation disabled' => ['alice@example.com&bob@example.com', 'false'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonAdminImpersonationLogins')]
    function testNonAdminLoginWithImpersonationSeparatorIsRejectedWithoutBinding(string $login, string $impersonationEnabled) {
        putenv('SABRE_IMPERSONATION_ENABLED=' . $impersonationEnabled);
        putenv('LDAP_USERNAME_MODE=username');
        $GLOBALS['__ldap_mock_enabled'] = true;
        $GLOBALS['__ldap_bind_calls'] = 0;

        try {
            $esnauth = new EsnMock('http://localhost:8080/');
            $request = new \Sabre\HTTP\Request('GET', '/', [
                'Authorization' => 'Basic ' . base64_encode($login . ':secret'),
            ]);

            [$rv, $msg] = $esnauth->check($request, new \Sabre\HTTP\Response());

            $this->assertFalse($rv);
            $this->assertEquals('Bad credentials', $msg);
            $this->assertNull($esnauth->getCurrentPrincipal());
            $this->assertEquals(0, $GLOBALS['__ldap_bind_calls']);
        } finally {
            $this->resetLdapMock();
            putenv('LDAP_USERNAME_MODE');
            putenv('SABRE_IMPERSONATION_ENABLED=false');
        }
    }

    static function localPartAmpersandLogins() {
        return [
            'impersonation enabled' => ['r&d@example.com', 'true', 'username'],
            'impersonation disabled' => ['r&d@example.com', 'false', 'username'],
            'email mode' => ['r&d@example.com', 'true', 'email'],
            'no domain' => ['r&d', 'true', 'username'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('localPartAmpersandLogins')]
    function testLoginWithAnAmpersandInItsLocalPartReachesTheLdapBind(string $login, string $impersonationEnabled, string $usernameMode) {
        putenv('SABRE_IMPERSONATION_ENABLED=' . $impersonationEnabled);
        putenv('LDAP_USERNAME_MODE=' . $usernameMode);
        $GLOBALS['__ldap_mock_enabled'] = true;
        $GLOBALS['__ldap_bind_calls'] = 0;

        try {
            $esnauth = new EsnMock('http://localhost:8080/');
            $request = new \Sabre\HTTP\Request('GET', '/', [
                'Authorization' => 'Basic ' . base64_encode($login . ':secret'),
            ]);

            [$rv, $msg] = $esnauth->check($request, new \Sabre\HTTP\Response());

            $this->assertNotEquals('Bad credentials', $msg);
            $this->assertGreaterThan(0, $GLOBALS['__ldap_bind_calls']);
        } finally {
            $this->resetLdapMock();
            putenv('LDAP_USERNAME_MODE');
            putenv('SABRE_IMPERSONATION_ENABLED=false');
        }
    }

    private bool $ldapServerOverridden = false;
    private string|false $originalLdapServer = false;

    protected function tearDown(): void {
        putenv('KERBEROS_ENABLED');
        putenv('KERBEROS_REALM_DOMAINS');
        putenv('KERBEROS_LDAP_PRINCIPAL_FILTER');
        putenv('LDAP_FILTER');
        if ($this->ldapServerOverridden) {
            putenv($this->originalLdapServer === false ? 'LDAP_SERVER' : "LDAP_SERVER=$this->originalLdapServer");
            $this->ldapServerOverridden = false;
            $this->resetLdapMock();
        }
    }

    private function enableKerberosWithoutLdap() {
        $this->enableKerberos();
        $this->overrideLdapServer('LDAP_SERVER');
    }

    private function enableKerberosWithMockedLdap(string $directoryMail) {
        $this->enableKerberos();
        $this->overrideLdapServer('LDAP_SERVER=ldap://mocked');
        $GLOBALS['__ldap_mock_enabled'] = true;
        $GLOBALS['__ldap_mock_email'] = $directoryMail;
    }

    private function overrideLdapServer(string $assignment) {
        if (!$this->ldapServerOverridden) {
            $this->originalLdapServer = getenv('LDAP_SERVER');
            $this->ldapServerOverridden = true;
        }
        putenv($assignment);
    }

    private function enableKerberos() {
        putenv('KERBEROS_ENABLED=true');
        putenv('KERBEROS_REALM_DOMAINS=CORP.EXAMPLE.COM:example.com');
    }

    private function insertExampleUser(EsnMock $esnauth, string $id, array $user) {
        $esnDb = $esnauth->getDb();
        $domainId = new \MongoDB\BSON\ObjectId(self::DOMAIN_ID);
        $esnDb->users->insertOne(array_merge([
            '_id' => new \MongoDB\BSON\ObjectId($id),
            'domains' => [ [ 'domain_id' => $domainId ] ]
        ], $user));
        if (!$esnDb->domains->findOne([ '_id' => $domainId ])) {
            $esnDb->domains->insertOne([ '_id' => $domainId, 'name' => 'example.com' ]);
        }
    }

    private function kerberosRequest(string $principal) {
        return \Sabre\HTTP\Sapi::createFromServerArray([
            'KRB5_PRINCIPAL' => $principal,
            'REQUEST_URI'    => '/',
            'REQUEST_METHOD' => 'PROPFIND',
        ]);
    }

    private function assertKerberosRejected(EsnMock $esnauth, string $principal, string $reason) {
        try {
            $esnauth->check($this->kerberosRequest($principal), new \Sabre\HTTP\Response());
            $this->fail("Principal '$principal' should be refused");
        } catch (\Sabre\DAV\Exception\Forbidden $e) {
            $this->assertEquals($reason, $e->getMessage());
        }
        $this->assertNull($esnauth->getCurrentPrincipal());
    }

    private function assertKerberosAuthenticates(EsnMock $esnauth, string $principal, string $userId) {
        [$rv, $msg] = $esnauth->check($this->kerberosRequest($principal), new \Sabre\HTTP\Response());

        $this->assertTrue($rv);
        $this->assertEquals('principals/users/' . $userId, $msg);
    }

    function testKerberosPrincipalAuthenticatesTheMatchingUser() {
        $this->enableKerberosWithoutLdap();

        $authNotificationResult = [];
        $esnauth = new EsnMock('http://localhost:8080/');
        $esnauth->getServer()->on("auth:success", function($tenant) use (&$authNotificationResult) {
            $authNotificationResult[] = $tenant->getPrincipal();
        });

        $esnDb = $esnauth->getDb();
        $userId = new \MongoDB\BSON\ObjectId(self::USER_ID);
        $domainId = new \MongoDB\BSON\ObjectId(self::DOMAIN_ID);
        $esnDb->users->insertOne([
            '_id' => $userId,
            'firstname' => 'Alice',
            'lastname' => 'Doe',
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ 'alice@example.com' ] ]
            ],
            'domains' => [
                [ 'domain_id' => $domainId ]
            ]
        ]);
        $esnDb->domains->insertOne([
            '_id' => $domainId,
            'name' => 'example.com'
        ]);

        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'KRB5_PRINCIPAL' => 'alice@CORP.EXAMPLE.COM',
            'REQUEST_URI'    => '/',
            'REQUEST_METHOD' => 'PROPFIND',
        ]);
        $response = new \Sabre\HTTP\Response();

        [$rv, $msg] = $esnauth->check($request, $response);

        $this->assertTrue($rv);
        $this->assertEquals('principals/users/' . self::USER_ID, $msg);
        $this->assertEquals(['principals/users/' . self::USER_ID], $authNotificationResult);
    }

    function testKerberosPrincipalIsIgnoredWhenKerberosIsDisabled() {
        putenv('KERBEROS_REALM_DOMAINS=CORP.EXAMPLE.COM:example.com');

        $esnauth = new EsnMock('http://localhost:8080/');

        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'KRB5_PRINCIPAL' => 'alice@CORP.EXAMPLE.COM',
            'REQUEST_URI'    => '/',
            'REQUEST_METHOD' => 'PROPFIND',
        ]);
        $response = new \Sabre\HTTP\Response();

        [$rv, $msg] = $esnauth->check($request, $response);

        $this->assertFalse($rv);
        $this->assertEquals("No 'Authorization: Basic' header found. Either the client didn't send one, or the server is misconfigured", $msg);
        $this->assertNull($esnauth->getCurrentPrincipal());
    }

    function testKerberosPrincipalWithoutUserIsForbidden() {
        $this->enableKerberosWithoutLdap();

        $esnauth = new EsnMock('http://localhost:8080/');

        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'KRB5_PRINCIPAL' => 'nobody@CORP.EXAMPLE.COM',
            'REQUEST_URI'    => '/',
            'REQUEST_METHOD' => 'PROPFIND',
        ]);
        $response = new \Sabre\HTTP\Response();

        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);
        $this->expectExceptionMessage('checkKerberos: no user found');

        $esnauth->check($request, $response);
    }

    function testKerberosPrincipalOfAnUnknownRealmIsForbidden() {
        $this->enableKerberosWithoutLdap();

        $esnauth = new EsnMock('http://localhost:8080/');

        // Kerberos realms are case sensitive
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'KRB5_PRINCIPAL' => 'alice@corp.example.com',
            'REQUEST_URI'    => '/',
            'REQUEST_METHOD' => 'PROPFIND',
        ]);
        $response = new \Sabre\HTTP\Response();

        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);
        $this->expectExceptionMessage('checkKerberos: realm not allowed');

        $esnauth->check($request, $response);
    }

    function testKerberosServicePrincipalIsForbidden() {
        $this->enableKerberosWithoutLdap();

        $esnauth = new EsnMock('http://localhost:8080/');

        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'KRB5_PRINCIPAL' => 'HTTP/dav.example.com@CORP.EXAMPLE.COM',
            'REQUEST_URI'    => '/',
            'REQUEST_METHOD' => 'PROPFIND',
        ]);
        $response = new \Sabre\HTTP\Response();

        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);
        $this->expectExceptionMessage('checkKerberos: unsupported principal');

        $esnauth->check($request, $response);
    }

    function testKerberosRejectionDoesNotLeakItsReasonInProduction() {
        $this->enableKerberosWithoutLdap();

        $esnauth = new EsnMock('http://localhost:8080/', false);

        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'KRB5_PRINCIPAL' => 'nobody@CORP.EXAMPLE.COM',
            'REQUEST_URI'    => '/',
            'REQUEST_METHOD' => 'PROPFIND',
        ]);
        $response = new \Sabre\HTTP\Response();

        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);
        $this->expectExceptionMessage('Authentification failure');

        $esnauth->check($request, $response);
    }

    function testKerberosPrincipalMatchingTheStoredPreferredEmailAuthenticates() {
        $this->enableKerberosWithoutLdap();
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->insertExampleUser($esnauth, self::USER_ID, [
            'preferredEmail' => 'alice@example.com',
            'accounts' => [ [ 'type' => 'email', 'emails' => [ 'a.doe@example.com', 'alice@example.com' ] ] ]
        ]);

        $this->assertKerberosAuthenticates($esnauth, 'alice@CORP.EXAMPLE.COM', self::USER_ID);
    }

    function testKerberosPrincipalNamedAfterAnotherUserSecondaryAddressIsForbidden() {
        $this->enableKerberosWithoutLdap();
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->insertExampleUser($esnauth, self::USER_ID, [
            'accounts' => [ [ 'type' => 'email', 'emails' => [ 'alice@example.com', 'sales@example.com' ] ] ]
        ]);

        $this->assertKerberosRejected($esnauth, 'sales@CORP.EXAMPLE.COM', 'checkKerberos: no user found');
    }

    function testKerberosPrincipalNamedAfterAnotherUserAliasIsForbidden() {
        $this->enableKerberosWithoutLdap();
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->insertExampleUser($esnauth, self::USER_ID, [
            'accounts' => [ [ 'type' => 'email', 'emails' => [ 'alice@example.com' ] ] ],
            'emails' => [ 'jdoe@example.com' ]
        ]);

        $this->assertKerberosRejected($esnauth, 'jdoe@CORP.EXAMPLE.COM', 'checkKerberos: no user found');
    }

    function testKerberosPrincipalMatchingTwoUsersIsForbidden() {
        $this->enableKerberosWithoutLdap();
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->insertExampleUser($esnauth, self::USER_ID, [
            'accounts' => [ [ 'type' => 'email', 'emails' => [ 'alice@example.com' ] ] ]
        ]);
        $this->insertExampleUser($esnauth, self::OTHER_USER_ID, [
            'preferredEmail' => 'alice@example.com',
            'accounts' => [ [ 'type' => 'email', 'emails' => [ 'alice.martin@example.com' ] ] ]
        ]);

        $this->assertKerberosRejected($esnauth, 'alice@CORP.EXAMPLE.COM', 'checkKerberos: no user found');
    }

    function testKerberosPrincipalIsResolvedThroughTheDirectory() {
        $this->enableKerberosWithMockedLdap('alice.martin@example.com');
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->insertExampleUser($esnauth, self::USER_ID, [
            'accounts' => [ [ 'type' => 'email', 'emails' => [ 'jdoe@example.com' ] ] ]
        ]);
        $this->insertExampleUser($esnauth, self::OTHER_USER_ID, [
            'accounts' => [ [ 'type' => 'email', 'emails' => [ 'alice.martin@example.com' ] ] ]
        ]);

        // The directory, not the principal name, tells which mailbox jdoe owns
        $this->assertKerberosAuthenticates($esnauth, 'jdoe@CORP.EXAMPLE.COM', self::OTHER_USER_ID);
        $this->assertEquals('(uid=jdoe)', $GLOBALS['__ldap_last_search_filter']);
    }

    function testKerberosDirectorySearchUsesTheConfiguredPrincipalFilter() {
        $this->enableKerberosWithMockedLdap('alice@example.com');
        putenv('KERBEROS_LDAP_PRINCIPAL_FILTER=(|(krbPrincipalName={principal})(sAMAccountName={user}))');
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->insertExampleUser($esnauth, self::USER_ID, [
            'accounts' => [ [ 'type' => 'email', 'emails' => [ 'alice@example.com' ] ] ]
        ]);

        $this->assertKerberosAuthenticates($esnauth, 'alice@CORP.EXAMPLE.COM', self::USER_ID);
        $this->assertEquals(
            '(|(krbPrincipalName=alice@CORP.EXAMPLE.COM)(sAMAccountName=alice))',
            $GLOBALS['__ldap_last_search_filter']
        );
    }

    function testKerberosDirectorySearchAppliesTheLdapFilter() {
        $this->enableKerberosWithMockedLdap('alice@example.com');
        putenv('LDAP_FILTER=(memberOf=cn=dav,ou=groups,dc=example,dc=com)');
        // The directory answers that no entry passes LDAP_FILTER
        $GLOBALS['__ldap_mock_entries_empty'] = true;
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->insertExampleUser($esnauth, self::USER_ID, [
            'accounts' => [ [ 'type' => 'email', 'emails' => [ 'alice@example.com' ] ] ]
        ]);

        $this->assertKerberosRejected($esnauth, 'alice@CORP.EXAMPLE.COM',
            'checkKerberos: Unable to find alice@CORP.EXAMPLE.COM which is valid for auth');
        $this->assertEquals(
            '(& (uid=alice) (memberOf=cn=dav,ou=groups,dc=example,dc=com))',
            $GLOBALS['__ldap_last_search_filter']
        );
    }

    function testKerberosPrincipalWithSeveralDirectoryEntriesIsForbidden() {
        $this->enableKerberosWithMockedLdap('alice@example.com');
        $GLOBALS['__ldap_mock_duplicate_entries'] = true;
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->insertExampleUser($esnauth, self::USER_ID, [
            'accounts' => [ [ 'type' => 'email', 'emails' => [ 'alice@example.com' ] ] ]
        ]);

        $this->assertKerberosRejected($esnauth, 'alice@CORP.EXAMPLE.COM', 'checkKerberos: no unique directory entry');
    }

    function testKerberosPrincipalWhoseDirectoryMailIsOutsideTheRealmDomainIsForbidden() {
        $this->enableKerberosWithMockedLdap('alice@other.org');
        $esnauth = new EsnMock('http://localhost:8080/');

        $this->assertKerberosRejected($esnauth, 'alice@CORP.EXAMPLE.COM', 'checkKerberos: mail outside the realm domain');
    }

    function testKerberosPrincipalWhoseDirectoryMailHasNoUserIsForbidden() {
        $this->enableKerberosWithMockedLdap('alice@example.com');
        $esnauth = new EsnMock('http://localhost:8080/');

        $this->assertKerberosRejected($esnauth, 'alice@CORP.EXAMPLE.COM', 'checkKerberos: no user found');
    }

    function testKerberosAnswers503WhenTheDirectoryIsUnavailable() {
        $this->enableKerberosWithMockedLdap('alice@example.com');
        $GLOBALS['__ldap_mock_search_fails'] = true;
        $esnauth = new EsnMock('http://localhost:8080/');

        $this->expectException(LdapUnavailableException::class);

        $esnauth->check($this->kerberosRequest('alice@CORP.EXAMPLE.COM'), new \Sabre\HTTP\Response());
    }

    function testKerberosPrincipalWithANameThatIsNotLowerCaseIsForbidden() {
        $this->enableKerberosWithMockedLdap('alice@example.com');
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->insertExampleUser($esnauth, self::USER_ID, [
            'accounts' => [ [ 'type' => 'email', 'emails' => [ 'alice@example.com' ] ] ]
        ]);

        // Kerberos names are case sensitive: "Alice" is not "alice", yet the directory would match both
        $this->assertKerberosRejected($esnauth, 'Alice@CORP.EXAMPLE.COM', 'checkKerberos: principal name not lower case');
        $this->assertNull($GLOBALS['__ldap_last_search_filter']);
    }

    function testKerberosRealmsMappedOntoTheSameDomainAreForbidden() {
        $this->enableKerberosWithoutLdap();
        putenv('KERBEROS_REALM_DOMAINS=CORP.EXAMPLE.COM:example.com,PARTNER.ORG:EXAMPLE.COM');
        $esnauth = new EsnMock('http://localhost:8080/');
        $this->insertExampleUser($esnauth, self::USER_ID, [
            'accounts' => [ [ 'type' => 'email', 'emails' => [ 'alice@example.com' ] ] ]
        ]);

        $this->assertKerberosRejected($esnauth, 'alice@CORP.EXAMPLE.COM', 'checkKerberos: several realms map to the same domain');
    }

    function testChallengeOffersNegotiateBeforeBasicWhenKerberosIsEnabled() {
        putenv('KERBEROS_ENABLED=true');

        $esnauth = new EsnMock('http://localhost:8080/');
        $request = new \Sabre\HTTP\Request('PROPFIND', '/');
        $response = new \Sabre\HTTP\Response();

        $esnauth->challenge($request, $response);

        $this->assertEquals(
            ['Negotiate', 'Basic realm="Realm", charset="UTF-8"'],
            $response->getHeaderAsArray('WWW-Authenticate')
        );
    }

    function testChallengeOffersOnlyBasicWhenKerberosIsDisabled() {
        $esnauth = new EsnMock('http://localhost:8080/');
        $request = new \Sabre\HTTP\Request('PROPFIND', '/');
        $response = new \Sabre\HTTP\Response();

        $esnauth->challenge($request, $response);

        $this->assertEquals(
            ['Basic realm="Realm", charset="UTF-8"'],
            $response->getHeaderAsArray('WWW-Authenticate')
        );
    }

    function testChallengeDoesNotOfferNegotiateAgainWhenNginxDidNotHandleIt() {
        putenv('KERBEROS_ENABLED=true');

        $esnauth = new EsnMock('http://localhost:8080/');
        $request = new \Sabre\HTTP\Request('PROPFIND', '/', ['Authorization' => 'Negotiate YIIDCAYGKwYBBQUC']);
        $response = new \Sabre\HTTP\Response();

        $esnauth->challenge($request, $response);

        $this->assertEquals(
            ['Basic realm="Realm", charset="UTF-8"'],
            $response->getHeaderAsArray('WWW-Authenticate')
        );
    }

}

class FailingPrincipalBackend {
    const MESSAGE = 'internal detail that must not leak';

    function getAuthTenantByEmail($email) {
        throw new \Error(self::MESSAGE);
    }
}

class EsnMock extends Esn {
    function __construct($apiroot, bool $debug = true) {
        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->{ESN_MONGO_ESNDB};
        $this->esndb->drop();
        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);

        $logger = \ESN\Log\EsnLoggerFactory::initLogger(null);
        $loggerPlugin = new \ESN\Log\ExceptionLoggerPlugin($logger);

        $this->server = new \Sabre\DAV\Server([]);
        $this->server->addPlugin($loggerPlugin);

        require_once ESN_TEST_VENDOR . '/sabre/http/tests/HTTP/ClientTest.php';
        parent::__construct($apiroot, "Realm", $this->principalBackend, $this->server, $debug);
        $this->httpClient = new \Sabre\HTTP\ClientMock();
    }

    function getClient() {
        return $this->httpClient;
    }

    function getServer() {
       return $this->server;
    }

    function getDb() {
        return $this->esndb;
    }

    function setPrincipalBackend($principalBackend) {
        $this->principalBackend = $principalBackend;
    }

    protected function getAdminCredential(): ?array {
        return ['admin', 'test-admin-password'];
    }
}
