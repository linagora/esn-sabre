<?php

namespace ESN\CalDAV\Backend\DAO;

use ESN\DAV\ChangeLog\ChangeLog;

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

    /**
     * The calendar with its prunedsynctoken watermark, see ESN\DAV\ChangeLog\ChangeLog.
     */
    public function getPrunedSyncToken($calendarId) {
        return $this->findOne(
            ['_id' => new \MongoDB\BSON\ObjectId($calendarId)],
            ['projection' => [ChangeLog::PRUNED_SYNC_TOKEN => 1]]
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
