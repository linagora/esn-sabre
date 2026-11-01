<?php

namespace ESN\CardDAV;

use Sabre\CardDAV\IAddressBook;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\IFile;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\Uri;

/**
 * Applies the card write guards to COPY and MOVE.
 *
 * Sabre's Tree copies a file by calling createFile() on the destination
 * collection directly: beforeCreateFile is never emitted, so the vCard
 * validation, the inline photo policy and the domain-members guard, all bound
 * to that event, are skipped.
 *
 * When the destination is an address book, this plugin emits beforeCreateFile
 * with the source data, as a PUT of that data would. A guard that throws or
 * stops the event refuses the request. When a guard rewrites the data (inline
 * photo filtered, card repaired), the copy is replaced with the rewritten card
 * once Sabre has stored it.
 */
class CopyMoveGuardPlugin extends ServerPlugin {

    /**
     * @var Server
     */
    protected $server;

    /**
     * Rewritten card data, by destination path.
     *
     * @var array<string, string>
     */
    private array $rewrittenCards = [];

    function initialize(Server $server) {
        $this->server = $server;

        $server->on('beforeCopy', [$this, 'beforeCopy']);
        $server->on('beforeMove', [$this, 'beforeMove']);
        $server->on('afterCopy', [$this, 'storeRewrittenCard']);
        $server->on('afterMove', [$this, 'storeRewrittenCard']);
    }

    function getPluginName() {
        return 'carddav-copy-move-guard';
    }

    function beforeCopy($sourcePath, $destinationPath) {
        return $this->guardCardWrite($sourcePath, $destinationPath);
    }

    function beforeMove($sourcePath, $destinationPath) {
        // A move within one collection is a rename: Tree::move() writes no data.
        if (self::parentPath($sourcePath) === self::parentPath($destinationPath)) {
            return true;
        }

        return $this->guardCardWrite($sourcePath, $destinationPath);
    }

    function storeRewrittenCard($sourcePath, $destinationPath) {
        if (!array_key_exists($destinationPath, $this->rewrittenCards)) {
            return;
        }

        $data = $this->rewrittenCards[$destinationPath];
        unset($this->rewrittenCards[$destinationPath]);
        $this->server->tree->getNodeForPath($destinationPath)->put($data);
    }

    private function guardCardWrite($sourcePath, $destinationPath) {
        $destinationParent = $this->server->tree->getNodeForPath(self::parentPath($destinationPath));
        if (!$destinationParent instanceof IAddressBook) {
            return true;
        }

        $source = $this->server->tree->getNodeForPath($sourcePath);
        if (!$source instanceof IFile) {
            throw new Forbidden('Only cards can be copied or moved into an address book');
        }

        $data = $source->get();
        $modified = false;
        if (!$this->server->emit('beforeCreateFile', [$destinationPath, &$data, $destinationParent, &$modified])) {
            return false;
        }

        if ($modified) {
            $this->rewrittenCards[$destinationPath] = is_resource($data) ? stream_get_contents($data) : $data;
        }

        return true;
    }

    private static function parentPath($path): string {
        return Uri\split($path)[0];
    }
}
