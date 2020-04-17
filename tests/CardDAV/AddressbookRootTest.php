<?php

namespace ESN\CardDAV;

/**
 * @medium
 */
class AddressbookRootTest extends \PHPUnit_Framework_TestCase {
    protected $esndb;
    protected $sabredb;
    protected $principalBackend;
    protected $carddavBackend;

    const DOMAIN_ID = '5a095e2c46b72521d03f6d75';
    const USER_ID = '54313fcc398fef406b0041b6';
    const ADMINISTRATOR_ID = '54313fcc398fef406b0041b7';

    function setUp() {
        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->{ESN_MONGO_ESNDB};

        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->esndb->drop();
        $this->sabredb->drop();

        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\EsnRequest($this->esndb);
        $this->carddavBackend = new \ESN\CardDAV\Backend\Mongo($this->sabredb);

        $this->root = new AddressBookRoot($this->principalBackend, $this->carddavBackend);

        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER_ID),
            'domains' => []
        ]);
        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID),
            'administrators' => [
                [
                    'user_id' => self::ADMINISTRATOR_ID
                ]
            ]
        ]);
    }

    function testConstruct() {
        $this->assertTrue($this->root instanceof AddressBookRoot);
        $this->assertTrue($this->root instanceof \Sabre\DAV\Collection);
        $this->assertEquals('addressbooks', $this->root->getName());
    }

    function testChildren() {
        $children = $this->root->getChildren();
        $this->assertEquals(2, count($children));

        $user = $children[0];
        $domain = $children[1];

        $this->checkUser($user);

        $this->checkDomain($domain);
    }

    function testGetChild() {
        $user = $this->root->getChild('54313fcc398fef406b0041b6');
        $this->checkUser($user);

        $domain = $this->root->getChild(self::DOMAIN_ID);
        $this->checkDomain($domain);

        $invalid = $this->root->getChild('not_a_mongo_id');
        $this->assertNull($invalid);
    }

    function checkUser($user) {
        $this->assertTrue($user instanceof \Sabre\CardDAV\AddressBookHome);
        $this->assertEquals($user->getName(), '54313fcc398fef406b0041b6');
        $this->assertEquals($user->getOwner(), 'principals/users/54313fcc398fef406b0041b6');
    }

    function checkDomain($domain) {
        $this->assertTrue($domain instanceof \Sabre\CardDAV\AddressBookHome);
        $this->assertEquals($domain->getName(), self::DOMAIN_ID);
        $this->assertEquals($domain->getOwner(), 'principals/domains/'.self::DOMAIN_ID);
        $this->assertEquals($domain->getACL(), [
            [
                'privilege' => '{DAV:}read',
                'principal' => '{DAV:}owner',
                'protected' => true
            ],
            [
                'privilege' => '{DAV:}all',
                'principal' => 'principals/users/' . self::ADMINISTRATOR_ID,
                'protected' => true
            ]
        ]);
    }

    /**
     * @expectedException \Sabre\DAV\Exception\NotFound
     */
    function testGetChildNotFound() {
        $this->root->getChild('000011110000111100001111');
    }
}

