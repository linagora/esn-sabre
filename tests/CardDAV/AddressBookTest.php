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

    private function sharedPublicBookWithCards(int $cardCount) {
        $backend = new CountingCardDAVBackend($this->sabredb);
        $backend->publicRight = '{DAV:}read';
        $backend->sharees = [
            new \Sabre\DAV\Xml\Element\Sharee([
                'principal' => 'principals/users/user2',
                'access' => SPlugin::ACCESS_READWRITE,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED,
            ])
        ];
        for ($i = 0; $i < $cardCount; $i++) {
            $backend->createCard($this->bookId, 'card' . $i . '.vcf', $this->cardData);
        }

        return [new AddressBook($backend, $this->bookInfo), $backend];
    }

    private function assertCardsCarry(array $expectedACL, array $expectedUris, array $children) {
        $this->assertEqualsCanonicalizing($expectedUris, array_map(function ($child) { return $child->getName(); }, $children));
        foreach ($children as $child) {
            $this->assertInstanceOf(\Sabre\CardDAV\Card::class, $child);
            $this->assertEquals($expectedACL, $child->getACL());
        }
    }

    function testGetChildrenSharesTheChildACL() {
        list($book, $backend) = $this->sharedPublicBookWithCards(3);

        $childACL = $book->getChildACL();
        $this->assertContains(['privilege' => '{DAV:}read', 'principal' => '{DAV:}authenticated'], $childACL);
        $this->assertContains(['privilege' => '{DAV:}write-content', 'principal' => 'principals/users/user2', 'protected' => true], $childACL);
        $this->assertCardsCarry($childACL, ['card0.vcf', 'card1.vcf', 'card2.vcf'], $book->getChildren());
    }

    function testGetMultipleChildrenSharesTheChildACL() {
        list($book, $backend) = $this->sharedPublicBookWithCards(3);

        $this->assertCardsCarry(
            $book->getChildACL(),
            ['card0.vcf', 'card2.vcf'],
            $book->getMultipleChildren(['card0.vcf', 'card2.vcf', 'missing.vcf'])
        );
    }

    function testListingCardsReadsTheSharingStateOnce() {
        list($book, $backend) = $this->sharedPublicBookWithCards(20);

        $backend->resetCounters();
        $book->getChildACL();
        $perChildACL = [$backend->getInvitesCalls, $backend->getAddressBookPublicRightCalls];

        $backend->resetCounters();
        $this->assertCount(20, $book->getChildren());
        $this->assertEquals($perChildACL, [$backend->getInvitesCalls, $backend->getAddressBookPublicRightCalls]);

        $backend->resetCounters();
        $this->assertCount(2, $book->getMultipleChildren(['card0.vcf', 'card19.vcf']));
        $this->assertEquals($perChildACL, [$backend->getInvitesCalls, $backend->getAddressBookPublicRightCalls]);
    }

    function testChildExistsDoesNotComputeTheChildACL() {
        list($book, $backend) = $this->sharedPublicBookWithCards(1);

        $backend->resetCounters();
        $this->assertTrue($book->childExists('card0.vcf'));
        $this->assertFalse($book->childExists('missing.vcf'));
        $this->assertEquals(0, $backend->getInvitesCalls);
        $this->assertEquals(0, $backend->getAddressBookPublicRightCalls);
    }

    function testListingAnEmptyAddressBookDoesNotComputeTheChildACL() {
        list($book, $backend) = $this->sharedPublicBookWithCards(0);

        $backend->resetCounters();
        $this->assertSame([], $book->getChildren());
        $this->assertSame([], $book->getMultipleChildren(['missing.vcf']));
        $this->assertEquals(0, $backend->getInvitesCalls);
        $this->assertEquals(0, $backend->getAddressBookPublicRightCalls);
    }
}

class CountingCardDAVBackend extends \ESN\CardDAV\Backend\Mongo {
    public $publicRight = null;
    public $sharees = [];
    public $getInvitesCalls = 0;
    public $getAddressBookPublicRightCalls = 0;

    function resetCounters() {
        $this->getInvitesCalls = 0;
        $this->getAddressBookPublicRightCalls = 0;
    }

    function getInvites($addressBookId) {
        $this->getInvitesCalls++;

        return $this->sharees;
    }

    function getAddressBookPublicRight($addressBookId) {
        $this->getAddressBookPublicRightCalls++;

        return $this->publicRight;
    }
}
