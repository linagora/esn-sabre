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
        return $this->findOne(
            ['_id' => new \MongoDB\BSON\ObjectId($calendarId)],
            ['projection' => ['synctoken' => 1]]
        );
    }

    public function incrementSyncToken($calendarId) {
        return $this->updateOne(
            ['_id' => new \MongoDB\BSON\ObjectId($calendarId)],
            ['$inc' => ['synctoken' => 1]]
        );
    }

    public function ensureIndexes() {
        // No specific indexes needed for calendars collection
        // _id is already indexed by default
    }
}
