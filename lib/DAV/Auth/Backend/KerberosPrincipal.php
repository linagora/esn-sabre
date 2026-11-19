<?php

namespace ESN\DAV\Auth\Backend;

/**
 * A user principal nginx authenticated ("alice@CORP.EXAMPLE.COM"), with the
 * mail domain KERBEROS_REALM_DOMAINS maps its realm to.
 *
 * The name keeps the case the KDC gave it: Kerberos names are case sensitive.
 */
class KerberosPrincipal {
    public function __construct(
        public readonly string $principal,
        public readonly string $name,
        public readonly string $realm,
        public readonly string $domain
    ) {}

    /**
     * The address the principal is assumed to own when no directory can tell:
     * its name in the mapped domain.
     */
    public function assumedEmail(): string {
        return $this->name . '@' . $this->domain;
    }

    public function isInDomain(string $email): bool {
        return strtolower(substr(strrchr($email, '@'), 1)) === $this->domain;
    }

    public function __toString(): string {
        return $this->principal;
    }
}
