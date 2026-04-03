<?php

namespace ESN\DAVACL\DAO;

use ESN\CalDAV\Backend\DAO\BaseDAO;

class ResourceDAO extends BaseDAO {

    public function __construct(\MongoDB\Database $db) {
        parent::__construct($db, 'resources');
    }

    /**
     * Find a resource document by its ID.
     *
     * @param string $id The resource ID (MongoDB ObjectId as string)
     * @return array|null The resource document, or null if not found
     */
    public function findById(string $id): ?array {
        try {
            $objectId = new \MongoDB\BSON\ObjectId($id);
        } catch (\Exception $e) {
            return null;
        }

        return $this->findOne(['_id' => $objectId]);
    }
}
