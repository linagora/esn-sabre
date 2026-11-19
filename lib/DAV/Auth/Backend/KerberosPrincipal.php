<?php

namespace ESN\DAV\Auth\Backend;

/**
 * The name of a user principal nginx authenticated ("alice" for
 * "alice@CORP.EXAMPLE.COM"), with the mail domain KERBEROS_REALM_DOMAINS maps
 * its realm to.
 */
class KerberosPrincipal {
    public function __construct(
        public readonly string $name,
        public readonly string $domain
    ) {}

    /**
     * The address the principal owns, as James maps it: its name in the mapped
     * domain.
     */
    public function email(): string {
        return $this->name . '@' . $this->domain;
    }
}
