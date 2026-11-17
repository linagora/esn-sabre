<?php

namespace ESN\DAV;

/**
 * Sabre's tree, with nodeExists() going through the node cache.
 *
 * Sabre resolves the parent and then asks it childExists(), which neither reads
 * nor fills the cache: a request on one calendar object then reads it from Mongo
 * once per nodeExists() (the ACL check, CorePlugin::httpPut, every realtime
 * message) plus once more to build the node. Resolving the node instead makes
 * every later lookup of the same path free.
 *
 * Only existing nodes are cached, as by getNodeForPath(): a missing path still
 * costs one lookup per call, and nothing that creates a node behind the tree's
 * back can leave a stale "absent" answer.
 */
class Tree extends \Sabre\DAV\Tree {

    function nodeExists($path) {
        try {
            $this->getNodeForPath($path);

            return true;
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            return false;
        }
    }
}
