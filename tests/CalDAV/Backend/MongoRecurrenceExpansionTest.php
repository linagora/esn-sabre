<?php

namespace ESN\CalDAV\Backend;

require_once ESN_TEST_BASE . '/CalDAV/Backend/RecurrenceExpansionTest.php';

/**
 * @medium
 */
class MongoRecurrenceExpansionTest extends RecurrenceExpansionTest {

    protected $esndb;
    protected $sabredb;
    protected $backend;

    function setUp() {
        $mc = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mc->{ESN_MONGO_SABREDB};
        $this->sabredb->drop();

        $this->backend = new Mongo($this->sabredb);
    }

    function tearDown() {
        if ($this->sabredb) {
            $this->sabredb->drop();
        }
    }

    protected function getBackend() {
        return $this->backend;
    }

    protected function generateId() {
        // Generate MongoDB ObjectIds directly like MongoTest does
        return [(string) new \MongoDB\BSON\ObjectId(), (string) new \MongoDB\BSON\ObjectId()];
    }
}
