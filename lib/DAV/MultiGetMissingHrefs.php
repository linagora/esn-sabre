<?php

namespace ESN\DAV;

use Sabre\DAV\Server;
use Sabre\DAV\Xml\Element\Response;
use Sabre\DAV\Xml\Response\MultiStatus;

/**
 * Sabre's calendar-multiget and addressbook-multiget leave an href that names
 * no resource out of the multistatus (Server::getPropertiesForMultiplePaths
 * skips it), while RFC 4791 section 7.9 and RFC 6352 section 8.7 want a 404
 * response for it: without one, a client cannot tell a deleted resource from
 * an answer that was dropped.
 *
 * The stock report is kept; the entries it hands to the multistatus are
 * captured and, when an href is missing among them, the multistatus is written
 * again with a 404 response for each missing href. Nothing changes, and no
 * lookup is added, when every href exists.
 */
class MultiGetMissingHrefs {

    /**
     * @param Server   $server
     * @param string[] $hrefs  the hrefs of the report
     * @param callable $report runs the stock report
     * @return void
     */
    static function run(Server $server, array $hrefs, callable $report) {
        $entries = [];
        $capture = function (&$fileProperties) use (&$entries) {
            $entries = $fileProperties;
        };

        $server->on('beforeMultiStatus', $capture, 1000);
        try {
            $report();
        } finally {
            $server->removeListener('beforeMultiStatus', $capture);
        }

        $found = [];
        foreach ($entries as $entry) {
            $found[trim($entry['href'], '/')] = true;
        }

        $missingPaths = [];
        foreach ($hrefs as $href) {
            $path = trim($server->calculateUri($href), '/');
            if (!isset($found[$path])) {
                $missingPaths[$path] = $path;
            }
        }

        if (!$missingPaths) {
            return;
        }

        $prefer = $server->getHTTPPrefer();
        $responses = [];
        foreach ($entries as $entry) {
            $href = $entry['href'];
            unset($entry['href']);
            if ('minimal' === $prefer['return']) {
                unset($entry[404]);
            }
            $responses[] = new Response(ltrim($href, '/'), $entry);
        }
        foreach ($missingPaths as $path) {
            $responses[] = new Response($path, [], 404);
        }

        $server->httpResponse->setBody($server->xml->write(
            '{DAV:}multistatus',
            new MultiStatus($responses),
            $server->getBaseUri()
        ));
    }
}
