<?php

namespace ESN\CardDAV;

/**
 * @medium
 */
class AddressBookHomeTest extends \PHPUnit_Framework_TestCase {
    protected $sabredb;
    protected $carddavBackend;

    function setUp() {
        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->sabredb->drop();

        $this->principalUri = "principals/users/user1";
        $this->carddavBackend = new \ESN\CardDAV\Backend\Mongo($this->sabredb);

        $this->books = new AddressBookHome($this->carddavBackend, $this->principalUri);
    }

    function testGetChildren() {
        $this->carddavBackend->createAddressBook($this->principalUri, 'book1', []);

        $children = $this->books->getChildren();
        $this->assertCount(1, $children);
        $this->assertInstanceOf('\ESN\CardDAV\AddressBook', $children[0]);
    }
}
