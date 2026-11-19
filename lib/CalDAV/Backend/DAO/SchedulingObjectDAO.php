<?php

namespace ESN\CalDAV\Backend\DAO;

class SchedulingObjectDAO extends BaseDAO {
    private const TTL_INDEX_KEY = ['dateCreated' => 1];
    private const SECONDS_PER_DAY = 86400;

    private $schedulingObjectTTLInDays;

    public function __construct(\MongoDB\Database $db, $schedulingObjectTTLInDays = 0, $collectionName = 'schedulingobjects') {
        parent::__construct($db, $collectionName);
        $this->schedulingObjectTTLInDays = $schedulingObjectTTLInDays;
    }

    public function findByPrincipalUriAndUri($principalUri, $objectUri, array $projection = []) {
        $query = ['principaluri' => $principalUri, 'uri' => $objectUri];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->findOne($query, $options);
    }

    public function findByPrincipalUri($principalUri, array $projection = []) {
        $query = ['principaluri' => $principalUri];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find($query, $options);
    }

    public function deleteSchedulingObject($principalUri, $objectUri) {
        return $this->deleteOne(['principaluri' => $principalUri, 'uri' => $objectUri]);
    }

    public function createSchedulingObject(array $objectData) {
        return $this->insertOne($objectData);
    }

    public function ensureIndexes() {
        // Key of findByPrincipalUriAndUri, deleteSchedulingObject and, by prefix, findByPrincipalUri
        $this->createIndex(['principaluri' => 1, 'uri' => 1]);
        $this->ensureTTLIndex();
    }

    /**
     * Expire the inbox items after schedulingObjectTTLInDays on 'dateCreated'.
     *
     * createIndex fails with IndexOptionsConflict when the TTL index already exists with
     * another expireAfterSeconds (a deployment upgraded from another default, or a changed
     * setting): the existing index is then updated in place with collMod.
     */
    private function ensureTTLIndex() {
        if (empty($this->schedulingObjectTTLInDays)) {
            return;
        }

        $expireAfterSeconds = (int) $this->schedulingObjectTTLInDays * self::SECONDS_PER_DAY;
        $existingIndex = $this->findIndexByKey(self::TTL_INDEX_KEY);

        if ($existingIndex === null) {
            $this->createIndex(self::TTL_INDEX_KEY, ['expireAfterSeconds' => $expireAfterSeconds]);
        } elseif (($existingIndex['expireAfterSeconds'] ?? null) !== $expireAfterSeconds) {
            $this->db->command([
                'collMod' => $this->collectionName,
                'index' => ['keyPattern' => self::TTL_INDEX_KEY, 'expireAfterSeconds' => $expireAfterSeconds]
            ]);
        }
    }

    private function findIndexByKey(array $key) {
        $matchingIndexes = array_filter(
            iterator_to_array($this->getCollection()->listIndexes(), false),
            fn($index) => $index->getKey() == $key
        );
        return array_values($matchingIndexes)[0] ?? null;
    }
}
