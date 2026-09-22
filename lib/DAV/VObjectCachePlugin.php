<?php

namespace ESN\DAV;

use ESN\Utils\VObjectCache;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Makes a request scoped {@see VObjectCache} reachable from anywhere that has
 * the Server at hand.
 *
 * Handling a single write involves half a dozen plugins that each need the old
 * or the new calendar object parsed. Rather than threading a cache through
 * every constructor, they look it up here. The same instance is shared with the
 * CalDAV backend (see \ESN\CalDAV\Backend\Mongo::getVObjectCache), so the
 * denormalization step at the end of a write reuses the document a plugin
 * already parsed.
 */
class VObjectCachePlugin extends ServerPlugin {
    const PLUGIN_NAME = 'esn-vobject-cache';

    /**
     * Caches handed out for servers that have no plugin registered.
     *
     * Keeping them keyed by server rather than returning a throwaway cache per
     * call means unit tests building a bare Server still get the deduplication,
     * and the WeakMap lets the entry go as soon as the server does.
     *
     * @var \WeakMap<Server, VObjectCache>|null
     */
    private static $fallbacks = null;

    /** @var VObjectCache */
    private $cache;

    function __construct(?VObjectCache $cache = null) {
        $this->cache = $cache ?: new VObjectCache();
    }

    function initialize(Server $server) {
        // Start every request with an empty cache: a document parsed while
        // serving a previous request must never be handed to the next one.
        $server->on('beforeMethod:*', [$this, 'resetCache'], 1);
    }

    function resetCache() {
        $this->cache->reset();
        $this->cache->resetStats();
    }

    function getPluginName() {
        return self::PLUGIN_NAME;
    }

    function getCache() {
        return $this->cache;
    }

    /**
     * Returns the cache to use for a given server.
     *
     * Falls back to a per-server cache when the plugin was not registered, so
     * call sites never have to branch on its presence.
     *
     * @param Server|null $server
     * @return VObjectCache
     */
    static function cacheFor(?Server $server) {
        if ($server) {
            $plugin = $server->getPlugin(self::PLUGIN_NAME);

            if ($plugin instanceof self) {
                return $plugin->getCache();
            }

            if (self::$fallbacks === null) {
                self::$fallbacks = new \WeakMap();
            }

            if (!isset(self::$fallbacks[$server])) {
                self::$fallbacks[$server] = new VObjectCache();
            }

            return self::$fallbacks[$server];
        }

        return new VObjectCache();
    }
}
