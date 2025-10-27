<?php

namespace ESN\Security;

use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

#[\AllowDynamicProperties]
class CrowdSecPluginTest extends \PHPUnit\Framework\TestCase {

    private $plugin;
    private $mockClient;
    private $server;

    protected function setUp(): void {
        $this->mockClient = new CrowdSecMock();
        $this->plugin = new CrowdSecPlugin($this->mockClient);

        $this->server = new \Sabre\DAV\Server([]);
        $this->plugin->initialize($this->server);
    }

    function testAllowsNonBlockedIp() {
        $request = new \Sabre\HTTP\Request('GET', '/calendars');
        $response = new \Sabre\HTTP\Response();

        // Set up server environment
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';

        $result = $this->plugin->beforeMethod($request, $response);

        // Should return null to allow request to continue
        $this->assertNull($result);
        $this->assertNotEquals(403, $response->getStatus());
    }

    function testBlocksBlockedIp() {
        $blockedIp = '10.0.0.1';
        $this->mockClient->addBlockedIp($blockedIp);

        $request = new \Sabre\HTTP\Request('GET', '/calendars');
        $response = new \Sabre\HTTP\Response();

        // Set up server environment
        $_SERVER['REMOTE_ADDR'] = $blockedIp;

        $result = $this->plugin->beforeMethod($request, $response);

        // Should return false to stop further processing
        $this->assertFalse($result);
        $this->assertEquals(403, $response->getStatus());

        $body = json_decode($response->getBodyAsString(), true);
        $this->assertEquals('Forbidden', $body['error']);
        $this->assertEquals('Access denied', $body['message']);
    }

    function testCustomHttpCode() {
        $blockedIp = '10.0.0.2';
        $this->mockClient->addBlockedIp($blockedIp);

        // Create plugin with custom HTTP code
        $pluginWithCustomCode = new CrowdSecPlugin($this->mockClient, null, 429);
        $pluginWithCustomCode->initialize($this->server);

        $request = new \Sabre\HTTP\Request('GET', '/calendars');
        $response = new \Sabre\HTTP\Response();

        $_SERVER['REMOTE_ADDR'] = $blockedIp;

        $result = $pluginWithCustomCode->beforeMethod($request, $response);

        $this->assertFalse($result);
        $this->assertEquals(429, $response->getStatus());

        $body = json_decode($response->getBodyAsString(), true);
        $this->assertEquals('Too Many Requests', $body['error']);
    }

    function testXForwardedForHeader() {
        $blockedIp = '203.0.113.1';
        $this->mockClient->addBlockedIp($blockedIp);

        $request = new \Sabre\HTTP\Request('GET', '/calendars', [
            'X-Forwarded-For' => $blockedIp
        ]);
        $response = new \Sabre\HTTP\Response();

        $_SERVER['REMOTE_ADDR'] = '192.168.1.1'; // Different from blocked IP

        $result = $this->plugin->beforeMethod($request, $response);

        // Should block based on X-Forwarded-For header
        $this->assertFalse($result);
        $this->assertEquals(403, $response->getStatus());
    }

    function testXForwardedForMultipleIps() {
        $blockedIp = '203.0.113.1';
        $this->mockClient->addBlockedIp($blockedIp);

        $request = new \Sabre\HTTP\Request('GET', '/calendars', [
            'X-Forwarded-For' => $blockedIp . ', 192.168.1.1, 10.0.0.1'
        ]);
        $response = new \Sabre\HTTP\Response();

        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';

        $result = $this->plugin->beforeMethod($request, $response);

        // Should block based on first IP in X-Forwarded-For
        $this->assertFalse($result);
        $this->assertEquals(403, $response->getStatus());
    }

    function testXRealIpHeader() {
        $blockedIp = '198.51.100.1';
        $this->mockClient->addBlockedIp($blockedIp);

        $request = new \Sabre\HTTP\Request('GET', '/calendars', [
            'X-Real-IP' => $blockedIp
        ]);
        $response = new \Sabre\HTTP\Response();

        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';

        $result = $this->plugin->beforeMethod($request, $response);

        // Should block based on X-Real-IP header
        $this->assertFalse($result);
        $this->assertEquals(403, $response->getStatus());
    }

    function testGetPluginName() {
        $this->assertEquals('crowdsec-security', $this->plugin->getPluginName());
    }

    function testGetPluginInfo() {
        $info = $this->plugin->getPluginInfo();

        $this->assertArrayHasKey('name', $info);
        $this->assertArrayHasKey('description', $info);
        $this->assertArrayHasKey('link', $info);

        $this->assertEquals('crowdsec-security', $info['name']);
        $this->assertContains('CrowdSec', $info['description']);
    }

    protected function tearDown(): void {
        // Clean up server variables
        unset($_SERVER['REMOTE_ADDR']);
    }
}
