<?php

namespace ESN\Security;

#[\AllowDynamicProperties]
class CrowdSecMockTest extends \PHPUnit\Framework\TestCase {

    private $mock;

    protected function setUp(): void {
        $this->mock = new CrowdSecMock();
    }

    function testInitiallyEmpty() {
        $this->assertFalse($this->mock->shouldBlock('192.168.1.1'));
        $this->assertFalse($this->mock->shouldBlock('10.0.0.1'));
    }

    function testAddBlockedIp() {
        $ip = '192.168.1.100';
        $this->mock->addBlockedIp($ip);

        $this->assertTrue($this->mock->shouldBlock($ip));
        $this->assertFalse($this->mock->shouldBlock('192.168.1.101'));
    }

    function testSetBlockedIps() {
        $ips = ['10.0.0.1', '10.0.0.2', '10.0.0.3'];
        $this->mock->setBlockedIps($ips);

        $this->assertTrue($this->mock->shouldBlock('10.0.0.1'));
        $this->assertTrue($this->mock->shouldBlock('10.0.0.2'));
        $this->assertTrue($this->mock->shouldBlock('10.0.0.3'));
        $this->assertFalse($this->mock->shouldBlock('10.0.0.4'));
    }

    function testClearBlockedIps() {
        $this->mock->setBlockedIps(['10.0.0.1', '10.0.0.2']);
        $this->assertTrue($this->mock->shouldBlock('10.0.0.1'));

        $this->mock->clearBlockedIps();

        $this->assertFalse($this->mock->shouldBlock('10.0.0.1'));
        $this->assertFalse($this->mock->shouldBlock('10.0.0.2'));
    }

    function testExactMatch() {
        $this->mock->addBlockedIp('192.168.1.100');

        // Should be exact match, not partial
        $this->assertTrue($this->mock->shouldBlock('192.168.1.100'));
        $this->assertFalse($this->mock->shouldBlock('192.168.1.10'));
        $this->assertFalse($this->mock->shouldBlock('192.168.1.1'));
    }
}
