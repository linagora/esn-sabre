<?php

namespace ESN\DAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;

class CorsPlugin extends ServerPlugin {

    public $allowMethods = ['GET', 'POST', 'PUT', 'PROPFIND', 'REPORT'];
    public $allowHeaders = ["Depth", 'Authorization'];
    public $allowOrigin = ["*"];
    public $allowCredentials = true;

    function initialize(Server $server) {
        $this->server = $server;
        foreach($this->allowMethods as $method) {
            $server->on('method:' . $method, [$this, 'addCORSHeaders'], 90);
        }

        $server->on('beforeMethod:OPTIONS', [$this, 'authPreflight'], 5);
    }

    function addCORSHeaders(RequestInterface $request, ResponseInterface $response) {
        $response->setHeader('Access-Control-Allow-Origin', join(', ', $this->allowOrigin));
        $response->setHeader('Access-Control-Allow-Headers', join(', ', $this->allowHeaders));
        $response->setHeader('Access-Control-Allow-Methods', join(', ', $this->allowMethods));

        if ($this->allowCredentials) {
            $response->setHeader('Access-Control-Allow-Credentials', 'true');
        }
        return true;
    }

    function authPreflight(RequestInterface $request, ResponseInterface $response) {
        if ($request->getHeader("Access-Control-Request-Method")) {
            $this->addCORSHeaders($request, $response);
            $this->server->sapi->sendResponse($response);
            return false;
        }
        return true;
    }
}
