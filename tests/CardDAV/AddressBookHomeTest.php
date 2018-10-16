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

        $this->domainPrincipal = 'principals/domains/domain';

        $this->principal = [
            'uri' => 'principals/users/user',
            'groupPrincipals' => [$this->domainPrincipal]
        ];
        $this->carddavBackend = new \ESN\CardDAV\Backend\Esn($this->sabredb);

        $this->books = new AddressBookHome($this->carddavBackend, $this->principal);
    }

    function testGetChildren() {
        $this->carddavBackend->createAddressBook($this->domainPrincipal, 'GAB', []);

        $children = $this->books->getChildren();
        $this->assertCount(3, $children);
        $this->assertInstanceOf('\ESN\CardDAV\AddressBook', $children[0]);
        $this->assertInstanceOf('\ESN\CardDAV\AddressBook', $children[1]);
        $this->assertInstanceOf('\ESN\CardDAV\AddressBook', $children[2]);

        $childrenNames = [];
        foreach ($children as $child) {
            $childrenNames[] = $child->getName();
        }

        // Default address books
        $this->assertContains('collected', $childrenNames);
        $this->assertContains('contacts', $childrenNames);

        // Domain address book
        $this->assertContains('GAB', $childrenNames);
    }
}
