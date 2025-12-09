<?php

namespace ESN\CalDAV\Backend\DAO;

class CalendarObjectDAO extends BaseDAO {
    public function __construct(\MongoDB\Database $db, $collectionName = 'calendarobjects') {
        parent::__construct($db, $collectionName);
    }

    public function findByCalendarId($calendarId, array $projection = []) {
        $query = ['calendarid' => $calendarId];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find($query, $options);
    }

    public function findByCalendarIdAndUris($calendarId, array $uris, array $projection = []) {
        $query = ['calendarid' => $calendarId, 'uri' => ['$in' => $uris]];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find($query, $options);
    }

    public function createCalendarObject(array $objectData) {
        return $this->insertOne($objectData);
    }

    public function updateCalendarObject($calendarId, $objectUri, array $updateData) {
        return $this->updateOne(
            ['calendarid' => $calendarId, 'uri' => $objectUri],
            ['$set' => $updateData]
        );
    }

    public function deleteCalendarObject($calendarId, $objectUri) {
        return $this->deleteOne(['calendarid' => $calendarId, 'uri' => $objectUri]);
    }

    public function deleteAllObjectsByCalendarId($calendarId) {
        return $this->deleteMany(['calendarid' => new \MongoDB\BSON\ObjectId($calendarId)]);
    }

    public function findWithQuery(array $query, array $projection = []) {
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find($query, $options);
    }

    public function findByUid($calendarIds, $uid, array $projection = []) {
        $query = ['calendarid' => ['$in' => $calendarIds], 'uid' => $uid];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->findOne($query, $options);
    }

    public function findByUri($calendarIds, $uri, array $projection = []) {
        $query = ['calendarid' => ['$in' => $calendarIds], 'uri' => $uri];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->findOne($query, $options);
    }

    public function findByUidMultiple($calendarIds, $uid, array $projection = []) {
        $query = ['calendarid' => ['$in' => $calendarIds], 'uid' => $uid];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find($query, $options);
    }

    public function ensureIndexes() {
        // Index for all calendar object queries
        $this->createIndex(['calendarid' => 1]);

        // Compound index for getMultipleCalendarObjects and getCalendarObject
        $this->createIndex(['calendarid' => 1, 'uri' => 1]);

        // Compound index for calendarQuery with time-range filters
        $this->createIndex([
            'calendarid' => 1,
            'componenttype' => 1,
            'firstoccurence' => 1,
            'lastoccurence' => 1
        ]);

        // Index for getCalendarObjectByUID and getDuplicateCalendarObjectsByURI
        $this->createIndex(['uid' => 1]);
    }
}
