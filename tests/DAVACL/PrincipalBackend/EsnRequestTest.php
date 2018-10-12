<?php

namespace ESN\DAVACL\PrincipalBackend;

require_once ESN_TEST_VENDOR . '/sabre/http/tests/HTTP/ClientTest.php';

class EsnRequestTest extends \PHPUnit_Framework_TestCase {

    function setUp() {
        $this->plugin = new EsnRequest(new MockPrincipalDb(), new MockAuthBackend(), 'http://server', new \Sabre\HTTP\ClientMock());
    }

    function testGetPrincipalByPathForResource() {
        $client = $this->plugin->getClient();

        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, &$requestCalled) {
            $requestCalled = true;
        });

        $principal = $this->plugin->GetPrincipalByPath('principals/resources/123123');

        $this->assertFalse($requestCalled);
    }

    function testGetPrincipalByPathForOtherTypes() {
        $client = $this->plugin->getClient();

        $requestCalled = false;
        $self = $this;

        $client->on('doRequest', function($request, &$response) use ($self, &$requestCalled) {
            $requestCalled = true;
        });

        $principal = $this->plugin->GetPrincipalByPath('principals/users/xxxxx');

        $this->assertFalse($requestCalled);
    }
}

class MockAuthBackend {
    function getAuthCookies() {
        return "coookies!!!";
    }
}

class MockPrincipalDb {
    public $users = null;
    public $communities = null;
    public $projects = null;
    public $resources = null;
    public $domains = null;
}
