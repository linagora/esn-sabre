<?php

namespace ESN\Utils;

/** Matches a URL against a configured origin and optional path prefix. */
class TrustedUrlBase {
    private string $scheme;
    private string $hostPattern;
    private int $port;
    private string $path;

    function __construct(string $base) {
        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host']) ||
            !in_array(strtolower($parts['scheme']), ['http', 'https'], true) ||
            isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException('Invalid trusted URL base: ' . $base);
        }

        $this->scheme = strtolower($parts['scheme']);
        $this->hostPattern = str_replace('\\{fdqn\\}', '[a-z0-9](?:[a-z0-9-]*[a-z0-9])?', preg_quote($parts['host'], '~'));
        $this->port = $parts['port'] ?? ($this->scheme === 'https' ? 443 : 80);
        $this->path = rtrim($parts['path'] ?? '', '/');
    }

    /**
     * Whether a browser opens $url inside the trusted origin and path.
     *
     * The URL is read as browsers read it (WHATWG URL), or refused when
     * parse_url() could read it otherwise (GHSA-7f3v-466j-qf9g): the text is
     * stored as it is, and it is what clients open. Refused, so that no
     * normalisation is needed: control characters anywhere (browsers drop
     * tabs and line feeds, joining what they separate), in the authority or
     * the path a space, a backslash (a path separator for http(s)) or an
     * encoded slash / backslash, an authority other than host[:digits]
     * (userinfo, brackets, "443y"), and a "." / ".." path segment,
     * percent-encoded or not. The query and fragment never move the path:
     * they may hold anything else.
     */
    function accepts(string $url): bool {
        $url = trim($url);
        if (preg_match('~[\x00-\x1F\x7F]~', $url)) {
            return false;
        }

        if (!preg_match('~^([a-z][a-z0-9+.-]*)://([^/?#\\\\]*)([^?#]*)~iD', $url, $parts)) {
            return false;
        }
        [, $scheme, $authority, $path] = $parts;
        if (preg_match('~[ \\\\]|%2f|%5c~i', $authority . $path)) {
            return false;
        }

        if (!preg_match('~^([a-z0-9.-]+)(?::([0-9]+))?$~iD', $authority, $authorityParts)
            || strtolower($scheme) !== $this->scheme
            || !preg_match('~^' . $this->hostPattern . '$~iD', $authorityParts[1])) {
            return false;
        }

        $port = isset($authorityParts[2]) ? (int) ltrim($authorityParts[2], '0') : ($this->scheme === 'https' ? 443 : 80);
        if ($port !== $this->port) {
            return false;
        }

        foreach (explode('/', $path) as $segment) {
            if (in_array(str_ireplace('%2e', '.', $segment), ['.', '..'], true)) {
                return false;
            }
        }

        return $this->path === '' || $path === $this->path || str_starts_with($path, $this->path . '/');
    }
}
