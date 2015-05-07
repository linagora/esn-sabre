<?php

namespace ESN\CardDAV\Backend;

/**
 * @medium
 */
class EsnTest extends \PHPUnit_Framework_TestCase {
    protected function getBackend() {
        $mc = new \MongoClient(ESN_MONGO_SABREURI);
        $db = $mc->selectDB(ESN_MONGO_SABREDB);
        $db->drop();
        return new Esn($db);
    }

    function testGetAddressBooksForUserNoAddressBooks() {
        $backend = $this->getBackend();
        $books = $backend->getAddressBooksForUser('principals/user2');

        $elementCheck = array(
            'uri'               => $backend->CONTACTS_URI,
            '{DAV:}displayname' => '',
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => ''
        );

        $this->assertInternalType('array',$books);
        $this->assertEquals(1,count($books));

        foreach ($elementCheck as $name => $value) {
            $this->assertArrayHasKey($name, $books[0]);
            $this->assertEquals($value,$books[0][$name]);
        }
    }
}
