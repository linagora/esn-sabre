<?php

namespace ESN\DAV;

use \Sabre\DAV\Exception\BadRequest;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;
use \Sabre\Xml\ParseException;

/**
 * Guards the XML request body of every DAV method whose body Sabre (or an ESN
 * plugin) deserialises as XML: REPORT, PROPFIND, PROPPATCH, ACL and LOCK
 * always, MKCOL and POST when sent with an XML content type.
 *
 * Two distinct problems are handled here, both before Sabre's core handler runs:
 *
 *  1. Sabre's core REPORT handler lets XML parse errors of the request body
 *     escape as 500, while PROPFIND/PROPPATCH already map them to 400. We run
 *     the core REPORT handler ourselves and report a parse error as 400.
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
    // Right before Sabre\DAV\CorePlugin's handlers (default priority 100).
    const PRIORITY = 99;

    // After authentication (10), before the JSON (15), ACL (20) and
    // calendar subscription (30) plugins, which may parse the body themselves.
    const BEFORE_METHOD_PRIORITY = 12;

    // Methods whose body is always deserialised as XML when present.
    const XML_BODY_METHODS = ['REPORT', 'PROPFIND', 'PROPPATCH', 'ACL', 'LOCK'];

    // Methods whose body is deserialised as XML only with an XML content type
    // (MKCOL extended bodies, POST share-resource / CalDAV sharing).
    const XML_CONTENT_TYPE_METHODS = ['MKCOL', 'POST'];

    protected $server;

    function getPluginName() {
        return 'report-body-validation';
    }

    function initialize(Server $server) {
        $this->server = $server;
        $server->on('beforeMethod:*', [$this, 'beforeMethod'], self::BEFORE_METHOD_PRIORITY);
        $server->on('method:REPORT', [$this, 'httpReport'], self::PRIORITY);
    }

    function beforeMethod(RequestInterface $request, ResponseInterface $response) {
        $method = strtoupper($request->getMethod());

        if (in_array($method, self::XML_BODY_METHODS, true)
            || (in_array($method, self::XML_CONTENT_TYPE_METHODS, true) && $this->hasXmlContentType($request))) {
            $this->assertWellFormedBody($request, $response);
        }
    }

    function httpReport(RequestInterface $request, ResponseInterface $response) {
        try {
            return $this->server->getPlugin('core')->httpReport($request, $response);
        } catch (ParseException $e) {
            throw new BadRequest('The REPORT request body is not a valid XML document: ' . $e->getMessage());
        }
    }

    /**
     * Ensures the request body, when present, is a well-formed XML document.
     *
     * Returns (letting Sabre proceed) on an empty body: an empty PROPFIND means
     * `allprop`, and Sabre maps an empty REPORT/PROPPATCH body to its own error.
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
     * Sabre picks its XML handlers with loose substring checks on Content-Type
     * (`application/xml`, `text/xml`, `application/davsharing+xml`), so match
     * any content type mentioning xml rather than an exact list.
     */
    private function hasXmlContentType(RequestInterface $request): bool {
        return false !== stripos($request->getHeader('Content-Type') ?? '', 'xml');
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
