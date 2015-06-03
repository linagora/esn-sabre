<?php

namespace ESN\CardDAV\Backend;

require_once 'AbstractDatabaseTest.php';

/**
 * @medium
 */
class MongoTest extends AbstractDatabaseTest {

    function setUp() {
        parent::setUp();

        $book = [
            'principaluri' => 'principals/user1',
            'displayname' => 'book1',
            'uri' => 'book1',
            'description' => 'addressbook 1',
            'synctoken' => 1
        ];
        $this->db->addressbooks->insert($book);

        $this->bookId = (string)$book['_id'];

        $card = [
            'addressbookid' => $book['_id'],
            'carddata' => 'card1',
            'uri' => 'card1',
            'lastmodified' => 0,
            'etag' => '"' . md5('card1') . '"',
            'size' => 5
        ];
        $this->db->cards->insert($card);
        $this->cardId = (string)$card['_id'];

        $card = [
            'addressbookid' => $book['_id'],
            'carddata' => 'card2',
            'uri' => 'card2',
            'lastmodified' => 0,
            'etag' => '"' . md5('card2') . '"',
            'size' => 5
        ];
        $this->db->cards->insert($card);

        $this->cardId2 = (string)$card['_id'];
    }

    protected function generateId() {
        return (string) new \MongoId();
    }

    protected function getBackend() {
        $mcsabre = new \MongoClient(ESN_MONGO_SABREURI);
        $this->db = $mcsabre->selectDB(ESN_MONGO_SABREDB);
        $this->db->drop();
        return new Mongo($this->db);
    }

    function testConstruct() {
        $backend = $this->getBackend();
        $this->assertTrue($backend instanceof Mongo);
    }
}
