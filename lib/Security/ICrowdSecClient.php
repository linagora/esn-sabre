<?php

namespace ESN\Security;

/**
 * Interface for CrowdSec client implementations
 */
interface ICrowdSecClient {
    /**
     * Check if an IP address should be blocked
     *
     * @param string $ip IP address to check
     * @return bool true if IP should be blocked, false otherwise
     */
    public function shouldBlock(string $ip): bool;
}
