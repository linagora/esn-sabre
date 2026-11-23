<?php

namespace ESN\DAV\Auth\Backend;

use \ESN\Utils\Env;

/**
 * The LDAP directory of LDAP_SERVER: connections, binds and searches shared by
 * Basic authentication, auto-provisioning and Kerberos principal resolution.
 *
 * Infrastructure failures raise LdapUnavailableException (503), never an
 * authentication failure.
 */
class LdapDirectory {

    function configured(): bool {
        return (bool) Env::getString('LDAP_SERVER');
    }

    function open() {
        try {
            $ldapCon = ldap_connect(Env::getString('LDAP_SERVER'));
        } catch (\ErrorException $e) {
            $ldapCon = false;
        }
        if (!$ldapCon) {
            error_log('Unable to connect to LDAP server');
            throw new LdapUnavailableException('LDAP server unavailable');
        }

        $timeout = Env::getInteger('LDAP_TIMEOUT', 5);
        ldap_set_option($ldapCon, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldapCon, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($ldapCon, LDAP_OPT_NETWORK_TIMEOUT, $timeout);
        ldap_set_option($ldapCon, LDAP_OPT_TIMEOUT, $timeout);

        return $ldapCon;
    }

    /**
     * Negative codes are client-side failures (server down, timeout, connect
     * error...), 51/52 are the server reporting itself busy or unavailable.
     * Anything else is the directory answering about the credentials.
     */
    function isUnavailableError(int $code): bool {
        return $code < 0 || $code === 51 || $code === 52;
    }

    /**
     * The service account is ours, not the user's: any failure is an
     * infrastructure problem.
     */
    function bindAdmin($ldapCon) {
        try {
            $ldapBind = ldap_bind($ldapCon, Env::getString('LDAP_ADMIN_DN'), Env::getString('LDAP_ADMIN_PASSWORD'));
        } catch (\ErrorException $e) {
            $ldapBind = false;
        }
        if (!$ldapBind) {
            $code = ldap_errno($ldapCon);
            $msg  = ldap_error($ldapCon);
            error_log("LDAP admin bind failed: [$code] '$msg'");
            throw new LdapUnavailableException('LDAP server unavailable');
        }
    }

    function search($ldapCon, $base, $filter): array {
        try {
            $searchResult = ldap_search($ldapCon, $base, $filter);
            $entries = $searchResult === false ? false : ldap_get_entries($ldapCon, $searchResult);
        } catch (\ErrorException $e) {
            $entries = false;
        }
        if ($entries === false) {
            $code = ldap_errno($ldapCon);
            $msg  = ldap_error($ldapCon);
            error_log("LDAP search '$filter' failed: [$code] '$msg'");
            throw new LdapUnavailableException('LDAP server unavailable');
        }

        return $entries;
    }

    /**
     * Searches LDAP_BASE with the service account, on a connection of its own.
     */
    function searchAsAdmin(string $filter): array {
        $ldapCon = $this->open();
        try {
            $this->bindAdmin($ldapCon);
            return $this->search($ldapCon, Env::getString('LDAP_BASE'), $filter);
        } finally {
            ldap_close($ldapCon);
        }
    }

    /**
     * The mail of the first entry, which must be a valid address.
     */
    function mailOf($entries, $username, $user): string {
        if ($entries['count'] == 0) {
            error_log("Unable to find $username which is valid for auth!");
            throw new  AuthException("Unable to find $username which is valid for auth");
        }
        if ($entries['count'] > 1) {
            error_log("More than one entry for $user");
        }
        if (empty($entries[0]['mail'][0]) || !is_string($entries[0]['mail'][0])) {
            error_log("$user has no mail attribute");
            throw new  AuthException("$user has no mail attribute");
        }
        $mail = $entries[0]['mail'][0];
        if(!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            error_log("validateUserPass: $user has incorrect mail attribute $mail");
            throw new  AuthException("$user has incorrect mail attribute");
        }

        return $mail;
    }
}
