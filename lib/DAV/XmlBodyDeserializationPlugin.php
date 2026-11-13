<?php

namespace ESN\DAV;

use \Sabre\DAV\Exception;
use \Sabre\DAV\Exception\BadRequest;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\DAVACL\Xml\Property\Acl;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;
use \Sabre\VObject\InvalidDataException;
use \Sabre\Xml\ParseException;

/**
 * Answers 400 when the XML request body of MKCOL, MKCALENDAR, ACL or an XML
 * POST cannot be deserialised.
 *
 * Sabre deserialises the whole body with the server's element map before its
 * handlers look at it. Several vendored deserialisers read keys and attributes
 * without checking them (`CalDAV\Xml\Request\Share`, `CalDAV\Xml\Request\MkCalendar`,
 * `CalDAV\Xml\Property\SupportedCalendarComponentSet`, `DAV\Xml\Property\ShareAccess`,
 * `CalDAV\Xml\Filter\CompFilter`...): a well-formed body missing an element or
 * an attribute raises a PHP warning (an ErrorException under esn.php's error
 * handler), a TypeError or an InvalidDataException. Some also throw the base
 * `Sabre\DAV\Exception`, which answers 500 (`DAVACL\Xml\Property\Principal`
 * with an empty `<D:href/>`, `DAV\Xml\Property\SupportedReportSet`). Sabre's
 * handlers only map `ParseException` to 400, so these escape as 500.
 *
 * We deserialise the body once ourselves, before any handler, and turn these
 * failures into 400. Bodies that are not well-formed XML are not parsed at
 * all, and parse errors are left to the handlers, which already deal with them.
 */
#[\AllowDynamicProperties]
class XmlBodyDeserializationPlugin extends ServerPlugin {
    // After authentication and ACL checks (beforeMethod), before every handler
    // of these methods (ESN\CalDAV\Subscriptions\Plugin's MKCOL handler is at 90).
    const PRIORITY = 10;

    // Methods whose handlers only read an XML body when the Content-Type says so.
    const XML_CONTENT_TYPE_METHODS = ['MKCOL', 'POST'];

    protected $server;

    function getPluginName() {
        return 'xml-body-deserialization';
    }

    function initialize(Server $server) {
        $this->server = $server;
        foreach (['MKCOL', 'MKCALENDAR', 'ACL', 'POST'] as $method) {
            $server->on('method:' . $method, [$this, 'assertDeserializableBody'], self::PRIORITY);
        }
    }

    function assertDeserializableBody(RequestInterface $request, ResponseInterface $response) {
        if (!$this->hasXmlBody($request)) {
            return;
        }

        $body = $request->getBodyAsString();

        // getBodyAsString() consumes a stream body; put it back as a string so
        // the handlers can read it again.
        $request->setBody($body);

        // A malformed body can make Sabre's deserialiser loop forever or
        // recurse until the stack limit (see ReportBodyValidationPlugin): never
        // parse it here. It is left to the handlers, where
        // ReportBodyValidationPlugin rejects it with 400 (and JSON handlers of
        // the same methods still get their non-XML bodies).
        if ('' === $body || !ReportBodyValidationPlugin::isWellFormedXml($body)) {
            return;
        }

        try {
            $result = $this->server->xml->parse($body, $request->getUrl());
        } catch (ParseException $e) {
            return;
        } catch (\ErrorException | \TypeError | \ValueError | InvalidDataException $e) {
            throw $this->undeserialisableBody($request, $e);
        } catch (Exception $e) {
            // Client errors (a BadRequest from a deserialiser) already answer
            // the right status: only server errors are mapped.
            if ($e->getHTTPCode() < 500) {
                throw $e;
            }
            throw $this->undeserialisableBody($request, $e);
        }

        if ($result instanceof Acl) {
            $this->assertAcePrincipals($result);
        }
    }

    /**
     * `DAVACL\Xml\Property\Principal` leaves its href unset for a falsy one
     * such as `<D:href>0</D:href>`: the ACE then carries a null principal,
     * which Sabre's ACL handler passes to `Server::calculateUri()` (a TypeError).
     */
    private function assertAcePrincipals(Acl $acl): void {
        foreach ($acl->getPrivileges() as $ace) {
            if (!is_string($ace['principal'] ?? null) || '' === $ace['principal']) {
                throw new BadRequest('The ACL request body contains an ACE whose principal has no usable href');
            }
        }
    }

    private function undeserialisableBody(RequestInterface $request, \Throwable $cause): BadRequest {
        return new BadRequest('The ' . $request->getMethod() . ' request body could not be deserialised: ' . $cause->getMessage());
    }

    private function hasXmlBody(RequestInterface $request): bool {
        if (!in_array($request->getMethod(), self::XML_CONTENT_TYPE_METHODS)) {
            return true;
        }

        return str_contains(strtolower($request->getHeader('Content-Type') ?? ''), 'xml');
    }
}
