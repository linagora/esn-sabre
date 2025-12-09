<?php

namespace ESN\CalDAV\Backend\DAO;

class CalendarDAO extends BaseDAO {
    public function __construct(\MongoDB\Database $db, $collectionName = 'calendars') {
        parent::__construct($db, $collectionName);
    }

    public function findByIds(array $calendarIds, array $projection = []) {
        $query = ['_id' => ['$in' => $calendarIds]];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find($query, $options);
    }

    public function createCalendar(array $calendarData) {
        $result = $this->insertOne($calendarData);
        return (string) $result->getInsertedId();
    }

    public function deleteById($calendarId) {
        return $this->deleteOne(['_id' => new \MongoDB\BSON\ObjectId($calendarId)]);
    }

    public function getSyncToken($calendarId) {
        $projection = ['synctoken' => 1];
        $query = ['_id' => new \MongoDB\BSON\ObjectId($calendarId)];
        return $this->findOne($query, ['projection' => $projection]);
    }

    public function incrementSyncToken($calendarId) {
        $query = ['_id' => new \MongoDB\BSON\ObjectId($calendarId)];
        $update = ['$inc' => ['synctoken' => 1]];
        return $this->updateOne($query, $update);
    }

    public function ensureIndexes() {
        // No specific indexes needed for calendars collection
        // _id is already indexed by default
    }
}
