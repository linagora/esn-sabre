<?php

namespace ESN\CardDAV\Backend;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

require_once 'AbstractDatabaseTest.php';

/**
 * @medium
 */
#[\AllowDynamicProperties]
class MongoTest extends AbstractDatabaseTest {

    function setUp(): void {
        parent::setUp();

        $book = [
            'principaluri' => 'principals/users/user1',
            'displayname' => 'book1',
            'uri' => 'book1',
            'description' => 'addressbook 1',
            'privilege' => ['dav:read', 'dav:write'],
            'synctoken' => 1
        ];
        $this->book = $book;

        $book1 = [
            'principaluri' => 'principals/users/user2',
            'displayname' => null,
            'uri' => 'book2',
            'description' => null,
            'type' => 'social',
            'synctoken' => 1
        ];

        $insertResultBook =  $this->db->addressbooks->insertOne($book);
        $insertResultBook1 = $this->db->addressbooks->insertOne($book1);

        $this->bookId = (string) $insertResultBook->getInsertedId();
        $this->missingPropertiesBookId = (string) $insertResultBook1->getInsertedId();

        $card = [
            'addressbookid' => new \MongoDB\BSON\ObjectId($this->bookId),
            'carddata' => 'card1',
            'uri' => 'card1',
            'lastmodified' => 0,
            'etag' => '"' . md5('card1') . '"',
            'size' => 5
        ];
        $insertResultCard = $this->db->cards->insertOne($card);
        $this->cardId = (string) $insertResultCard->getInsertedId();

        $card = [
            'addressbookid' => new \MongoDB\BSON\ObjectId($this->bookId),
            'carddata' => 'card2',
            'uri' => 'card2',
            'lastmodified' => 0,
            'etag' => '"' . md5('card2') . '"',
            'size' => 5
        ];
        $insertResultCard2 = $this->db->cards->insertOne($card);

        $this->cardId2 = (string) $insertResultCard2->getInsertedId();
    }

    protected function generateId() {
        return (string) new \MongoDB\BSON\ObjectId();
    }

    protected function getBackend() {
        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->db = $mcsabre->{ESN_MONGO_SABREDB};
        $this->db->drop();
        return new Mongo($this->db);
    }

    function testConstruct() {
        $backend = $this->getBackend();
        $this->assertTrue($backend instanceof Mongo);
    }

    function testAddressBookExists() {
        $book = [
            'principaluri' => 'principals/users/user2',
            'displayname' => null,
            'uri' => 'thisoneexists',
            'description' => null,
            'synctoken' => 1
        ];

        $this->db->addressbooks->insertOne($book);

        $this->assertTrue($this->backend->addressBookExists('principals/users/user2', 'thisoneexists'));
        $this->assertFalse($this->backend->addressBookExists('principals/users/user2', 'thisonedoesnotexistsatall'));
    }

    function testGetCards() {
        $result = $this->backend->getCards($this->bookId);

        $expected = array(
            array(
                'id' => $this->cardId,
                'uri' => 'card1',
                'lastmodified' => 0,
                'etag' => '"' . md5('card1') . '"',
                'size' => 5
            ),
            array(
                'id' => $this->cardId2,
                'uri' => 'card2',
                'lastmodified' => 0,
                'etag' => '"' . md5('card2') . '"',
                'size' => 5
            )
        );

        $this->assertEquals($expected, $result);
    }

    function testGetCardsSortLimitOffset() {
        $result = $this->backend->getCards($this->bookId, 1, 1, 'fn');

        $expected = array(
            array(
                'id' => $this->cardId2,
                'uri' => 'card2',
                'lastmodified' => 0,
                'etag' => '"' . md5('card2') . '"',
                'size' => 5
            )
        );

        $this->assertEquals($expected, $result);
    }

    function testGetCardsFilters() {
        $backend = $this->getBackend();
        $cardUpToDate = [
            'addressbookid' => new \MongoDB\BSON\ObjectId($this->bookId),
            'carddata' => 'cardUpToDate',
            'uri' => 'cardUpToDate',
            'lastmodified' => 100,
            'etag' => '"' . md5('cardUpToDate') . '"',
            'size' => 5
        ];
        $this->db->cards->insertOne($cardUpToDate);

        $cardOutdated1 = [
            'addressbookid' => new \MongoDB\BSON\ObjectId($this->bookId),
            'carddata' => 'cardOutdated1',
            'uri' => 'cardOutdated1',
            'lastmodified' => 99,
            'etag' => '"' . md5('cardOutdated1') . '"',
            'size' => 5
        ];
        $insertResultCard1 = $this->db->cards->insertOne($cardOutdated1);

        $cardOutdated2 = [
            'addressbookid' => new \MongoDB\BSON\ObjectId($this->bookId),
            'carddata' => 'cardOutdated2',
            'uri' => 'cardOutdated2',
            'lastmodified' => 99,
            'etag' => '"' . md5('cardOutdated2') . '"',
            'size' => 5
        ];
        $insertResultCard2 = $this->db->cards->insertOne($cardOutdated2);
        $filters = [
            'modifiedBefore' => 100
        ];
        $result = $backend->getCards($this->bookId, 0, 0, null, $filters);

        $expected = array(
            array(
                'id' => (string) $insertResultCard1->getInsertedId(),
                'uri' => 'cardOutdated1',
                'lastmodified' => 99,
                'etag' => '"' . md5('cardOutdated1') . '"',
                'size' => 5
            ),
            array(
                'id' => (string) $insertResultCard2->getInsertedId(),
                'uri' => 'cardOutdated2',
                'lastmodified' => 99,
                'etag' => '"' . md5('cardOutdated2') . '"',
                'size' => 5
            )
        );

        $this->assertEquals($expected, $result);
    }

    function testGetSharedAddressBooksBySource() {
        $backend = $this->getBackend();

        $addressBookId = $backend->createAddressBook('principals/users/user1', 'addressbook1', []);
        $backend->updateInvites($addressBookId, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'principal' => 'principals/users/user2',
                'href' => 'mailto:user1@op.co',
                'access' => SPlugin::ACCESS_READ,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                'properties' => []
            ])
        ]);
        $sharedAddressBooks = $backend->getSharedAddressBooksBySource($addressBookId);

        $this->assertEquals(1, count($sharedAddressBooks));
        $this->assertEquals('principals/users/user2', $sharedAddressBooks[0]['principaluri']);
    }
}
