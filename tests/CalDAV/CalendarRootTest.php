<?php

namespace ESN\CalDAV;

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

        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);
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
        $this->esndb->communities->insert([ '_id' => new \MongoId('54313fcc398fef406b0041b4') ]);

        $children = $this->root->getChildren();
        $this->assertEquals(2, count($children));

        $user = $children[0];
        $community = $children[1];

        $this->assertTrue($user instanceof \Sabre\CalDAV\CalendarHome);
        $this->assertEquals($user->getName(), '54313fcc398fef406b0041b6');
        $this->assertEquals($user->getOwner(), 'principals/users/54313fcc398fef406b0041b6');

        $this->assertTrue($community instanceof \Sabre\CalDAV\CalendarHome);
        $this->assertEquals($community->getName(), '54313fcc398fef406b0041b4');
        $this->assertEquals($community->getOwner(), 'principals/communities/54313fcc398fef406b0041b4');
    }

    function testGetChild() {
        $this->esndb->users->insert([ '_id' => new \MongoId('54313fcc398fef406b0041b6') ]);
        $this->esndb->communities->insert([ '_id' => new \MongoId('54313fcc398fef406b0041b4') ]);

        $user = $this->root->getChild('54313fcc398fef406b0041b6');
        $this->assertTrue($user instanceof \Sabre\CalDAV\CalendarHome);
        $this->assertEquals($user->getName(), '54313fcc398fef406b0041b6');
        $this->assertEquals($user->getOwner(), 'principals/users/54313fcc398fef406b0041b6');

        $community = $this->root->getChild('54313fcc398fef406b0041b4');
        $this->assertTrue($community instanceof \Sabre\CalDAV\CalendarHome);
        $this->assertEquals($community->getName(), '54313fcc398fef406b0041b4');
        $this->assertEquals($community->getOwner(), 'principals/communities/54313fcc398fef406b0041b4');
    }

    /**
     * @expectedException \Sabre\DAV\Exception\NotFound
     */
    function testGetChildNotFound() {
        $this->root->getChild('54313fcc398fef406b0041b2');
    }
}
