<?php

namespace ESN\CalDAV\Backend\DAO;

class SchedulingObjectDAO extends BaseDAO {
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
        $query = ['principaluri' => $principalUri, 'uri' => $objectUri];
        return $this->deleteMany($query);
    }

    public function createSchedulingObject(array $objectData) {
        return $this->insertOne($objectData);
    }

    public function ensureIndexes() {
        // Create a TTL index that expires after a period of time on 'dateCreated'
        if (isset($this->schedulingObjectTTLInDays) && $this->schedulingObjectTTLInDays !== 0) {
            $this->createIndex(
                ['dateCreated' => 1],
                ['expireAfterSeconds' => $this->schedulingObjectTTLInDays * 86400]
            );
        }
    }
}
