<?php

namespace ESN\DAV;

use \Sabre\DAV\Exception\BadRequest;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;
use \Sabre\Xml\ParseException;

/**
 * Sabre's core REPORT handler lets XML parse errors of the request body
 * escape as 500, while PROPFIND/PROPPATCH/MKCOL already answer 400.
 *
 * This plugin runs the core REPORT handler itself, just before Sabre would,
 * and reports a malformed or non-XML body as 400 Bad Request.
 */
#[\AllowDynamicProperties]
class ReportBodyValidationPlugin extends ServerPlugin {
    // Right before Sabre\DAV\CorePlugin::httpReport (default priority 100)
    const PRIORITY = 99;

    protected $server;

    function getPluginName() {
        return 'report-body-validation';
    }

    function initialize(Server $server) {
        $this->server = $server;
        $server->on('method:REPORT', [$this, 'httpReport'], self::PRIORITY);
    }

    function httpReport(RequestInterface $request, ResponseInterface $response) {
        try {
            return $this->server->getPlugin('core')->httpReport($request, $response);
        } catch (ParseException $e) {
            throw new BadRequest('The REPORT request body is not a valid XML document: ' . $e->getMessage());
        }
    }
}
