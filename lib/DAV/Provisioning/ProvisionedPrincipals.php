<?php

namespace ESN\DAV\Provisioning;

/**
 * Remembers the principals whose default collections are known to exist.
 */
interface ProvisionedPrincipals {

    function contains(string $principalUri): bool;

    function add(string $principalUri): void;
}
