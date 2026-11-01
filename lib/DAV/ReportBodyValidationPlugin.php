<?php

namespace ESN\DAV;

use \Sabre\DAV\Exception\BadRequest;
use \Sabre\DAV\Exception\ReportNotSupported;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;
use \Sabre\VObject\InvalidDataException;
use \Sabre\Xml\ParseException;

/**
 * Guards the XML request body of the DAV methods whose body Sabre deserialises
 * with the shared `{DAV:}prop` deserialiser: REPORT, PROPFIND and PROPPATCH.
 *
 * Two distinct problems are handled here, both before Sabre's core handler runs:
 *
 *  1. Sabre's core REPORT handler lets deserialisation errors of the request
 *     body escape as 500, while PROPFIND/PROPPATCH already map them to 400.
 *     Besides XML parse errors, the CalDAV filter deserialisers throw on a
 *     malformed `time-range` date (InvalidDataException) and read the required
 *     `name` attribute of `comp-filter`/`prop-filter`/`param-filter` unchecked
 *     (a warning, turned into ErrorException by esn.php's error handler). We
 *     handle REPORT ourselves, like Sabre's core handler does, and report a
 *     body deserialisation error as 400. Errors raised while running the
 *     report itself are left untouched.
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
 *
 *  3. libxml's reader and writer disagree on what a valid element name is:
 *     `XMLReader` accepts names that `XMLWriter` refuses (e.g. one holding
 *     U+FEFF). Sabre echoes every requested property it cannot find back in
 *     the multistatus, so such a name made `XMLWriter` throw a `ValueError`
 *     (500) while the response was written. The same sweep rejects with 400 a
 *     body holding an element name that `XMLWriter` would not write.
 */
#[\AllowDynamicProperties]
class ReportBodyValidationPlugin extends ServerPlugin {
    // Right before Sabre\DAV\CorePlugin's handlers (default priority 100).
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
    }

    function httpReport(RequestInterface $request, ResponseInterface $response) {
        $this->assertWellFormedBody($request, $response);

        $result = $this->parseReportBody($request, $rootElementName);

        if ($this->server->emit('report', [$rootElementName, $result, $request->getPath()])) {
            // No plugin handled the report
            throw new ReportNotSupported();
        }

        // Interrupt the event chain: the REPORT has been handled
        return false;
    }

    private function parseReportBody(RequestInterface $request, &$rootElementName) {
        try {
            return $this->server->xml->parse($request->getBody(), $request->getUrl(), $rootElementName);
        } catch (ParseException $e) {
            throw new BadRequest('The REPORT request body is not a valid XML document: ' . $e->getMessage());
        } catch (InvalidDataException | \ErrorException $e) {
            throw new BadRequest('The REPORT request body is invalid: ' . $e->getMessage());
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
     * Reports whether XMLWriter, which Sabre uses to write the response, would
     * accept this element name. Its name check is stricter than XMLReader's.
     */
    private function isWritableElementName(\XMLWriter $writer, string $name): bool {
        try {
            return false !== @$writer->startElement($name);
        } catch (\ValueError $e) {
            return false;
        }
    }

    /**
     * Reads the whole document with XMLReader and reports whether libxml saw a
     * fatal error. Unlike XMLReader::next() (used by Sabre's deserialiser), a
     * read() sweep surfaces a mismatched end tag instead of stalling on it.
     * Element names that XMLWriter could not write back also fail the check.
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

        $writer = new \XMLWriter();
        $writer->openMemory();

        $wellFormed = true;
        while ($wellFormed && false !== @$reader->read()) {
            if (\XMLReader::ELEMENT === $reader->nodeType) {
                $wellFormed = $this->isWritableElementName($writer, $reader->localName);
            }
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
