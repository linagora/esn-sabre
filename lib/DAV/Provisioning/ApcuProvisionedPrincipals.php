<?php

namespace ESN\DAV\Provisioning;

/**
 * Keeps the provisioned principals in APCu, shared by the PHP-FPM workers of a node.
 *
 * Entries expire so that a default collection deleted behind the back of a node
 * gets recreated eventually. Without APCu nothing is remembered and the defaults
 * are checked on every request, as before.
 */
class ApcuProvisionedPrincipals implements ProvisionedPrincipals {
    const KEY_PREFIX = 'esn-sabre:default-collections:';
    const DEFAULT_TTL_IN_SECONDS = 3600;

    private int $ttlInSeconds;

    function __construct(int $ttlInSeconds = self::DEFAULT_TTL_IN_SECONDS) {
        $this->ttlInSeconds = $ttlInSeconds;
    }

    function contains(string $principalUri): bool {
        return $this->isApcuAvailable() && apcu_exists($this->key($principalUri));
    }

    function add(string $principalUri): void {
        if ($this->isApcuAvailable()) {
            apcu_store($this->key($principalUri), true, $this->ttlInSeconds);
        }
    }

    private function key(string $principalUri): string {
        return self::KEY_PREFIX . $principalUri;
    }

    private function isApcuAvailable(): bool {
        return function_exists('apcu_enabled') && apcu_enabled();
    }
}
