<?php

namespace ESN\CardDAV;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

/**
 * @medium
 */
class AddressBookHomeTest extends \PHPUnit_Framework_TestCase {
    protected $sabredb;
    protected $carddavBackend;

    const USER_ID = '54313fcc398fef406b0041b6';
    const ADMINISTRATOR_ID = '54313fcc398fef406b0041b7';
    const DOMAIN_ID = '54313fcc398fef406b0041b8';

    function setUp() {
        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->sabredb->drop();

        $this->domainPrincipal = 'principals/domains/' . self::DOMAIN_ID;

        $this->principal = [
            'uri' => 'principals/users/' . self::USER_ID,
            'groupPrincipals' => [
                [
                    'uri' => $this->domainPrincipal,
                    'administrators' => [ 'principals/users/' . self::ADMINISTRATOR_ID ],
                    'members' => [
                        'principals/users/' . self::ADMINISTRATOR_ID,
                        'principals/users/' . self::USER_ID
                    ]
                ]
            ]
        ];
        $this->carddavBackend = new \ESN\CardDAV\Backend\Esn($this->sabredb);

        $this->books = new AddressBookHome($this->carddavBackend, $this->principal);
    }

    function testGetChildren() {
        $this->carddavBackend->createAddressBook($this->domainPrincipal, 'GAB', [ '{DAV:}acl' => [ '{DAV:}read' ] ]);

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

        // Check ACL of GAB
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

        $index = array_search('GAB', $childrenNames);
        $this->assertEquals($children[$index]->getACL(), $expectACL);
        $this->assertEquals($children[$index]->getProperties([])['{DAV:}group'], 'principals/domains/' . self::DOMAIN_ID);
    }

    function testGetChildrenWithGroupAdressBookIsDelegated() {
        $createGroupAddressBookId = $this->carddavBackend->createAddressBook($this->domainPrincipal, 'GAB', [ '{DAV:}acl' => [ '{DAV:}read' ] ]);
        $delegatedAddressBookName = 'DelegatedGAB';

        // Share GAB for user
        $this->carddavBackend->updateInvites(
            $createGroupAddressBookId,
            [
                new \Sabre\DAV\Xml\Element\Sharee([
                    'href' => self::USER_ID,
                    'access' => SPlugin::ACCESS_READWRITE,
                    'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                    'properties' => [ '{DAV:}displayname' => $delegatedAddressBookName ],
                    'principal' => 'principals/users/' . self::USER_ID
                ])
            ]
        );

        $children = $this->books->getChildren();
        $this->assertCount(3, $children);

        $childrenNames = [];
        $delegatedAddressBooks = [];

        foreach ($children as $child) {
            $childrenNames[] = $child->getName();

            if ($child instanceof \ESN\CardDAV\Sharing\SharedAddressBook) {
                $delegatedAddressBooks[] = $child;
            }
        }

        // Default address books
        $this->assertContains('collected', $childrenNames);
        $this->assertContains('contacts', $childrenNames);

        // Delegated address book
        $this->assertCount(1, $delegatedAddressBooks);

        $expectACL = [
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/users/' . self::USER_ID,
                'protected' => true
            ]
        ];
        $properties = $delegatedAddressBooks[0]->getProperties([ 'acl', 'share_displayname' ]);

        $this->assertEquals($properties['acl'], $expectACL);
        $this->assertEquals($properties['share_displayname'], $delegatedAddressBookName);
    }

    function testGetChildrenWithDisabledGroupAdressBook() {
        $createGroupAddressBookId = $this->carddavBackend->createAddressBook(
            $this->domainPrincipal,
            'GAB',
            [
                '{DAV:}acl' => [ '{DAV:}read' ],
                '{http://open-paas.org/contacts}state' => 'disabled'
            ]
        );

        $children = $this->books->getChildren();
        $this->assertCount(2, $children);

        $childrenNames = [];

        foreach ($children as $child) {
            $childrenNames[] = $child->getName();
        }

        // Default address books
        $this->assertContains('collected', $childrenNames);
        $this->assertContains('contacts', $childrenNames);
    }

    function testGetChildrenWithSharedDisabledGroupAddressBook() {
        $createGroupAddressBookId = $this->carddavBackend->createAddressBook($this->domainPrincipal, 'GAB', [
            '{DAV:}acl' => [ '{DAV:}read' ],
            '{http://open-paas.org/contacts}state' => 'disabled'
        ]);
        $delegatedAddressBookName = 'DelegatedGAB';

        // Share GAB for user
        $this->carddavBackend->updateInvites(
            $createGroupAddressBookId,
            [
                new \Sabre\DAV\Xml\Element\Sharee([
                    'href' => self::USER_ID,
                    'access' => SPlugin::ACCESS_READWRITE,
                    'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                    'properties' => [ '{DAV:}displayname' => $delegatedAddressBookName ],
                    'principal' => 'principals/users/' . self::USER_ID
                ])
            ]
        );

        $children = $this->books->getChildren();
        $this->assertCount(2, $children);

        $childrenNames = [];
        $delegatedAddressBooks = [];

        foreach ($children as $child) {
            $childrenNames[] = $child->getName();

            if ($child instanceof \ESN\CardDAV\Sharing\SharedAddressBook) {
                $delegatedAddressBooks[] = $child;
            }
        }

        // Default address books
        $this->assertContains('collected', $childrenNames);
        $this->assertContains('contacts', $childrenNames);
    }
}
