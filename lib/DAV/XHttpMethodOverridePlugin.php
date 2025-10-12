<?php

namespace ESN\DAV;

use \Sabre\DAV;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;
use \Sabre\DAV\ServerPlugin;
use \Sabre\DAV\Server;

/**
 * This is a simple plugin that will read any X-HTTP-OVERRIDE
 * headers and call the correct handlers accordingly
 *
 */
#[\AllowDynamicProperties]
class XHttpMethodOverridePlugin extends ServerPlugin {
    /**
     * reference to server class
     *
     * @var DAV\Server
     */
    protected $server;

    /**
     * name of the HTTP header
     */
    private $headerName = "X-Http-Method-Override";

    /**
     * Initializes the plugin and subscribes to events
     *
     * @param DAV\Server $server
     * @return void
     */
    function initialize(Server $server) {
        $this->server = $server;
        $this->server->on('beforeMethod:*', [$this, 'override'], 90);
    }

    /**
     * This method intercepts requests and lookup for X-HTTP-METHOD-OVERRIDE header
     *
     * Calls the correct Sabre method
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    function override(RequestInterface $request, ResponseInterface $response) {
        $method = $request->getHeader($this->headerName);

        if (!$method) {
            return true;
        }

        $subRequest = clone $request;
        $subRequest->setMethod($method);
        $subRequest->removeHeader($this->headerName);
        $this->server->invokeMethod($subRequest, $response);
        return false;
    }
}

