<?php

namespace ESN\DAV;

use \Sabre\DAV\Exception\BadRequest;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;
use \Sabre\Xml\ParseException;

/**
 * Guards the XML request body of the DAV methods whose body Sabre deserialises:
 * REPORT, PROPFIND, PROPPATCH, ACL and the XML POSTs of CalDAV sharing.
 *
 * Two distinct problems are handled here, both before Sabre's handler runs:
 *
 *  1. Sabre's REPORT, ACL and CalDAV sharing POST handlers let XML parse
 *     errors of the request body (malformed document, unexpected root element,
 *     empty body) escape as 500, while PROPFIND/PROPPATCH already map them to
 *     400. We run those handlers ourselves and report a parse error as 400.
 *
 *  2. A malformed body can make Sabre's deserialiser spin forever instead of
 *     raising a parse error. `Sabre\Xml\Deserializer\keyValue()` (used for
 *     `{DAV:}prop`) advances with `XMLReader::next()`; on a mismatched end tag
 *     following an empty element, `next()` fails silently and leaves the cursor
 *     on the same element, so the loop re-parses it until `max_execution_time`.
 *     Any authenticated user could tie up one PHP worker per request (a REPORT,
 *     PROPFIND or PROPPATCH on their own collection is enough) and, with enough
 *     parallel requests, exhaust the FPM pool — a denial of service.
 *
 *     The loop lives in the vendored sabre/xml, which `XMLReader::next()`'s
 *     silent failure defeats. We instead pre-check that the body is a
 *     well-formed XML document with a plain `XMLReader::read()` sweep (which
 *     does surface the libxml error) and answer 400 before handing it to Sabre.
 */
#[\AllowDynamicProperties]
class ReportBodyValidationPlugin extends ServerPlugin {
    // Right before Sabre's core, ACL and CalDAV sharing handlers (default priority 100).
    const PRIORITY = 99;

    protected $server;

    function getPluginName() {
        return 'report-body-validation';
    }

    function initialize(Server $server) {
        $this->server = $server;
        $server->on('method:REPORT', [$this, 'httpReport'], self::PRIORITY);
        $server->on('method:PROPFIND', [$this, 'assertWellFormedBody'], self::PRIORITY);
        $server->on('method:PROPPATCH', [$this, 'assertWellFormedBody'], self::PRIORITY);
        $server->on('method:ACL', [$this, 'httpAcl'], self::PRIORITY);
        $server->on('method:POST', [$this, 'httpPost'], self::PRIORITY);
    }

    function httpReport(RequestInterface $request, ResponseInterface $response) {
        $this->assertWellFormedBody($request, $response);

        return $this->delegate('core', 'httpReport', 'REPORT', $request, $response);
    }

    function httpAcl(RequestInterface $request, ResponseInterface $response) {
        $this->assertWellFormedBody($request, $response);

        return $this->delegate('acl', 'httpAcl', 'ACL', $request, $response);
    }

    /**
     * Only XML POSTs are guarded: they are the ones Sabre's CalDAV sharing
     * plugin parses. Other POSTs (JSON, iCalendar, forms) are left untouched.
     */
    function httpPost(RequestInterface $request, ResponseInterface $response) {
        if (!$this->hasXmlContentType($request)) {
            return;
        }

        $this->assertWellFormedBody($request, $response);

        return $this->delegate('caldav-sharing', 'httpPost', 'POST', $request, $response);
    }

    /**
     * Ensures the request body, when present, is a well-formed XML document.
     *
     * Returns (letting Sabre proceed) on an empty body: an empty PROPFIND means
     * `allprop`, and Sabre maps an empty REPORT/PROPPATCH/ACL body to its own
     * error.
     * Throws BadRequest on a malformed body, so Sabre never deserialises it.
     */
    function assertWellFormedBody(RequestInterface $request, ResponseInterface $response) {
        $body = $request->getBodyAsString();

        // getBodyAsString() consumes a stream body; put it back as a string so
        // the core handler can read it again.
        $request->setBody($body);

        if ('' === $body) {
            return;
        }

        if (!$this->isWellFormedXml($body)) {
            throw new BadRequest('The request body is not a well-formed XML document');
        }
    }

    /**
     * Runs the handler of the given Sabre plugin, reporting the XML parse
     * errors it lets escape as 400. Lets the event chain go on when the plugin
     * is not registered.
     */
    private function delegate(string $pluginName, string $handler, string $method, RequestInterface $request, ResponseInterface $response) {
        $plugin = $this->server->getPlugin($pluginName);
        if (!$plugin) {
            return;
        }

        try {
            return $plugin->$handler($request, $response);
        } catch (ParseException $e) {
            throw new BadRequest('The ' . $method . ' request body is not a valid XML document: ' . $e->getMessage());
        }
    }

    private function hasXmlContentType(RequestInterface $request): bool {
        $contentType = (string) $request->getHeader('Content-Type');

        return str_contains($contentType, 'application/xml') || str_contains($contentType, 'text/xml');
    }

    /**
     * Reads the whole document with XMLReader and reports whether libxml saw a
     * fatal error. Unlike XMLReader::next() (used by Sabre's deserialiser), a
     * read() sweep surfaces a mismatched end tag instead of stalling on it.
     *
     * Entities are not expanded (no LIBXML_NOENT) and the network is disabled
     * (LIBXML_NONET), so a hostile body cannot expand or fetch anything here.
     */
    private function isWellFormedXml(string $body): bool {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $reader = new \XMLReader();
        if (false === @$reader->XML($body, null, LIBXML_NONET)) {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);

            return false;
        }

        $wellFormed = true;
        while (false !== @$reader->read()) {
            // keep reading until the end of the document
        }
        if (libxml_get_errors()) {
            $wellFormed = false;
        }

        $reader->close();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $wellFormed;
    }
}
