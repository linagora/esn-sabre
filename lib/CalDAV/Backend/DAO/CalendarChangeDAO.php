<?php

namespace ESN\CalDAV\Backend\DAO;

class CalendarChangeDAO extends BaseDAO {
    public function __construct(\MongoDB\Database $db, $collectionName = 'calendarchanges') {
        parent::__construct($db, $collectionName);
    }

    public function deleteChangesByCalendarId($calendarId) {
        return $this->deleteMany(['calendarid' => new \MongoDB\BSON\ObjectId($calendarId)]);
    }

    public function findChangesBySyncToken($calendarId, $syncToken, $currentToken, $limit = null) {
        $projection = [
            'uri' => 1,
            'operation' => 1
        ];

        $query = [
            'synctoken' => ['$gte' => (int) $syncToken, '$lt' => (int) $currentToken],
            'calendarid' => new \MongoDB\BSON\ObjectId($calendarId)
        ];

        $options = [
            'projection' => $projection,
            'sort' => ['synctoken' => 1]
        ];

        if ($limit > 0) {
            $options['limit'] = $limit;
        }

        return $this->find($query, $options);
    }

    public function addChange($calendarId, $objectUri, $syncToken, $operation) {
        $obj = [
            'uri' => $objectUri,
            'synctoken' => $syncToken,
            'calendarid' => new \MongoDB\BSON\ObjectId($calendarId),
            'operation' => $operation
        ];
        return $this->insertOne($obj);
    }

    public function ensureIndexes() {
        $this->createIndex([
            'calendarid' => 1,
            'synctoken' => 1
        ]);
    }
}
