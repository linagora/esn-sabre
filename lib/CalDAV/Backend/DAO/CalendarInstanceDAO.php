<?php

namespace ESN\CalDAV\Backend\DAO;

use Sabre\DAV\Sharing\Plugin;

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
        return $this->updateOne(
            ['_id' => new \MongoDB\BSON\ObjectId($instanceId)],
            ['$set' => $newValues]
        );
    }

    public function findInstanceById($instanceId, array $projection = []) {
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->findOne(['_id' => new \MongoDB\BSON\ObjectId($instanceId)], $options);
    }

    public function deleteInstanceById($instanceId) {
        return $this->deleteOne(['_id' => new \MongoDB\BSON\ObjectId($instanceId)]);
    }

    public function deleteInstancesByCalendarId($calendarId) {
        return $this->deleteMany(['calendarid' => new \MongoDB\BSON\ObjectId($calendarId)]);
    }

    public function findInstanceByCalendarIdAndShareHref($calendarId, $shareHref) {
        return $this->findOne(
            ['calendarid' => new \MongoDB\BSON\ObjectId($calendarId), 'share_href' => $shareHref],
            ['projection' => ['uri' => 1]]
        );
    }

    public function deleteInstanceByCalendarIdAndShareHref($calendarId, $shareHref) {
        return $this->deleteOne(['calendarid' => new \MongoDB\BSON\ObjectId($calendarId), 'share_href' => $shareHref]);
    }

    public function updateShareeAccess($calendarId, $shareHref, array $updateData) {
        return $this->updateOne(
            ['calendarid' => new \MongoDB\BSON\ObjectId($calendarId), 'share_href' => $shareHref],
            ['$set' => $updateData]
        );
    }

    public function findInvitesByCalendarId($calendarId, array $projection = []) {
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find(['calendarid' => new \MongoDB\BSON\ObjectId($calendarId)], $options);
    }

    // Fetch the source calendar instance for a shared calendar.
    public function findSourceInstanceByCalendarId($calendarId, array $projection = []) {
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->findOne(
            [
                'calendarid' => new \MongoDB\BSON\ObjectId($calendarId),
                'access' => Plugin::ACCESS_SHAREDOWNER
            ],
            $options
        );
    }

    public function updatePublicRight($calendarId, $privilege) {
        return $this->updateMany(
            ['calendarid' => new \MongoDB\BSON\ObjectId($calendarId)],
            ['$set' => ['public_right' => $privilege]]
        );
    }

    public function getPublicRight($calendarId) {
        return $this->findOne(
            ['calendarid' => new \MongoDB\BSON\ObjectId($calendarId)],
            ['projection' => ['public_right' => 1]]
        );
    }

    public function updateInviteStatus($instanceId, $status) {
        return $this->updateOne(
            ['_id' => new \MongoDB\BSON\ObjectId($instanceId)],
            ['$set' => ['share_invitestatus' => $status]]
        );
    }

    public function findInstancesByPrincipalUriWithAccess($principalUri, array $projection = []) {
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find(['principaluri' => $principalUri], $options);
    }

    public function ensureIndexes() {
        // Create a unique compound index on 'principaluri' and 'uri'
        $this->createIndex(
            ['principaluri' => 1, 'uri' => 1],
            ['unique' => true]
        );
    }
}
