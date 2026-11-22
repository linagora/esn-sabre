<?php

namespace ESN\DAV;

/**
 * The sync tokens of a collection are the non-negative integers it issued,
 * from 0 up to its current token.
 */
final class SyncToken {

    /**
     * Whether $syncToken is a token a collection now at $currentToken may have
     * issued. Any other is unknown (RFC 6578 section 3.2, {DAV:}valid-sync-token):
     * the change lookup would read "abc" or "-1" as 0 and list every change,
     * and a token above the current one as "nothing changed".
     *
     * @param mixed $syncToken The token the client sent, without its URI prefix
     * @param int|string $currentToken
     * @return bool
     */
    static function isIssued($syncToken, $currentToken) {
        if (!is_int($syncToken) && !is_string($syncToken)) {
            return false;
        }

        $syncToken = (string) $syncToken;
        if (!ctype_digit($syncToken)) {
            return false;
        }

        // Compared as decimal strings: a token too long for an integer is not
        // cast down to PHP_INT_MAX.
        $syncToken = ltrim($syncToken, '0');
        $currentToken = ltrim((string) $currentToken, '0');

        return strlen($syncToken) < strlen($currentToken)
            || (strlen($syncToken) === strlen($currentToken) && strcmp($syncToken, $currentToken) <= 0);
    }
}
