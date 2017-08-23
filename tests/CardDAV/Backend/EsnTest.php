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

        $contactAddressBook = array(
            'uri'               => $backend->CONTACTS_URI,
            '{DAV:}displayname' => '',
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => ''
        );

        $collectedAddressBook = array(
            'uri'               => $backend->COLLECTED_URI,
            '{DAV:}displayname' => '',
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => ''
        );

        $this->assertInternalType('array',$books);
        $this->assertEquals(2, count($books));

        $this->checkAddressbook($contactAddressBook, $books[0]);
        $this->checkAddressbook($collectedAddressBook, $books[1]);
    }

    private function checkAddressbook($expected, $item) {
        foreach ($expected as $name => $value) {
            $this->assertArrayHasKey($name, $item);
            $this->assertEquals($value, $item[$name]);
        }
    }
}
