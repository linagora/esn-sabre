<?php

namespace ESN\JSON\CardDAV;

/**
 * Address Book Object Handler
 *
 * Handles address book operations through the JSON API, including
 * sync-token based synchronization for address books (mirrors the
 * equivalent CalDAV sync-token REPORT).
 */
class AddressBookHandler {
    protected $server;

    public function __construct($server) {
        $this->server = $server;
    }

    /**
     * Get cards based on sync-token for incremental address book synchronization.
     *
     * Reuses the address book node's own getChanges() method, which is the same
     * path used by the WebDAV XML sync-collection REPORT, keeping the JSON and
     * XML behaviors consistent.
     *
     * @param string $nodePath Path to the address book (e.g. "/addressbooks/userId/book1")
     * @param \Sabre\CardDAV\IAddressBook $node Address book node instance
     * @param object $jsonData JSON request data containing the 'sync-token' property
     * @return array Tuple of [int $statusCode, array|null $responseBody]
     *               - [207, array] on success with multistatus response
     *               - [400, null] on invalid sync-token or address book
     */
    public function getCardsBySyncToken($nodePath, $node, $jsonData) {
        $syncToken = $this->getSyncTokenFromRequest($jsonData);

        if (!$this->isValidSyncToken($syncToken)) {
            return [400, null];
        }

        // The node implements Sabre\DAV\Sync\ISyncCollection::getChanges(), the
        // same method the XML sync-collection REPORT routes through.
        $changes = $node->getChanges($syncToken, 1);

        // null means the sync token is unknown/expired or sync is unsupported
        if ($changes === null) {
            return [400, null];
        }

        $baseUri = $this->server->getBaseUri();
        $items = $this->buildSyncItems($changes, $baseUri, $nodePath);

        return [207, [
            '_links' => [
                'self' => [ 'href' => $baseUri . $nodePath . '.json' ]
            ],
            '_embedded' => [
                'dav:item' => $items
            ],
            'sync-token' => 'http://sabre.io/ns/sync/' . $changes['syncToken']
        ]];
    }

    private function getSyncTokenFromRequest($jsonData) {
        $syncToken = isset($jsonData->{'sync-token'}) ? $jsonData->{'sync-token'} : null;

        // Extract numeric sync token from URL format if needed
        // Format can be: "http://sabre.io/ns/sync/153" or just "153"
        // Handle trailing slashes: "http://sabre.io/ns/sync/153/" -> "153"
        if ($syncToken && is_string($syncToken)) {
            $parts = explode('/', rtrim($syncToken, '/'));
            $syncToken = end($parts);
        }

        return $syncToken;
    }

    private function isValidSyncToken($syncToken) {
        if ($syncToken === '' || $syncToken === null) {
            return true;
        }

        return is_numeric($syncToken);
    }

    private function buildSyncItems($changes, $baseUri, $nodePath) {
        $items = [];

        foreach (array_merge($changes['added'], $changes['modified']) as $uri) {
            $items[] = [
                '_links' => [
                    'self' => [ 'href' => $baseUri . $nodePath . '/' . $uri ]
                ],
                'status' => 200
            ];
        }

        foreach ($changes['deleted'] as $uri) {
            $items[] = [
                '_links' => [
                    'self' => [ 'href' => $baseUri . $nodePath . '/' . $uri ]
                ],
                'status' => 404
            ];
        }

        return $items;
    }
}
