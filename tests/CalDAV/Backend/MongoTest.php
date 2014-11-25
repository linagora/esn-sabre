<?php

namespace ESN\CalDAV\Backend;

require_once 'AbstractDatabaseTest.php';

/**
 * @medium
 */
class MongoTest extends AbstractDatabaseTest {
    protected function generateId() {
        return (string) new \MongoId();
    }

    protected function getBackend() {
        $mc = new \MongoClient(ESN_MONGO_SABREURI);
        $db = $mc->selectDB(ESN_MONGO_SABREDB);
        $db->drop();
        return new Mongo($db);
    }
    
    function testConstruct() {
        $backend = $this->getBackend();
        $this->assertTrue($backend instanceof Mongo);
    }
}
