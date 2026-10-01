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

    function accepts(string $url): bool {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host']) ||
            isset($parts['user']) || isset($parts['pass']) ||
            strtolower($parts['scheme']) !== $this->scheme ||
            !preg_match('~^' . $this->hostPattern . '$~iD', $parts['host']) ||
            ($parts['port'] ?? ($this->scheme === 'https' ? 443 : 80)) !== $this->port) {
            return false;
        }

        $path = $parts['path'] ?? '';
        return $this->path === '' || $path === $this->path || str_starts_with($path, $this->path . '/');
    }
}
