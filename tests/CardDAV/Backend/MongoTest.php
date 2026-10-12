<?php

namespace ESN\CardDAV\Backend;

use \Sabre\DAV\Sharing\Plugin as SPlugin;
use PHPUnit\Framework\Attributes\DataProvider;

require_once 'AbstractDatabaseTestBase.php';
require_once ESN_TEST_BASE . '/DAV/MongoFindCounter.php';

/**
 * @medium
 */
#[\AllowDynamicProperties]
class MongoTest extends AbstractDatabaseTestBase {

    function setUp(): void {
        parent::setUp();

        $book = [
            'principaluri' => 'principals/users/user1',
            'displayname' => 'book1',
            'uri' => 'book1',
            'description' => 'addressbook 1',
            'privilege' => ['dav:read', 'dav:write'],
            'synctoken' => 1
        ];
        $this->book = $book;

        $book1 = [
            'principaluri' => 'principals/users/user2',
            'displayname' => null,
            'uri' => 'book2',
            'description' => null,
            'type' => 'social',
            'synctoken' => 1
        ];

        $insertResultBook =  $this->db->addressbooks->insertOne($book);
        $insertResultBook1 = $this->db->addressbooks->insertOne($book1);

        $this->bookId = (string) $insertResultBook->getInsertedId();
        $this->missingPropertiesBookId = (string) $insertResultBook1->getInsertedId();

        $card = [
            'addressbookid' => new \MongoDB\BSON\ObjectId($this->bookId),
            'carddata' => 'card1',
            'uri' => 'card1',
            'lastmodified' => 0,
            'etag' => '"' . md5('card1') . '"',
            'size' => 5
        ];
        $insertResultCard = $this->db->cards->insertOne($card);
        $this->cardId = (string) $insertResultCard->getInsertedId();

        $card = [
            'addressbookid' => new \MongoDB\BSON\ObjectId($this->bookId),
            'carddata' => 'card2',
            'uri' => 'card2',
            'lastmodified' => 0,
            'etag' => '"' . md5('card2') . '"',
            'size' => 5
        ];
        $insertResultCard2 = $this->db->cards->insertOne($card);

        $this->cardId2 = (string) $insertResultCard2->getInsertedId();
    }

    protected function generateId() {
        return (string) new \MongoDB\BSON\ObjectId();
    }

    protected function getBackend() {
        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->db = $mcsabre->{ESN_MONGO_SABREDB};
        $this->db->drop();
        return new Mongo($this->db);
    }

    function testGetMultipleCardsQueriesByBatches() {
        $backend = $this->getBackend();
        // Just over one batch
        $count = Mongo::MULTIGET_BATCH_SIZE + 10;
        $uris = [];
        for ($i = 0; $i < $count; $i++) {
            $uris[] = 'card' . $i . '.vcf';
            $backend->createCard($this->bookId, 'card' . $i . '.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Card " . $i . "\r\nEND:VCARD\r\n");
        }
        $uris[] = 'missing.vcf';

        $queries = \ESN\DAV\MongoFindCounter::count('cards', function () use ($backend, $uris, &$cards) {
            $cards = $backend->getMultipleCards($this->bookId, $uris);
        });

        $this->assertEquals(2, $queries);
        $this->assertEqualsCanonicalizing(array_slice($uris, 0, $count), array_column($cards, 'uri'));
    }

    function testResubscriptionDoesNotRestoreRevokedDelegation() {
        $principal = 'principals/users/user2';
        $sharee = new \Sabre\DAV\Xml\Element\Sharee([
            'href' => 'mailto:user2@example.org',
            'principal' => $principal,
            'access' => SPlugin::ACCESS_READWRITE,
            'inviteStatus' => SPlugin::INVITE_ACCEPTED
        ]);
        $this->backend->updateInvites($this->bookId, [$sharee]);
        $shared = $this->backend->getSharedAddressBooksForUser($principal)[0];
        $this->backend->deleteSharedAddressBook($shared['id']);
        $sharee->access = SPlugin::ACCESS_NOACCESS;
        $this->backend->updateInvites($this->bookId, [$sharee]);

        $this->backend->createSubscription($principal, 'resubscribed', [
            '{http://open-paas.org/contacts}source' => new \Sabre\DAV\Xml\Property\Href('addressbooks/user1/book1'),
            '{DAV:}acl' => ['dav:read']
        ]);

        $this->assertCount(0, $this->backend->getSharedAddressBooksForUser($principal));
        $this->assertCount(0, $this->backend->getInvites($this->bookId));
        $subscriptions = $this->backend->getSubscriptionsForUser($principal);
        $this->assertCount(1, $subscriptions);
        $this->assertEquals('public', $subscriptions[0]['{http://open-paas.org/contacts}subscription-type']);
        $this->assertEquals(['dav:read'], $subscriptions[0]['{DAV:}acl']);
    }

    #[DataProvider('delegationRemovalStates')]
    function testRemovingDelegationOnlyNotifiesVisibleBooks($hidden, $deleteSource) {
        $principal = 'principals/users/user2';
        $sharee = new \Sabre\DAV\Xml\Element\Sharee([
            'href' => 'mailto:user2@example.org',
            'principal' => $principal,
            'access' => SPlugin::ACCESS_READ,
            'inviteStatus' => SPlugin::INVITE_ACCEPTED
        ]);
        $this->backend->updateInvites($this->bookId, [$sharee]);
        $shared = $this->backend->getSharedAddressBooksForUser($principal)[0];
        if ($hidden) {
            $this->backend->deleteSharedAddressBook($shared['id']);
        }
        $events = [];
        $this->backend->getEventEmitter()->on('sabre:addressBookSubscriptionDeleted', function ($event) use (&$events) {
            $events[] = $event;
        });

        if ($deleteSource) {
            $listener = new \ESN\CardDAV\Sharing\ListenerPlugin($this->backend);
            $listener->initialize(new \Sabre\DAV\Server());
            $this->backend->deleteAddressBook($this->bookId);
        } else {
            $sharee->access = SPlugin::ACCESS_NOACCESS;
            $this->backend->updateInvites($this->bookId, [$sharee]);
        }

        $this->assertCount(0, $this->backend->getInvites($this->bookId));
        $this->assertCount(0, $this->backend->getSharedAddressBooksForUser($principal));
        $this->assertCount($hidden ? 0 : 1, $events);
        if (!$hidden) {
            $this->assertEquals('addressbooks/user2/' . $shared['uri'], $events[0]['path']);
        }
    }

    static function delegationRemovalStates() {
        return [[false, false], [true, false], [false, true], [true, true]];
    }

    function testConstruct() {
        $backend = $this->getBackend();
        $this->assertTrue($backend instanceof Mongo);
    }

    function testAddressBookExists() {
        $book = [
            'principaluri' => 'principals/users/user2',
            'displayname' => null,
            'uri' => 'thisoneexists',
            'description' => null,
            'synctoken' => 1
        ];

        $this->db->addressbooks->insertOne($book);

        $this->assertTrue($this->backend->addressBookExists('principals/users/user2', 'thisoneexists'));
        $this->assertFalse($this->backend->addressBookExists('principals/users/user2', 'thisonedoesnotexistsatall'));
    }

    function testGetCards() {
        $result = $this->backend->getCards($this->bookId);

        $expected = array(
            array(
                'id' => $this->cardId,
                'uri' => 'card1',
                'lastmodified' => 0,
                'etag' => '"' . md5('card1') . '"',
                'size' => 5
            ),
            array(
                'id' => $this->cardId2,
                'uri' => 'card2',
                'lastmodified' => 0,
                'etag' => '"' . md5('card2') . '"',
                'size' => 5
            )
        );

        $this->assertEquals($expected, $result);
    }

    function testGetCardsSortLimitOffset() {
        $result = $this->backend->getCards($this->bookId, 1, 1, 'fn');

        $expected = array(
            array(
                'id' => $this->cardId2,
                'uri' => 'card2',
                'lastmodified' => 0,
                'etag' => '"' . md5('card2') . '"',
                'size' => 5
            )
        );

        $this->assertEquals($expected, $result);
    }

    function testGetCardsFilters() {
        $backend = $this->getBackend();
        $cardUpToDate = [
            'addressbookid' => new \MongoDB\BSON\ObjectId($this->bookId),
            'carddata' => 'cardUpToDate',
            'uri' => 'cardUpToDate',
            'lastmodified' => 100,
            'etag' => '"' . md5('cardUpToDate') . '"',
            'size' => 5
        ];
        $this->db->cards->insertOne($cardUpToDate);

        $cardOutdated1 = [
            'addressbookid' => new \MongoDB\BSON\ObjectId($this->bookId),
            'carddata' => 'cardOutdated1',
            'uri' => 'cardOutdated1',
            'lastmodified' => 99,
            'etag' => '"' . md5('cardOutdated1') . '"',
            'size' => 5
        ];
        $insertResultCard1 = $this->db->cards->insertOne($cardOutdated1);

        $cardOutdated2 = [
            'addressbookid' => new \MongoDB\BSON\ObjectId($this->bookId),
            'carddata' => 'cardOutdated2',
            'uri' => 'cardOutdated2',
            'lastmodified' => 99,
            'etag' => '"' . md5('cardOutdated2') . '"',
            'size' => 5
        ];
        $insertResultCard2 = $this->db->cards->insertOne($cardOutdated2);
        $filters = [
            'modifiedBefore' => 100
        ];
        $result = $backend->getCards($this->bookId, 0, 0, null, $filters);

        $expected = array(
            array(
                'id' => (string) $insertResultCard1->getInsertedId(),
                'uri' => 'cardOutdated1',
                'lastmodified' => 99,
                'etag' => '"' . md5('cardOutdated1') . '"',
                'size' => 5
            ),
            array(
                'id' => (string) $insertResultCard2->getInsertedId(),
                'uri' => 'cardOutdated2',
                'lastmodified' => 99,
                'etag' => '"' . md5('cardOutdated2') . '"',
                'size' => 5
            )
        );

        $this->assertEquals($expected, $result);
    }

    function testGetSharedAddressBooksBySource() {
        $backend = $this->getBackend();

        $addressBookId = $backend->createAddressBook('principals/users/user1', 'addressbook1', []);
        $backend->updateInvites($addressBookId, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'principal' => 'principals/users/user2',
                'href' => 'mailto:user1@op.co',
                'access' => SPlugin::ACCESS_READ,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                'properties' => []
            ])
        ]);
        $sharedAddressBooks = $backend->getSharedAddressBooksBySource($addressBookId);

        $this->assertEquals(1, count($sharedAddressBooks));
        $this->assertEquals('principals/users/user2', $sharedAddressBooks[0]['principaluri']);
    }
}
