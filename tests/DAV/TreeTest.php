<?php

namespace ESN\DAV;

class TreeTest extends \PHPUnit\Framework\TestCase {

    private $collection;
    private $tree;

    function setUp(): void {
        $this->collection = new CountingCollection('calendar');
        $this->collection->addChild(new DeletableFile('event.ics', $this->collection));
        $this->tree = new Tree(new \Sabre\DAV\SimpleCollection('root', [$this->collection]));
    }

    function testNodeExistsShouldFindAnExistingNode() {
        $this->assertTrue($this->tree->nodeExists('calendar/event.ics'));
    }

    function testNodeExistsShouldNotFindAMissingNode() {
        $this->assertFalse($this->tree->nodeExists('calendar/missing.ics'));
    }

    function testNodeExistsShouldNotFindAChildOfAFile() {
        $this->assertFalse($this->tree->nodeExists('calendar/event.ics/child'));
    }

    function testNodeExistsShouldFindTheRoot() {
        $this->assertTrue($this->tree->nodeExists(''));
    }

    function testNodeExistsShouldCacheTheNodeItResolves() {
        $this->tree->nodeExists('calendar/event.ics');
        $this->tree->nodeExists('/calendar/event.ics');
        $this->tree->getNodeForPath('calendar/event.ics');

        $this->assertSame(1, $this->collection->lookups);
    }

    function testNodeExistsShouldNotCacheAMissingNode() {
        $this->tree->nodeExists('calendar/missing.ics');
        $this->collection->createFile('missing.ics', 'data');

        $this->assertTrue($this->tree->nodeExists('calendar/missing.ics'));
    }

    function testNodeExistsShouldSeeADeletionThroughTheTree() {
        $this->tree->nodeExists('calendar/event.ics');
        $this->tree->delete('calendar/event.ics');

        $this->assertFalse($this->tree->nodeExists('calendar/event.ics'));
    }
}

class CountingCollection extends \Sabre\DAV\SimpleCollection {
    public $lookups = 0;

    function getChild($name) {
        $this->lookups++;

        return parent::getChild($name);
    }

    function createFile($name, $data = null) {
        $this->addChild(new \Sabre\DAV\SimpleFile($name, $data));
    }

    function removeChild($name) {
        unset($this->children[$name]);
    }
}

class DeletableFile extends \Sabre\DAV\SimpleFile {
    private $parent;

    function __construct($name, CountingCollection $parent) {
        parent::__construct($name, 'data');
        $this->parent = $parent;
    }

    function delete() {
        $this->parent->removeChild($this->getName());
    }
}
