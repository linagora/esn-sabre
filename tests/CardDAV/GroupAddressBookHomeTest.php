<?php

namespace ESN\CardDAV;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

/**
 * @medium
 */
class GroupAddressBookHomeTest extends \PHPUnit\Framework\TestCase {
    protected $sabredb;
    protected $carddavBackend;

    const ADMINISTRATOR_ID = '54313fcc398fef406b0041b7';
    const DOMAIN_ID = '54313fcc398fef406b0041b8';
    const USER_ID = '54313fcc398fef406b0041c9';

    function setUp() {
        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->sabredb->drop();
        $this->carddavBackend = new \ESN\CardDAV\Backend\Esn($this->sabredb);

        $this->books = new GroupAddressBookHome($this->carddavBackend, [
            'uri' => 'principals/domains/' . self::DOMAIN_ID,
            'administrators' => [ 'principals/users/' . self::ADMINISTRATOR_ID ],
            'members' => [
                'principals/users/' . self::ADMINISTRATOR_ID,
                'principals/users/' . self::USER_ID
            ]
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

        $expectACL = [
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/users/' . self::USER_ID,
                'protected' => true
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/users/' . self::ADMINISTRATOR_ID,
                'protected' => true
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => 'principals/users/' . self::ADMINISTRATOR_ID,
                'protected' => true
            ],
            [
                'privilege' => '{DAV:}share',
                'principal' => 'principals/users/' . self::ADMINISTRATOR_ID,
                'protected' => true
            ]
        ];
        $this->assertEquals($children[0]->getACL(), $expectACL);
    }
}
