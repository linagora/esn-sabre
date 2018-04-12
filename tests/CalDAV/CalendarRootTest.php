<?php

namespace ESN\CalDAV;

/**
 * @medium
 */
class CalendarRootTest extends \PHPUnit_Framework_TestCase {
    protected $esndb;
    protected $sabredb;
    protected $principalBackend;
    protected $caldavBackend;

    function setUp() {
        $mcesn = new \MongoClient(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->selectDB(ESN_MONGO_ESNDB);

        $mcsabre = new \MongoClient(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->selectDB(ESN_MONGO_SABREDB);

        $this->esndb->drop();
        $this->sabredb->drop();

        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\EsnRequest($this->esndb);
        $this->caldavBackend = new \ESN\CalDAV\Backend\Mongo($this->sabredb);

        $this->root = new CalendarRoot($this->principalBackend,
                                       $this->caldavBackend, $this->esndb);
    }

    function testConstruct() {
        $this->assertTrue($this->root instanceof CalendarRoot);
        $this->assertTrue($this->root instanceof \Sabre\DAV\Collection);
        $this->assertEquals('calendars', $this->root->getName());
    }

    function testChildren() {
        $this->esndb->users->insert([ '_id' => new \MongoId('54313fcc398fef406b0041b6') ]);
        //$this->esndb->communities->insert([ '_id' => new \MongoId('54313fcc398fef406b0041b4') ]);
        $this->esndb->projects->insert([ '_id' => new \MongoId('54b64eadf6d7d8e41d263e0f') ]);
        $this->esndb->resources->insert([ '_id' => new \MongoId('82113fcc398fef406b0041b7') ]);

        $children = $this->root->getChildren();
        $this->assertEquals(3, count($children));

        $user = $children[0];
        //$community = $children[1];
        $project = $children[1];
        $resource = $children[2];

        $this->assertTrue($user instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($user->getName(), '54313fcc398fef406b0041b6');
        $this->assertEquals($user->getOwner(), 'principals/users/54313fcc398fef406b0041b6');

        //@Chamerling Here to reactivate the fetch of communities calendar
        /*$this->assertTrue($community instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($community->getName(), '54313fcc398fef406b0041b4');
        $this->assertEquals($community->getOwner(), 'principals/communities/54313fcc398fef406b0041b4');*/

        $this->assertTrue($project instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($project->getName(), '54b64eadf6d7d8e41d263e0f');
        $this->assertEquals($project->getOwner(), 'principals/projects/54b64eadf6d7d8e41d263e0f');

        $this->assertTrue($resource instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($resource->getName(), '82113fcc398fef406b0041b7');
        $this->assertEquals($resource->getOwner(), 'principals/resources/82113fcc398fef406b0041b7');
    }

    function testGetChild() {
        $this->esndb->users->insert([ '_id' => new \MongoId('54313fcc398fef406b0041b6') ]);
        $this->esndb->communities->insert([ '_id' => new \MongoId('54313fcc398fef406b0041b4') ]);
        $this->esndb->projects->insert([ '_id' => new \MongoId('54b64eadf6d7d8e41d263e0f') ]);

        $user = $this->root->getChild('54313fcc398fef406b0041b6');
        $this->assertTrue($user instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($user->getName(), '54313fcc398fef406b0041b6');
        $this->assertEquals($user->getOwner(), 'principals/users/54313fcc398fef406b0041b6');

        //@Chamerling Here to reactivate the fetch of communities calendar
        /*$community = $this->root->getChild('54313fcc398fef406b0041b4');
        $this->assertTrue($community instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($community->getName(), '54313fcc398fef406b0041b4');
        $this->assertEquals($community->getOwner(), 'principals/communities/54313fcc398fef406b0041b4');*/

        $project = $this->root->getChild('54b64eadf6d7d8e41d263e0f');
        $this->assertTrue($project instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($project->getName(), '54b64eadf6d7d8e41d263e0f');
        $this->assertEquals($project->getOwner(), 'principals/projects/54b64eadf6d7d8e41d263e0f');

        $invalid = $this->root->getChild('not_a_mongo_id');
        $this->assertNull($invalid);
    }

    /**
     * @expectedException \Sabre\DAV\Exception\NotFound
     */
    function testGetChildNotFound() {
        $this->root->getChild('54313fcc398fef406b0041b2');
    }
}
