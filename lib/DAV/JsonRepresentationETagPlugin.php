<?php

namespace ESN\DAV;

use \Sabre\DAV\Exception\NotFound;
use \Sabre\DAV\IFile;
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
 * JSON_SUFFIX. When a GET negotiates JSON, the same way the CardDAV and CalDAV
 * plugins do, this plugin evaluates If-None-Match against the JSON ETag:
 * - on a match it answers 304 with the JSON ETag itself, as Sabre would answer
 *   with the stored one;
 * - otherwise it removes If-None-Match and If-Modified-Since, which a
 *   recipient must ignore when If-None-Match is present (RFC 9110 13.1.3), so
 *   that Sabre serves the representation.
 * The text representation keeps the stored ETag and Sabre's precondition
 * handling, so that DAV clients are not affected.
 */
class JsonRepresentationETagPlugin extends ServerPlugin {
    const JSON_SUFFIX = '-json';
    const ANY_ETAG = '*';
    const JSON_CONTENT_TYPES = ['application/vcard+json', 'application/calendar+json'];
    // Offered in the order of Sabre\CardDAV\Plugin::negotiateVCard
    const VCARD_CONTENT_TYPES = ['text/x-vcard', 'text/vcard', 'text/vcard; version=4.0', 'text/vcard; version=3.0', 'application/vcard+json'];
    // Offered in the order of Sabre\CalDAV\Plugin::httpAfterGet
    const ICALENDAR_CONTENT_TYPES = ['text/calendar', 'application/calendar+json'];

    private $server;

    function initialize(Server $server) {
        $this->server = $server;
        // After ACL (20): the stored ETag must not be disclosed before the read privilege is checked
        $server->on('beforeMethod:GET', [$this, 'evaluateIfNoneMatch']);
        $server->on('beforeMethod:HEAD', [$this, 'evaluateIfNoneMatch']);
        // After the CalDAV and CardDAV plugins (100) converted the body
        $server->on('afterMethod:GET', [$this, 'tagJsonRepresentation'], 150);
    }

    function getPluginName() {
        return 'json-representation-etag';
    }

    function evaluateIfNoneMatch(RequestInterface $request, ResponseInterface $response) {
        $ifNoneMatch = $request->getHeader('If-None-Match');
        $node = $ifNoneMatch ? $this->findFile($request->getPath()) : null;
        $storedETag = $node ? $node->getETag() : null;

        if (!$storedETag || !$this->negotiatesJson($request, $node->getContentType())) {
            return true;
        }

        $jsonETag = self::toJsonETag($storedETag);
        if (!$this->matches($ifNoneMatch, $jsonETag)) {
            $request->removeHeader('If-None-Match');
            $request->removeHeader('If-Modified-Since');

            return true;
        }

        if ($request->getMethod() !== 'GET') {
            // Let Sabre answer as it does for a matching text ETag
            $request->setHeader('If-None-Match', $storedETag);

            return true;
        }

        return $this->notModified($response, $jsonETag);
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

    private function findFile(string $path): ?IFile {
        try {
            $node = $this->server->tree->getNodeForPath($path);
        } catch (NotFound $e) {
            return null;
        }

        return $node instanceof IFile ? $node : null;
    }

    private function negotiatesJson(RequestInterface $request, ?string $storedContentType): bool {
        $offers = $this->negotiableContentTypes($storedContentType ?? '');
        $negotiated = $offers ? HTTP\negotiateContentType($request->getHeader('Accept'), $offers) : null;

        return in_array($negotiated, self::JSON_CONTENT_TYPES, true);
    }

    // Mirrors the Content-Type checks of the CardDAV and CalDAV plugins before they convert the body
    private function negotiableContentTypes(string $storedContentType): array {
        if (str_contains($storedContentType, 'text/vcard')) {
            return self::VCARD_CONTENT_TYPES;
        }

        return str_contains($storedContentType, 'text/calendar') ? self::ICALENDAR_CONTENT_TYPES : [];
    }

    private function matches(string $ifNoneMatch, string $etag): bool {
        return $ifNoneMatch === self::ANY_ETAG
            || in_array($etag, array_map('trim', explode(',', $ifNoneMatch)), true);
    }

    private function isJsonContentType(?string $contentType): bool {
        if (!$contentType) {
            return false;
        }

        $mimeType = strtolower(trim(explode(';', $contentType)[0]));

        return in_array($mimeType, self::JSON_CONTENT_TYPES, true);
    }

    private function notModified(ResponseInterface $response, string $jsonETag): bool {
        $response->setStatus(304);
        $response->setHeader('ETag', $jsonETag);
        $this->server->sapi->sendResponse($response);

        return false;
    }
}
