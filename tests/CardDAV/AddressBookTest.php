<?php

namespace ESN\CardDAV;

/**
 * @medium
 */
class AddressBookTest extends \PHPUnit_Framework_TestCase {
    protected $sabredb;
    protected $carddavBackend;

    function setUp() {
        $mcsabre = new \MongoClient(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->selectDB(ESN_MONGO_SABREDB);

        $this->sabredb->drop();

        $this->principalUri = "principals/user1";
        $this->carddavBackend = new \ESN\CardDAV\Backend\Mongo($this->sabredb);

        $this->bookId = '556e42ba10771854d5541fef';
        $this->bookInfo = [ 'id' => $this->bookId , 'principaluri' => $this->principalUri ];
        $this->book = new AddressBook($this->carddavBackend, $this->bookInfo);
    }

    function testGetChildren() {
        $this->carddavBackend->createAddressBook($this->principalUri, $this->bookId, []);
        $this->carddavBackend->createCard($this->bookId, 'hello.vcf', "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:hello\r\nEND:VCARD\r\n");
        $children = $this->book->getChildren();

        $this->assertCount(1, $children);
        $this->assertEquals(1, $this->book->getChildCount());
    }

    function testReadOnlyAddressbookACL() {
        $this->bookInfo = [ 'id' => $this->bookId , 'principaluri' => $this->principalUri, 'privilege' => 'read-only' ];
        $this->readOnlyBook = new \ESN\CardDAV\AddressBook($this->carddavBackend, $this->bookInfo);
        $expectedACL = [
            [
                'privilege' => '{DAV:}read',
                'principal' => "principals/user1",
                'protected' => true,
            ]
        ];
        $this->assertEquals($expectedACL, $this->readOnlyBook->getACL());
    }

    function testDefaultAddressbookACL() {
        $this->bookInfo = [ 'id' => $this->bookId , 'principaluri' => $this->principalUri];
        $this->book = new \ESN\CardDAV\AddressBook($this->carddavBackend, $this->bookInfo);
        $expectedACL = [
            [
                'privilege' => '{DAV:}read',
                'principal' => "principals/user1",
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => "principals/user1",
                'protected' => true,
            ]
        ];
        $this->assertEquals($expectedACL, $this->book->getACL());
    }
}
