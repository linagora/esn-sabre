<?php

namespace ESN\DAV\Auth\Backend;

require_once ESN_TEST_BASE . '/Sabre/HTTP/ResponseMock.php';

class MongoTest extends \PHPUnit\Framework\TestCase {

    const PRINCIPAL_PREFIX = "principals/users";

    protected static $db;
    protected static $userPrincipal;

    static function setUpBeforeClass() {
        $mc = new \MongoDB\Client(ESN_MONGO_ESNURI);
        self::$db = $mc->{ESN_MONGO_ESNDB};

        $salt = str_replace('+', '.', base64_encode("abcdefghijklmnopqrstuv"));
        $pw = crypt('xxx', '$2y$10$'.$salt.'$');

        $userId = new \MongoDB\BSON\ObjectId();
        self::$db->users->insertOne([
          '_id' => $userId,
          'accounts' => [
            [ 'type' => 'email', 'emails' => ['user1@example.com', 'user2@example.com'] ]
          ],
          'password' => $pw
        ]);
        self::$userPrincipal = "principals/users/" . (string)$userId;
    }

    static function tearDownAfterClass() {
        self::$db->drop();
        self::$db = null;
    }

    function testConstruct() {
        $mongo = new Mongo(self::$db);
        $this->assertTrue($mongo instanceof Mongo);
    }

    function testLoginPrimary() {
        $backend = new MockMongo(self::$db);
        $this->assertTrue($backend->validateUserPass('user1@example.com', 'xxx'));
        $this->assertEquals($backend->getCurrentPrincipal(), self::$userPrincipal);
    }

    function testLoginSecondary() {
        $backend = new MockMongo(self::$db);
        $this->assertTrue($backend->validateUserPass('user2@example.com', 'xxx'));
        $this->assertEquals($backend->getCurrentPrincipal(), self::$userPrincipal);
    }

    function testLoginInvalidUser() {
        $backend = new MockMongo(self::$db);
        $this->assertFalse($backend->validateUserPass('user3@example.com', 'xxx'));
        $this->assertNull($backend->getCurrentPrincipal());
    }

    function testLoginInvalidPassword() {
        $backend = new MockMongo(self::$db);
        $this->assertFalse($backend->validateUserPass('user1@example.com', 'yyy'));
        $this->assertNull($backend->getCurrentPrincipal());
    }
}

class MockMongo extends Mongo {
    public function validateUserPass($username, $password) {
        return parent::validateUserPass($username, $password);
    }
}
