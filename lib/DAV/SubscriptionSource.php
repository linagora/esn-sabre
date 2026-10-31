<?php

namespace ESN\DAV;

use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Server;
use Sabre\Uri\InvalidUriException;

/**
 * Resolves the source href of a calendar or address book subscription to a
 * server path, rejecting client hrefs that Server::calculateUri cannot handle.
 */
class SubscriptionSource {

    /**
     * @return string the path of the source, relative to the server base uri
     * @throws BadRequest when the href does not designate a path in this server
     */
    static function pathOf(Server $server, $href): string {
        if (!is_string($href) || $href === '') {
            throw new BadRequest('A subscription requires a source href');
        }

        try {
            return $server->calculateUri(self::uriPath($href));
        } catch (InvalidUriException $e) {
            throw new BadRequest('Invalid subscription source href');
        }
    }

    private static function uriPath(string $href): string {
        if ($href[0] === '/' || !strpos($href, '://')) {
            return $href;
        }

        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            throw new BadRequest('A subscription source href must designate a path');
        }

        return $path;
    }
}
