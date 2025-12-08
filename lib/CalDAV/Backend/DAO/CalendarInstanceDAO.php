<?php

namespace ESN\CalDAV\Backend\DAO;

class CalendarInstanceDAO extends BaseDAO {
    public function __construct(\MongoDB\Database $db, $collectionName = 'calendarinstances') {
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

    public function findInstanceByPrincipalUriAndUri($principalUri, $calendarUri, $access = 1) {
        $query = [
            'principaluri' => $principalUri,
            'uri' => $calendarUri,
            'access' => $access
        ];
        $projection = [
            '_id' => 1,
            'calendarid' => 1
        ];
        return $this->findOne($query, ['projection' => $projection]);
    }

    public function createInstance(array $instanceData) {
        $result = $this->insertOne($instanceData);
        return (string) $result->getInsertedId();
    }

    public function updateInstanceById($instanceId, array $newValues) {
        $query = ['_id' => new \MongoDB\BSON\ObjectId($instanceId)];
        return $this->updateOne($query, ['$set' => $newValues]);
    }

    public function findInstanceById($instanceId, array $projection = []) {
        $query = ['_id' => new \MongoDB\BSON\ObjectId($instanceId)];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->findOne($query, $options);
    }

    public function deleteInstanceById($instanceId) {
        return $this->deleteMany(['_id' => new \MongoDB\BSON\ObjectId($instanceId)]);
    }

    public function deleteInstancesByCalendarId($calendarId) {
        return $this->deleteMany(['calendarid' => new \MongoDB\BSON\ObjectId($calendarId)]);
    }

    public function findInstanceByCalendarIdAndShareHref($calendarId, $shareHref) {
        $query = ['calendarid' => new \MongoDB\BSON\ObjectId($calendarId), 'share_href' => $shareHref];
        return $this->findOne($query, ['projection' => ['uri' => 1]]);
    }

    public function deleteInstancesByCalendarIdAndShareHref($calendarId, $shareHref) {
        return $this->deleteMany(['calendarid' => new \MongoDB\BSON\ObjectId($calendarId), 'share_href' => $shareHref]);
    }

    public function updateShareeAccess($calendarId, $shareHref, array $updateData) {
        $query = ['calendarid' => new \MongoDB\BSON\ObjectId($calendarId), 'share_href' => $shareHref];
        return $this->updateMany($query, ['$set' => $updateData]);
    }

    public function findInvitesByCalendarId($calendarId, array $projection = []) {
        $query = ['calendarid' => new \MongoDB\BSON\ObjectId($calendarId)];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find($query, $options);
    }

    public function updatePublicRight($calendarId, $privilege) {
        $query = ['calendarid' => new \MongoDB\BSON\ObjectId($calendarId)];
        return $this->updateMany($query, ['$set' => ['public_right' => $privilege]]);
    }

    public function getPublicRight($calendarId) {
        $query = ['calendarid' => new \MongoDB\BSON\ObjectId($calendarId)];
        return $this->findOne($query, ['projection' => ['public_right' => 1]]);
    }

    public function updateInviteStatus($instanceId, $status) {
        $query = ['_id' => new \MongoDB\BSON\ObjectId($instanceId)];
        return $this->updateMany($query, ['$set' => ['share_invitestatus' => $status]]);
    }

    public function findInstancesByPrincipalUriWithAccess($principalUri, array $projection = []) {
        $query = ['principaluri' => $principalUri];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find($query, $options);
    }

    public function ensureIndexes() {
        // Create a unique compound index on 'principaluri' and 'uri'
        $this->createIndex(
            ['principaluri' => 1, 'uri' => 1],
            ['unique' => true]
        );
    }
}
