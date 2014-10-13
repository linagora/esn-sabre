<?php

namespace ESN\DAV\Auth\Backend;

require_once '../vendor/sabre/dav/tests/Sabre/HTTP/ResponseMock.php';

class MongoTest extends \PHPUnit_Framework_TestCase {

    protected static $db;
    protected static $userId;

    static function setUpBeforeClass() {
        $mc = new \MongoClient(ESN_MONGO_URI);
        self::$db = $mc->selectDB(ESN_MONGO_ESNDB);

        $salt = str_replace('+', '.', base64_encode("abcdefghijklmnopqrstuv"));
        $pw = crypt('xxx', '$2y$10$'.$salt.'$');

        self::$userId = new \MongoId();
        self::$db->users->insert([
          '_id' => self::$userId,
          'emails' => ['user1@example.com', 'user2@example.com'],
          'password' => $pw
        ]);
    }

    static function tearDownAfterClass() {
        self::$db->drop();
        self::$db = NULL;
    }

    function testConstruct() {
        $mongo = new Mongo(self::$db);
        $this->assertTrue($mongo instanceof Mongo);
    }

    function testLoginPrimary() {
        $backend = new MockMongo(self::$db);
        $this->assertTrue($backend->validateUserPass('user1@example.com', 'xxx'));
        $this->assertEquals($backend->getCurrentUser(), (string)self::$userId);
    }

    function testLoginSecondary() {
        $backend = new MockMongo(self::$db);
        $this->assertTrue($backend->validateUserPass('user2@example.com', 'xxx'));
        $this->assertEquals($backend->getCurrentUser(), (string)self::$userId);
    }

    function testLoginInvalidUser() {
        $backend = new MockMongo(self::$db);
        $this->assertFalse($backend->validateUserPass('user3@example.com', 'xxx'));
        $this->assertNull($backend->getCurrentUser());
    }

    function testLoginInvalidPassword() {
        $backend = new MockMongo(self::$db);
        $this->assertFalse($backend->validateUserPass('user1@example.com', 'yyy'));
        $this->assertNull($backend->getCurrentUser());
    }
}

class MockMongo extends Mongo {
    public function validateUserPass($username, $password) {
        return parent::validateUserPass($username, $password);
    }
}
