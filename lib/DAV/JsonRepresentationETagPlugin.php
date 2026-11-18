<?php

namespace ESN\DAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\HTTP;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;

/**
 * Gives the jCard / jCal representation of a card / calendar object its own ETag.
 *
 * Sabre converts the stored vCard / iCalendar to JSON on GET according to the
 * Accept header, but sends the ETag of the stored object (RFC 9110 8.8.3
 * requires one per representation): a conditional GET asking for JSON with
 * the ETag of the text representation would get a 304, and the client would
 * reuse the text body as JSON.
 *
 * The JSON representation is sent with the stored ETag suffixed by
 * JSON_SUFFIX. On a GET asking for JSON, If-None-Match is translated back to
 * stored ETags before Sabre checks it: entries that are not JSON ETags can not
 * match and are dropped. The text representation keeps the stored ETag, the
 * one returned by PUT and PROPFIND, so that DAV clients are not affected.
 */
class JsonRepresentationETagPlugin extends ServerPlugin {
    const JSON_SUFFIX = '-json';
    const JSON_CONTENT_TYPES = ['application/vcard+json', 'application/calendar+json'];
    const NEGOTIABLE_CONTENT_TYPES = ['text/vcard', 'text/calendar', 'application/vcard+json', 'application/calendar+json'];
    const ANY_ETAG = '*';

    function initialize(Server $server) {
        $server->on('beforeMethod:GET', [$this, 'translateIfNoneMatch']);
        $server->on('beforeMethod:HEAD', [$this, 'translateIfNoneMatch']);
        // After the CalDAV and CardDAV plugins (100) converted the body
        $server->on('afterMethod:GET', [$this, 'tagJsonRepresentation'], 150);
    }

    function getPluginName() {
        return 'json-representation-etag';
    }

    function translateIfNoneMatch(RequestInterface $request, ResponseInterface $response) {
        $ifNoneMatch = $request->getHeader('If-None-Match');

        if (!$ifNoneMatch || $ifNoneMatch === self::ANY_ETAG || $this->isHeadSubRequest($request) || !$this->acceptsJson($request)) {
            return true;
        }

        $storedETags = $this->toStoredETags($ifNoneMatch);
        if (empty($storedETags)) {
            $request->removeHeader('If-None-Match');
        } else {
            $request->setHeader('If-None-Match', implode(', ', $storedETags));
        }

        return true;
    }

    function tagJsonRepresentation(RequestInterface $request, ResponseInterface $response) {
        $etag = $response->getHeader('ETag');

        if ($etag && $this->isJsonContentType($response->getHeader('Content-Type'))) {
            $response->setHeader('ETag', self::toJsonETag($etag));
        }

        return true;
    }

    static function toJsonETag(string $etag): string {
        return str_ends_with($etag, '"')
            ? substr($etag, 0, -1) . self::JSON_SUFFIX . '"'
            : $etag . self::JSON_SUFFIX;
    }

    private function toStoredETags(string $ifNoneMatch): array {
        $jsonETags = array_filter(
            array_map('trim', explode(',', $ifNoneMatch)),
            fn($etag) => $this->isJsonETag($etag)
        );

        return array_values(array_map(fn($etag) => $this->toStoredETag($etag), $jsonETags));
    }

    private function isJsonETag(string $etag): bool {
        return str_ends_with($etag, self::JSON_SUFFIX . '"') || str_ends_with($etag, self::JSON_SUFFIX);
    }

    private function toStoredETag(string $jsonETag): string {
        return str_ends_with($jsonETag, '"')
            ? substr($jsonETag, 0, -strlen(self::JSON_SUFFIX) - 1) . '"'
            : substr($jsonETag, 0, -strlen(self::JSON_SUFFIX));
    }

    private function acceptsJson(RequestInterface $request): bool {
        $negotiated = HTTP\negotiateContentType($request->getHeader('Accept'), self::NEGOTIABLE_CONTENT_TYPES);

        return in_array($negotiated, self::JSON_CONTENT_TYPES, true);
    }

    private function isJsonContentType(?string $contentType): bool {
        if (!$contentType) {
            return false;
        }

        $mimeType = strtolower(trim(explode(';', $contentType)[0]));

        return in_array($mimeType, self::JSON_CONTENT_TYPES, true);
    }

    // HEAD is answered by a GET sub-request carrying the already translated header
    private function isHeadSubRequest(RequestInterface $request): bool {
        return $request->getMethod() === 'GET' && $request->getHeader('X-Sabre-Original-Method') === 'HEAD';
    }
}
