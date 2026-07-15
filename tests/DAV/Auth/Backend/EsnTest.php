<?php

namespace ESN\DAV\Auth\Backend;

$GLOBALS['__ldap_bind_behavior'] = 'default';
$GLOBALS['__ldap_mock_enabled'] = false;
$GLOBALS['__ldap_mock_email'] = 'johndoe@linagora.com';

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
    switch ($GLOBALS['__ldap_bind_behavior']) {
        case 'return_false':
            return false;
        case 'throw':
            throw new \ErrorException("Simulated LDAP exception");
        default:
            if ($GLOBALS['__ldap_mock_enabled']) {
                return true;
            }
            return \ldap_bind($conn, $dn, $pwd);
    }
}

function ldap_search($conn, $base, $filter) {
    if ($GLOBALS['__ldap_mock_enabled']) {
        return 'mock_ldap_search_result';
    }
    return \ldap_search($conn, $base, $filter);
}

function ldap_get_entries($conn, $result) {
    if ($GLOBALS['__ldap_mock_enabled']) {
        return [
            'count' => 1,
            0 => [
                'mail' => [$GLOBALS['__ldap_mock_email'], 'count' => 1],
                'count' => 1
            ]
        ];
    }
    return \ldap_get_entries($conn, $result);
}

function ldap_close($conn) {
    if ($GLOBALS['__ldap_mock_enabled']) {
        return true;
    }
    return \ldap_close($conn);
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

}

class EsnMock extends Esn {
    function __construct($apiroot) {
        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->{ESN_MONGO_ESNDB};
        $this->esndb->drop();
        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);

        $logger = \ESN\Log\EsnLoggerFactory::initLogger(null);
        $loggerPlugin = new \ESN\Log\ExceptionLoggerPlugin($logger);

        $this->server = new \Sabre\DAV\Server([]);
        $this->server->addPlugin($loggerPlugin);

        require_once ESN_TEST_VENDOR . '/sabre/http/tests/HTTP/ClientTest.php';
        parent::__construct($apiroot, "Realm", $this->principalBackend, $this->server, true);
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

    protected function getAdminCredential(): ?array {
        return ['admin', 'test-admin-password'];
    }
}
