<?php

namespace ESN\DAV;

use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\Uri;

/**
 * Refuses to bind a resource named "." or "..".
 *
 * Sabre removes the literal dot segments of a request URL, but decodes the
 * path afterwards: a PUT to ".../book/%2E" creates a resource named ".". Its
 * href is then listed as ".../book/.", which a client resolving URLs per
 * WHATWG turns into ".../book/": deleting that card deletes the whole address
 * book (or calendar).
 *
 * beforeBind is emitted for every new binding: PUT creating a resource, MKCOL,
 * and the destination of COPY and MOVE. Names which become a dot segment once
 * percent-decoded ("%2E", "%252E", "%2e%2e"...) are refused too, as a client
 * may decode the listed href before resolving it.
 */
class DotSegmentNameGuardPlugin extends ServerPlugin {

    private const DOT_SEGMENTS = ['.', '..'];

    function initialize(Server $server) {
        $server->on('beforeBind', [$this, 'beforeBind'], 10);
    }

    function getPluginName() {
        return 'dot-segment-name-guard';
    }

    function beforeBind($path) {
        $name = Uri\split($path)[1] ?? '';
        if (self::isDotSegment($name)) {
            throw new BadRequest('"." and ".." are not allowed as resource names');
        }

        return true;
    }

    private static function isDotSegment(string $name): bool {
        if (in_array($name, self::DOT_SEGMENTS, true)) {
            return true;
        }

        $decoded = rawurldecode($name);
        return $decoded !== $name && self::isDotSegment($decoded);
    }
}
