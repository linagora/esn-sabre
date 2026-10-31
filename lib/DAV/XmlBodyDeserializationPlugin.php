<?php

namespace ESN\DAV;

use \Sabre\DAV\Exception\BadRequest;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
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
 * handler), a TypeError or an InvalidDataException. Sabre's handlers only map
 * `ParseException` to 400, so these escape as 500.
 *
 * We deserialise the body once ourselves, before any handler, and turn these
 * failures into 400. Parse errors are left to Sabre's handlers, which already
 * deal with them.
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

        if ('' === $body) {
            return;
        }

        try {
            $this->server->xml->parse($body, $request->getUrl());
        } catch (ParseException $e) {
            return;
        } catch (\ErrorException | \TypeError | \ValueError | InvalidDataException $e) {
            throw new BadRequest('The ' . $request->getMethod() . ' request body could not be deserialised: ' . $e->getMessage());
        }
    }

    private function hasXmlBody(RequestInterface $request): bool {
        if (!in_array($request->getMethod(), self::XML_CONTENT_TYPE_METHODS)) {
            return true;
        }

        return str_contains(strtolower($request->getHeader('Content-Type') ?? ''), 'xml');
    }
}
