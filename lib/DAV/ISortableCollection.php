<?php

namespace ESN\DAV;

interface ISortableCollection extends \Sabre\DAV\ICollection {

    /**
     * Returns an array with all the child nodes
     *
     * @param int $offset Number of entries to skip
     * @param int $limit Maximum results, or 0 for unlimited
     * @param string $sort Sort field, or null for unsorted
     * @return DAV\INode[]
     */
    function getChildren($offset = 0, $limit = 0, $sort = null);

    /**
     * Retrieves the number of items in the collection
     *
     * @return int
     */
    function getChildCount();
}
