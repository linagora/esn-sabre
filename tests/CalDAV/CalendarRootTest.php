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
        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->{ESN_MONGO_ESNDB};

        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

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
        $this->esndb->users->insertOne([ '_id' => new \MongoDB\BSON\ObjectId('54313fcc398fef406b0041b6') ]);
        $this->esndb->resources->insertOne([ '_id' => new \MongoDB\BSON\ObjectId('82113fcc398fef406b0041b7') ]);

        $children = $this->root->getChildren();
        $this->assertEquals(2, count($children));

        $user = $children[0];
        $resource = $children[1];

        $this->assertTrue($user instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($user->getName(), '54313fcc398fef406b0041b6');
        $this->assertEquals($user->getOwner(), 'principals/users/54313fcc398fef406b0041b6');

        $this->assertTrue($resource instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($resource->getName(), '82113fcc398fef406b0041b7');
        $this->assertEquals($resource->getOwner(), 'principals/resources/82113fcc398fef406b0041b7');
    }

    function testGetChild() {
        $this->esndb->users->insertOne([ '_id' => new \MongoDB\BSON\ObjectId('54313fcc398fef406b0041b6') ]);

        $user = $this->root->getChild('54313fcc398fef406b0041b6');
        $this->assertTrue($user instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($user->getName(), '54313fcc398fef406b0041b6');
        $this->assertEquals($user->getOwner(), 'principals/users/54313fcc398fef406b0041b6');

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
