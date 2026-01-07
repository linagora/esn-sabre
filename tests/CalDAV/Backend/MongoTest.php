<?php

namespace ESN\CalDAV\Backend;

require_once 'AbstractDatabaseTestBase.php';

/**
 * @medium
 */
class MongoTest extends AbstractDatabaseTestBase {
    protected function generateId() {
        return [(string) new \MongoDB\BSON\ObjectId(), (string) new \MongoDB\BSON\ObjectId()];
    }

    protected function getBackend() {
        $mc = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $db = $mc->{ESN_MONGO_SABREDB};
        $db->drop();
        return new Mongo($db);
    }

    function testConstruct() {
        $backend = $this->getBackend();
        $this->assertTrue($backend instanceof Mongo);
    }
}
