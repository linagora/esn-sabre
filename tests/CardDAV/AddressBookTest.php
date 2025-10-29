<?php

namespace ESN\CardDAV;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

/**
 * @medium
 */
#[\AllowDynamicProperties]
class AddressBookTest extends \PHPUnit\Framework\TestCase {
    protected $sabredb;
    protected $carddavBackend;

    function setUp(): void {
        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->sabredb->drop();

        $this->principalUri = "principals/users/user1";
        $this->carddavBackend = new \ESN\CardDAV\Backend\Mongo($this->sabredb);

        $this->bookId = '556e42ba10771854d5541fef';
        $this->bookInfo = [ 'id' => $this->bookId , 'principaluri' => $this->principalUri ];
        $this->book = new AddressBook($this->carddavBackend, $this->bookInfo);
        $this->cardData = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:hello\r\nEND:VCARD\r\n";
    }

    function testGetChildren() {
        $this->carddavBackend->createAddressBook($this->principalUri, $this->bookId, []);
        $this->carddavBackend->createCard($this->bookId, 'hello.vcf', $this->cardData);
        $children = $this->book->getChildren();

        $this->assertCount(1, $children);
        $this->assertEquals(1, $this->book->getChildCount());
    }

    function testGetChildReadOnlyAddressBook() {
        $this->bookProperties = [ 'id' => $this->bookId , 'principaluri' => $this->principalUri, '{DAV:}acl' => ['dav:read'] ];
        $this->readOnlyBook = new \ESN\CardDAV\AddressBook($this->carddavBackend, $this->bookProperties);
        $this->carddavBackend->createCard($this->bookId, 'hello.vcf', $this->cardData);
        $children = $this->readOnlyBook->getChild('hello.vcf');
        $expectedACL = [
            [
                'privilege' => '{DAV:}read',
                'principal' => "principals/users/user1",
                'protected' => true,
            ]
        ];
        $this->assertEquals($expectedACL, $children->getACL());
    }

    function testGetChildNormalAddressBook() {
        $this->carddavBackend->createCard($this->bookId, 'hello.vcf', $this->cardData);
        $children = $this->book->getChild('hello.vcf');
        $expectedACL = [
            [
                'privilege' => '{DAV:}all',
                'principal' => "principals/users/user1",
                'protected' => true,
            ]
        ];
        $this->assertEquals($expectedACL, $children->getACL());
    }

    function testReadOnlyAddressbookACL() {
        $this->bookInfo = [ 'id' => $this->bookId , 'principaluri' => $this->principalUri, '{DAV:}acl' => ['dav:read'] ];
        $this->readOnlyBook = new \ESN\CardDAV\AddressBook($this->carddavBackend, $this->bookInfo);
        $expectedACL = [
            [
                'privilege' => '{DAV:}read',
                'principal' => "principals/users/user1",
                'protected' => true,
            ]
        ];
        $this->assertEquals($expectedACL, $this->readOnlyBook->getACL());
    }

    function testDefaultAddressbookACL() {
        $this->bookInfo = [ 'id' => $this->bookId , 'principaluri' => $this->principalUri];
        $this->book = new \ESN\CardDAV\AddressBook($this->carddavBackend, $this->bookInfo);
        $expectedACL = [
            [
                'privilege' => '{DAV:}all',
                'principal' => "principals/users/user1",
                'protected' => true,
            ]
        ];
        $this->assertEquals($expectedACL, $this->book->getACL());
    }

    function testGetSubscribedAddressBooks() {
        $bookId = $this->carddavBackend->createAddressBook($this->principalUri, 'book1', [
            '{DAV:}displayname' => 'Test Book',
            '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'Test Description'
        ]);

        $this->bookInfo = [
            'id' => $bookId,
            'uri' => 'book1',
            'principaluri' => 'principals/users/user1'
        ];
        $this->book = new \ESN\CardDAV\AddressBook($this->carddavBackend, $this->bookInfo);

        $this->carddavBackend->updateInvites($bookId, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'principal' => 'principals/users/user2',
                'access' => SPlugin::ACCESS_READ,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                'properties' => []
            ])
        ]);
        $this->carddavBackend->createSubscription(
          'principals/users/user3',
          'subscriptionuri',
          [
              '{http://open-paas.org/contacts}source' => new \Sabre\DAV\Xml\Property\Href('addressbooks/user1/book1', false)
          ]
        );

        $result = $this->book->getSubscribedAddressBooks();

        $this->assertCount(2, $result);
    }
}
