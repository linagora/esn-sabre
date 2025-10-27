<?php

namespace ESN\Security;

/**
 * Mock CrowdSec client for testing
 * Allows configuring which IPs should be blocked
 */
#[\AllowDynamicProperties]
class CrowdSecMock implements ICrowdSecClient {

    private $blockedIps = [];

    /**
     * Configure which IPs should be blocked
     *
     * @param array $ips Array of IP addresses to block
     */
    public function setBlockedIps(array $ips): void {
        $this->blockedIps = $ips;
    }

    /**
     * Add an IP to the blocked list
     *
     * @param string $ip IP address to block
     */
    public function addBlockedIp(string $ip): void {
        $this->blockedIps[] = $ip;
    }

    /**
     * Clear the blocked IPs list
     */
    public function clearBlockedIps(): void {
        $this->blockedIps = [];
    }

    /**
     * Check if an IP address should be blocked
     *
     * @param string $ip IP address to check
     * @return bool true if IP should be blocked, false otherwise
     */
    public function shouldBlock(string $ip): bool {
        return in_array($ip, $this->blockedIps, true);
    }
}
