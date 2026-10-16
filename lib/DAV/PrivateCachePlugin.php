<?php

namespace ESN\DAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;

/**
 * Marks every response as private to the authenticated principal.
 *
 * DAV responses carry ETag / Last-Modified but no explicit freshness, which
 * makes them heuristically cacheable (RFC 9111 4.2.2). Without these headers a
 * browser may serve one user's cached calendar object to a later request
 * issued with another user's credentials.
 */
class PrivateCachePlugin extends ServerPlugin {
    const CACHE_CONTROL = 'private, no-cache';
    const VARY = 'Authorization';

    function initialize(Server $server) {
        // Runs first so that handlers short-circuiting the request (JSON API,
        // method override...) still send the headers.
        $server->on('beforeMethod:*', [$this, 'markPrivate'], 1);
    }

    function getPluginName() {
        return 'private-cache';
    }

    function markPrivate(RequestInterface $request, ResponseInterface $response) {
        $response->setHeader('Cache-Control', self::CACHE_CONTROL);
        $response->setHeader('Vary', self::VARY);

        return true;
    }
}
