<?php

namespace ESN\CalDAV\Backend\DAO;

abstract class BaseDAO {
    protected $db;
    protected $collectionName;

    public function __construct(\MongoDB\Database $db, $collectionName) {
        $this->db = $db;
        $this->collectionName = $collectionName;
    }

    protected function getCollection() {
        return $this->db->selectCollection($this->collectionName);
    }

    public function findOne(array $query, array $options = []) {
        $options['typeMap'] = ['root' => 'array', 'document' => 'array', 'array' => 'array'];
        return $this->getCollection()->findOne($query, $options);
    }

    public function find(array $query, array $options = []) {
        $options['typeMap'] = ['root' => 'array', 'document' => 'array', 'array' => 'array'];
        return $this->getCollection()->find($query, $options);
    }

    public function insertOne(array $document) {
        return $this->getCollection()->insertOne($document);
    }

    public function updateOne(array $query, array $update, array $options = []) {
        return $this->getCollection()->updateOne($query, $update, $options);
    }

    public function updateMany(array $query, array $update, array $options = []) {
        return $this->getCollection()->updateMany($query, $update, $options);
    }

    public function deleteOne(array $query, array $options = []) {
        return $this->getCollection()->deleteOne($query, $options);
    }

    public function deleteMany(array $query, array $options = []) {
        return $this->getCollection()->deleteMany($query, $options);
    }

    public function createIndex($keys, array $options = []) {
        return $this->getCollection()->createIndex($keys, $options);
    }
}
