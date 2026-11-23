<?php

namespace ESN\DAV\Auth\Backend;

use \ESN\Utils\AuthTenant;
use \ESN\Utils\Env;

/**
 * Maps the principal nginx authenticated ("alice@CORP.EXAMPLE.COM") to a user.
 *
 * With an LDAP directory, the principal is the mail of its single entry; without
 * one, the primary address made of its name. Every refusal is a
 * KerberosRejectedException (403): a 401 would challenge the client again with
 * the very same ticket.
 */
class KerberosPrincipalResolver {

    private $principalBackend;
    private LdapDirectory $directory;

    function __construct($principalBackend, LdapDirectory $directory) {
        $this->principalBackend = $principalBackend;
        $this->directory = $directory;
    }

    function resolve(string $principal): AuthTenant {
        $kerberosPrincipal = $this->parse($principal);
        $tenant = $this->directory->configured()
            ? $this->tenantFromDirectory($kerberosPrincipal)
            : $this->tenantFromPrimaryEmail($kerberosPrincipal);
        if (!$tenant) {
            error_log("checkKerberos: no user found for principal '$principal'");
            throw new KerberosRejectedException('checkKerberos: no user found');
        }
        return $tenant;
    }

    /**
     * "alice@CORP.COM" -> name "alice", in the domain KERBEROS_REALM_DOMAINS
     * ("CORP.COM:mycompany.com,OTHER.COM:other.com") maps "CORP.COM" to.
     * Realms are case sensitive.
     */
    private function parse(string $principal): KerberosPrincipal {
        // A user principal: "user@REALM", not a service one ("HTTP/host@REALM").
        if (!preg_match('/^([^@\/]+)@([^@]+)$/', $principal, $parts)) {
            error_log("checkKerberos: unsupported principal '$principal'");
            throw new KerberosRejectedException('checkKerberos: unsupported principal');
        }
        [, $name, $realm] = $parts;
        $this->assertCanonicalName($principal, $name);

        $domains = $this->realmDomains();
        if (!isset($domains[$realm])) {
            error_log("checkKerberos: realm '$realm' is not allowed for principal '$principal'");
            throw new KerberosRejectedException('checkKerberos: realm not allowed');
        }
        return new KerberosPrincipal($principal, $name, $realm, $domains[$realm]);
    }

    /**
     * Kerberos names are case sensitive, user addresses and directory matches
     * usually are not: like James's CanonicalKerberosPrincipal, only lower-case
     * names are accepted, so that "alice" and "Alice", two distinct principals,
     * cannot sign in as the same user.
     */
    private function assertCanonicalName(string $principal, string $name): void {
        if ($name !== strtolower($name)) {
            error_log("checkKerberos: principal '$principal' has a name that is not lower case");
            throw new KerberosRejectedException('checkKerberos: principal name not lower case');
        }
    }

    /**
     * KERBEROS_REALM_DOMAINS as realm => domain. Like James's RealmMapping, two
     * realms mapped onto the same domain are refused: their principals of the
     * same name would sign in as the same user.
     */
    private function realmDomains(): array {
        $domains = [];
        foreach (explode(',', (string) Env::getString('KERBEROS_REALM_DOMAINS')) as $entry) {
            // "REALM:domain", malformed entries are skipped
            if (preg_match('/^([^:]+):(.+)$/', trim($entry), $pair)) {
                $domains[$pair[1]] = strtolower($pair[2]);
            }
        }
        if (count(array_unique($domains)) !== count($domains)) {
            error_log('checkKerberos: KERBEROS_REALM_DOMAINS maps several realms onto the same domain');
            throw new KerberosRejectedException('checkKerberos: several realms map to the same domain');
        }
        return $domains;
    }

    /**
     * Without a directory, the principal can only be assumed to own the address
     * made of its name: it must be the primary address of exactly one user,
     * never an alias or a secondary address that someone else holds.
     */
    private function tenantFromPrimaryEmail(KerberosPrincipal $kerberosPrincipal): ?AuthTenant {
        $email = $kerberosPrincipal->assumedEmail();
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log("checkKerberos: principal '$kerberosPrincipal' does not map to a valid email");
            throw new KerberosRejectedException('checkKerberos: invalid email');
        }
        return $this->principalBackend->getAuthTenantByPrimaryEmail($email);
    }

    /**
     * With a directory, it tells which mailbox the principal owns, as for Basic
     * authentication: the mail of its single entry, which LDAP_FILTER must
     * accept.
     */
    private function tenantFromDirectory(KerberosPrincipal $kerberosPrincipal): ?AuthTenant {
        $mail = $this->mailFromDirectory($kerberosPrincipal);
        if (!$kerberosPrincipal->isInDomain($mail)) {
            error_log("checkKerberos: mail '$mail' of principal '$kerberosPrincipal' is not in domain '$kerberosPrincipal->domain'");
            throw new KerberosRejectedException('checkKerberos: mail outside the realm domain');
        }
        return $this->principalBackend->getAuthTenantByEmail($mail);
    }

    private function mailFromDirectory(KerberosPrincipal $kerberosPrincipal): string {
        $entries = $this->directory->searchAsAdmin($this->ldapFilter($kerberosPrincipal));
        if ($entries['count'] > 1) {
            error_log("checkKerberos: several directory entries for principal '$kerberosPrincipal'");
            throw new KerberosRejectedException('checkKerberos: no unique directory entry');
        }
        try {
            return $this->directory->mailOf($entries, (string) $kerberosPrincipal, (string) $kerberosPrincipal);
        } catch (AuthException $e) {
            // A 401 would challenge the client again with the very same ticket
            throw new KerberosRejectedException('checkKerberos: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * KERBEROS_LDAP_PRINCIPAL_FILTER with its {user} (principal name) and
     * {principal} (full principal) placeholders escaped and filled in, ANDed
     * with LDAP_FILTER.
     */
    private function ldapFilter(KerberosPrincipal $kerberosPrincipal): string {
        $filter = strtr(Env::getString('KERBEROS_LDAP_PRINCIPAL_FILTER', '(uid={user})'), [
            '{user}' => ldap_escape($kerberosPrincipal->name, '', LDAP_ESCAPE_FILTER),
            '{principal}' => ldap_escape($kerberosPrincipal->principal, '', LDAP_ESCAPE_FILTER),
        ]);
        $ldapFilter = Env::getString('LDAP_FILTER');
        return $ldapFilter === null ? $filter : "(& $filter $ldapFilter)";
    }
}
