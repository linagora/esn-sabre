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
        // Calendar objects store the calendar id as a string, unlike the other collections
        return $this->deleteMany(['calendarid' => (string) $calendarId]);
    }

    public function findWithQuery(array $query, array $projection = []) {
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find($query, $options);
    }

    public function findByUid($calendarIds, string $uid, array $projection = []) {
        $query = ['calendarid' => ['$in' => $calendarIds], 'uid' => $uid];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->findOne($query, $options);
    }

    public function findByUri($calendarIds, $uri, array $projection = []) {
        $query = ['calendarid' => ['$in' => $calendarIds], 'uri' => $uri];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->findOne($query, $options);
    }

    public function findByUidMultiple($calendarIds, string $uid, array $projection = []) {
        $query = ['calendarid' => ['$in' => $calendarIds], 'uid' => $uid];
        $options = empty($projection) ? [] : ['projection' => $projection];
        return $this->find($query, $options);
    }

    /**
     * A time-range query bounds both fields (lastoccurence > start, firstoccurence < end), and MongoDB
     * bounds the scan by the first ranged field of the index only. With lastoccurence first, the scan
     * starts at the start of the range and covers what ends after it (the range, the future events and
     * the open-ended series), instead of every past event of the calendar.
     *
     * Built by migration 6 (Version0006CreateTimeRangeByEndIndex), in the background, not here: on a
     * large collection the build outlasts the startup index creation. The query planner uses it once it
     * exists.
     */
    const TIME_RANGE_BY_END_INDEX_KEY = [
        'calendarid' => 1,
        'componenttype' => 1,
        'lastoccurence' => 1,
        'firstoccurence' => 1
    ];

    /**
     * Kept next to the one above: until migration 6 has built it, it is the only time-range index, the
     * replicas still on an older version create it at startup, and it bounds the time-ranges given an
     * end only (a CalDAV calendar-query or free-busy-query may omit the start), which the index above
     * cannot. The planner picks, per query shape, the one examining fewer keys.
     */
    const TIME_RANGE_BY_START_INDEX_KEY = [
        'calendarid' => 1,
        'componenttype' => 1,
        'firstoccurence' => 1,
        'lastoccurence' => 1
    ];

    public function ensureIndexes() {
        // Compound index for getMultipleCalendarObjects and getCalendarObject, also serves the queries on
        // 'calendarid' alone
        $this->createIndex(['calendarid' => 1, 'uri' => 1]);

        // Compound index for calendarQuery with time-range filters, see TIME_RANGE_BY_END_INDEX_KEY too
        $this->createIndex(self::TIME_RANGE_BY_START_INDEX_KEY);

        // Index for getCalendarObjectByUID and getDuplicateCalendarObjects
        $this->createIndex(['uid' => 1]);
    }
}
