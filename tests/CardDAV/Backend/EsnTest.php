<?php

namespace ESN\CardDAV\Backend;

/**
 * @medium
 */
class EsnTest extends \PHPUnit\Framework\TestCase {
    protected function getBackend() {
        $mc = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $db = $mc->{ESN_MONGO_SABREDB};
        $db->drop();
        return new Esn($db);
    }

    function testGetAddressBooksForUserNoAddressBooks() {
        $backend = $this->getBackend();
        $books = $backend->getAddressBooksForUser('principals/users/user2');

        $contactAddressBook = array(
            'uri'               => $backend::CONTACTS_URI,
            '{DAV:}displayname' => '',
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => ''
        );

        $collectedAddressBook = array(
            'uri'               => $backend::COLLECTED_URI,
            '{DAV:}displayname' => '',
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => ''
        );

        $this->assertInternalType('array',$books);
        $this->assertEquals(2, count($books));

        $this->checkAddressbook($collectedAddressBook, $books[0]);
        $this->checkAddressbook($contactAddressBook, $books[1]);
    }

    function testGetAddressBooksForUserWhenOtherThanDefaultExists() {
        $backend = $this->getBackend();

        $backend->createAddressBook('principals/users/user2', 'anotheraaddressbook', []);

        $anotherAddressBook = array(
            'uri'               => 'anotheraaddressbook',
            '{DAV:}displayname' => '',
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => ''
        );

        $contactAddressBook = array(
            'uri'               => $backend::CONTACTS_URI,
            '{DAV:}displayname' => '',
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => ''
        );

        $collectedAddressBook = array(
            'uri'               => $backend::COLLECTED_URI,
            '{DAV:}displayname' => '',
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => ''
        );

        $books = $backend->getAddressBooksForUser('principals/users/user2');

        $this->assertInternalType('array',$books);
        $this->assertEquals(3, count($books));

        $this->checkAddressbook($anotherAddressBook, $books[0]);
        $this->checkAddressbook($collectedAddressBook, $books[1]);
        $this->checkAddressbook($contactAddressBook, $books[2]);
    }

    private function checkAddressbook($expected, $item) {
        foreach ($expected as $name => $value) {
            $this->assertArrayHasKey($name, $item);
            $this->assertEquals($value, $item[$name]);
        }
    }
}
