<?php

namespace ESN\CalDAV\Backend\DAO;

/**
 * @medium
 */
class SchedulingObjectDAOTest extends \PHPUnit\Framework\TestCase {
    private const SECONDS_PER_DAY = 86400;

    private $db;

    protected function setUp(): void {
        $this->db = (new \MongoDB\Client(ESN_MONGO_SABREURI))->{ESN_MONGO_SABREDB};
        $this->db->drop();
    }

    function testEnsureIndexesCreatesTheTTLIndex() {
        (new SchedulingObjectDAO($this->db, 7))->ensureIndexes();

        $this->assertSame(7 * self::SECONDS_PER_DAY, $this->ttlIndex()['expireAfterSeconds']);
    }

    function testEnsureIndexesUpdatesTheTTLOfAnExistingIndex() {
        (new SchedulingObjectDAO($this->db, 56))->ensureIndexes();

        (new SchedulingObjectDAO($this->db, 7))->ensureIndexes();

        $this->assertSame(7 * self::SECONDS_PER_DAY, $this->ttlIndex()['expireAfterSeconds']);
    }

    function testEnsureIndexesIsIdempotent() {
        (new SchedulingObjectDAO($this->db, 7))->ensureIndexes();

        (new SchedulingObjectDAO($this->db, 7))->ensureIndexes();

        $this->assertSame(7 * self::SECONDS_PER_DAY, $this->ttlIndex()['expireAfterSeconds']);
    }

    function testEnsureIndexesCreatesNoTTLIndexWhenDisabled() {
        (new SchedulingObjectDAO($this->db, 0))->ensureIndexes();

        $this->assertNull($this->ttlIndex());
    }

    function testEnsureIndexesCreatesThePrincipalUriAndUriIndex() {
        (new SchedulingObjectDAO($this->db, 0))->ensureIndexes();

        $this->assertNotNull($this->indexByKey(['principaluri' => 1, 'uri' => 1]));
    }

    private function ttlIndex() {
        return $this->indexByKey(['dateCreated' => 1]);
    }

    private function indexByKey(array $key) {
        $matchingIndexes = array_filter(
            iterator_to_array($this->db->selectCollection('schedulingobjects')->listIndexes(), false),
            fn($index) => $index->getKey() == $key
        );
        return array_values($matchingIndexes)[0] ?? null;
    }
}
