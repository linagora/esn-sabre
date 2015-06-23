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

    function setUp() {
        $mcesn = new \MongoClient(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->selectDB(ESN_MONGO_ESNDB);

        $mcsabre = new \MongoClient(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->selectDB(ESN_MONGO_SABREDB);

        $this->esndb->drop();
        $this->sabredb->drop();

        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);
        $this->carddavBackend = new \ESN\CardDAV\Backend\Mongo($this->sabredb);

        $this->root = new AddressBookRoot($this->principalBackend,
                                          $this->carddavBackend, $this->esndb);
    }

    function testConstruct() {
        $this->assertTrue($this->root instanceof AddressBookRoot);
        $this->assertTrue($this->root instanceof \Sabre\DAV\Collection);
        $this->assertEquals('addressbooks', $this->root->getName());
    }

    function testChildren() {
        $this->esndb->users->insert([ '_id' => new \MongoId('54313fcc398fef406b0041b6') ]);
        $this->esndb->communities->insert([ '_id' => new \MongoId('54313fcc398fef406b0041b4') ]);
        $this->esndb->projects->insert([ '_id' => new \MongoId('54b64eadf6d7d8e41d263e0f') ]);

        $children = $this->root->getChildren();
        $this->assertEquals(3, count($children));

        $user = $children[0];
        $community = $children[1];
        $project = $children[2];

        $this->assertTrue($user instanceof \Sabre\CardDAV\AddressBookHome);
        $this->assertEquals($user->getName(), '54313fcc398fef406b0041b6');
        $this->assertEquals($user->getOwner(), 'principals/users/54313fcc398fef406b0041b6');

        $this->assertTrue($community instanceof \Sabre\CardDAV\AddressBookHome);
        $this->assertEquals($community->getName(), '54313fcc398fef406b0041b4');
        $this->assertEquals($community->getOwner(), 'principals/communities/54313fcc398fef406b0041b4');

        $this->assertTrue($project instanceof \Sabre\CardDAV\AddressBookHome);
        $this->assertEquals($project->getName(), '54b64eadf6d7d8e41d263e0f');
        $this->assertEquals($project->getOwner(), 'principals/projects/54b64eadf6d7d8e41d263e0f');
    }

    function testGetChild() {
        $this->esndb->users->insert([ '_id' => new \MongoId('54313fcc398fef406b0041b6') ]);
        $this->esndb->communities->insert([ '_id' => new \MongoId('54313fcc398fef406b0041b4') ]);
        $this->esndb->projects->insert([ '_id' => new \MongoId('54b64eadf6d7d8e41d263e0f') ]);

        $user = $this->root->getChild('54313fcc398fef406b0041b6');
        $this->assertTrue($user instanceof \Sabre\CardDAV\AddressBookHome);
        $this->assertEquals($user->getName(), '54313fcc398fef406b0041b6');
        $this->assertEquals($user->getOwner(), 'principals/users/54313fcc398fef406b0041b6');

        $community = $this->root->getChild('54313fcc398fef406b0041b4');
        $this->assertTrue($community instanceof \Sabre\CardDAV\AddressBookHome);
        $this->assertEquals($community->getName(), '54313fcc398fef406b0041b4');
        $this->assertEquals($community->getOwner(), 'principals/communities/54313fcc398fef406b0041b4');

        $project = $this->root->getChild('54b64eadf6d7d8e41d263e0f');
        $this->assertTrue($project instanceof \Sabre\CardDAV\AddressBookHome);
        $this->assertEquals($project->getName(), '54b64eadf6d7d8e41d263e0f');
        $this->assertEquals($project->getOwner(), 'principals/projects/54b64eadf6d7d8e41d263e0f');
    }

    /**
     * @expectedException \Sabre\DAV\Exception\NotFound
     */
    function testGetChildNotFound() {
        $this->root->getChild('54313fcc398fef406b0041b2');
    }
}

