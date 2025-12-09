<?php

namespace ESN\CalDAV\Backend\DAO;

class CalendarSubscriptionDAO extends BaseDAO {
    public function __construct(\MongoDB\Database $db, $collectionName = 'calendarsubscriptions') {
        parent::__construct($db, $collectionName);
    }

    public function findByPrincipalUri($principalUri, array $fields = [], array $sort = []) {
        $query = ['principaluri' => $principalUri];
        $options = [];

        if (!empty($fields)) {
            $options['projection'] = array_fill_keys($fields, 1);
        }

        if (!empty($sort)) {
            $options['sort'] = $sort;
        }

        return $this->find($query, $options);
    }

    public function createSubscription(array $subscriptionData) {
        $result = $this->insertOne($subscriptionData);
        return (string) $result->getInsertedId();
    }

    public function updateSubscriptionById($subscriptionId, array $newValues) {
        $query = ['_id' => new \MongoDB\BSON\ObjectId($subscriptionId)];
        return $this->updateOne($query, ['$set' => $newValues]);
    }

    public function findSubscriptionById($subscriptionId, array $projection = []) {
        $query = ['_id' => new \MongoDB\BSON\ObjectId($subscriptionId)];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->findOne($query, $options);
    }

    public function deleteSubscriptionById($subscriptionId) {
        return $this->deleteOne(['_id' => new \MongoDB\BSON\ObjectId($subscriptionId)]);
    }

    public function findSubscribersBySource($source, array $projection = []) {
        $query = ['source' => $source];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find($query, $options);
    }

    public function ensureIndexes() {
        $this->createIndex(['principaluri' => 1]);
        $this->createIndex(['source' => 1]);
    }
}
