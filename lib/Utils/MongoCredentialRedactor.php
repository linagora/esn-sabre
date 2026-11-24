<?php

namespace ESN\Utils;

/**
 * Removes MongoDB credentials from an error message before it is logged.
 *
 * Driver messages can quote the whole connection URI ("Failed to parse MongoDB URI: '<uri>'"), password included,
 * while the rest of the message is what an operator needs to diagnose the failure.
 */
final class MongoCredentialRedactor {
    const REDACTED = '***';

    /**
     * Shorter secrets, and ends of the user information, are only removed from URIs: replaced wherever they appear,
     * a password '1' would mangle every '1' of "timeout after 10000ms".
     */
    const MIN_SECRET_LENGTH = 3;

    /**
     * @param string $message the message to log
     * @param string|null $connectionString the configured connection string, whose user information is also removed
     *        wherever it appears: a password with unescaped reserved characters defeats the URI pattern
     */
    public static function redact(string $message, ?string $connectionString = null): string {
        $userInfo = self::userInfoOf($connectionString);
        if ($userInfo !== null) {
            foreach (self::secretsOf($userInfo) as $secret) {
                $message = str_replace($secret, self::REDACTED, $message);
            }
            $message = self::redactUserInfoTails($message, $userInfo);
        }

        // Greedy up to the last '@' of the URI: an unescaped '@' in the password must not leave its end visible.
        return preg_replace('#(mongodb(?:\+srv)?://)[^\s\'"]*@#i', '$1' . self::REDACTED . '@', $message) ?? $message;
    }

    /** Everything between the scheme and the last '@', or null when the connection string carries no credentials. */
    private static function userInfoOf(?string $connectionString): ?string {
        if ($connectionString === null || !preg_match('#^mongodb(?:\+srv)?://(.+)@#is', $connectionString, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /** The user information and its password, raw and decoded, long enough to be replaced anywhere, longest first. */
    private static function secretsOf(string $userInfo): array {
        $secrets = [$userInfo];
        $separator = strpos($userInfo, ':');
        if ($separator !== false) {
            $password = substr($userInfo, $separator + 1);
            $secrets[] = $password;
            $secrets[] = rawurldecode($password);
        }
        $secrets = array_values(array_unique(array_filter($secrets, fn($secret) => strlen($secret) >= self::MIN_SECRET_LENGTH)));
        usort($secrets, fn($a, $b) => strlen($b) - strlen($a));

        return $secrets;
    }

    /**
     * libmongoc splits a URI at its first '@', so with an unescaped '@' in the password it quotes the rest as a host
     * ("Invalid host specifier 'ss:w0rd@mongo:27017'"): remove any end of the user information found before an '@'.
     */
    private static function redactUserInfoTails(string $message, string $userInfo): string {
        $parts = explode('@', $message);
        for ($i = 0; $i < count($parts) - 1; $i++) {
            for ($length = min(strlen($parts[$i]), strlen($userInfo)); $length >= self::MIN_SECRET_LENGTH; $length--) {
                if (substr($parts[$i], -$length) === substr($userInfo, -$length)) {
                    $parts[$i] = substr($parts[$i], 0, -$length) . self::REDACTED;
                    break;
                }
            }
        }

        return implode('@', $parts);
    }
}
