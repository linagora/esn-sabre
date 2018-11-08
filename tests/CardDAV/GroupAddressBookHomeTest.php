<?php

namespace ESN\CardDAV;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

/**
 * @medium
 */
class GroupAddressBookHomeTest extends \PHPUnit_Framework_TestCase {
    protected $sabredb;
    protected $carddavBackend;

    const ADMINISTRATOR_ID = '54313fcc398fef406b0041b7';
    const DOMAIN_ID = '54313fcc398fef406b0041b8';

    function setUp() {
        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->sabredb->drop();
        $this->carddavBackend = new \ESN\CardDAV\Backend\Esn($this->sabredb);

        $this->books = new GroupAddressBookHome($this->carddavBackend, [
            'uri' => 'principals/domains/' . self::DOMAIN_ID,
            'administrators' => [ 'principals/users/' . self::ADMINISTRATOR_ID ],
            'members' => [ 'principals/users/' . self::ADMINISTRATOR_ID ]
        ]);
    }

    function testGetChildren() {
        $this->carddavBackend->createAddressBook(
            'principals/domains/' . self::DOMAIN_ID,
            'GAB',
            [ '{DAV:}acl' => [ '{DAV:}read' ] ]
        );

        $children = $this->books->getChildren();
        $this->assertInstanceOf('\ESN\CardDAV\Group\GroupAddressBook', $children[0]);
    }
}
