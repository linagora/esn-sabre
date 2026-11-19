<?php

namespace ESN\CalDAV\Backend\DAO;

class CalendarChangeDAO extends BaseDAO {
    private $calendarChangesTTLInDays;

    public function __construct(\MongoDB\Database $db, $calendarChangesTTLInDays = 0, $collectionName = 'calendarchanges') {
        parent::__construct($db, $collectionName);
        $this->calendarChangesTTLInDays = $calendarChangesTTLInDays;
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

    /**
     * Returns the sync token of the oldest change kept for the calendar, null when none is kept.
     */
    public function findOldestSyncToken($calendarId) {
        $oldest = $this->findOne(
            ['calendarid' => new \MongoDB\BSON\ObjectId($calendarId)],
            ['projection' => ['synctoken' => 1], 'sort' => ['synctoken' => 1]]
        );

        return $oldest ? (int) $oldest['synctoken'] : null;
    }

    public function addChange($calendarId, $objectUri, $syncToken, $operation) {
        $obj = [
            'uri' => $objectUri,
            'synctoken' => $syncToken,
            'calendarid' => new \MongoDB\BSON\ObjectId($calendarId),
            'operation' => $operation,
            'date' => new \MongoDB\BSON\UTCDateTime()
        ];
        return $this->insertOne($obj);
    }

    public function ensureIndexes() {
        $this->createIndex([
            'calendarid' => 1,
            'synctoken' => 1
        ]);

        // Bounds the change log: a client holding a token older than the oldest kept change resyncs from scratch
        if ($this->calendarChangesTTLInDays > 0) {
            $this->createIndex(
                ['date' => 1],
                ['expireAfterSeconds' => $this->calendarChangesTTLInDays * 86400]
            );
        }
    }
}
