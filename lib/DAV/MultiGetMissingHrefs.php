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
 * again with a 404 response for each missing href. Nothing changes when every
 * href exists, and no lookup is added but that of each parent collection,
 * which the tree caches for the stock report.
 *
 * The stock report would also fail as a whole, with a 404, for an href whose
 * parent collection does not exist (or does not look its children up in one
 * query, and lacks that child): Tree::getMultipleNodes() throws NotFound for
 * it. Such hrefs are left out of the report beforehand, and answered 404 too.
 */
class MultiGetMissingHrefs {

    /**
     * @param Server   $server
     * @param object   $request the parsed multiget REPORT, whose hrefs the stock report reads
     * @param callable $report  runs the stock report on $request
     * @return void
     */
    static function run(Server $server, $request, callable $report) {
        $hrefs = $request->hrefs;
        $request->hrefs = self::hrefsTheTreeServes($server, $hrefs);

        $entries = [];
        $capture = function (&$fileProperties) use (&$entries) {
            $entries = $fileProperties;
        };

        $server->on('beforeMultiStatus', $capture, 1000);
        try {
            $report();
        } finally {
            $server->removeListener('beforeMultiStatus', $capture);
            $request->hrefs = $hrefs;
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

    /**
     * The hrefs for which Tree::getMultipleNodes() does not throw NotFound: those
     * of an existing collection supporting multi-get, and the existing children
     * of another collection.
     *
     * @param Server   $server
     * @param string[] $hrefs
     * @return string[]
     */
    private static function hrefsTheTreeServes(Server $server, array $hrefs) {
        $parents = [];
        $served = [];
        foreach ($hrefs as $href) {
            $path = $server->calculateUri($href);
            list($parent) = \Sabre\Uri\split($path);
            if (!array_key_exists($parent, $parents)) {
                $parents[$parent] = self::parentNode($server, $parent);
            }

            $parentNode = $parents[$parent];
            if ($parentNode instanceof \Sabre\DAV\IMultiGet
                || ($parentNode !== null && $server->tree->nodeExists($path))) {
                $served[] = $href;
            }
        }

        return $served;
    }

    private static function parentNode(Server $server, $path) {
        try {
            return $server->tree->getNodeForPath($path);
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            return null;
        }
    }
}
