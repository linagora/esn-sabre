<?php

namespace ESN\CardDAV\Backend;

abstract class AbstractDatabaseTest extends \PHPUnit_Framework_TestCase {

    protected $bookId;

    abstract protected function getBackend();
    abstract protected function generateId();

    protected $dummyCard = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:hello\r\nEND:VCARD\r\n";
    protected $dummyCard2 = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:world\r\nEND:VCARD\r\n";
    protected $dummyCard3 = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:Zelda\r\nEND:VCARD\r\n";
    protected $nonStandardFNCard1 = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:拔君\r\nEND:VCARD\r\n";
    protected $nonStandardFNCard2 = "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:+++\r\nEND:VCARD\r\n";

    public function setUp() {
        $this->backend = $this->getBackend();
        //$pdo->exec('INSERT INTO addressbooks (principaluri, displayname, uri,
        //description, synctoken) VALUES ("principals/user1", "book1", "book1",
        //"addressbook 1", 1)');
        //$pdo->exec('INSERT INTO cards (addressbookid, carddata, uri,
        //lastmodified, etag, size) VALUES (1, "card1", "card1", 0, "' .
        //md5('card1') . '", 5)');

    }

    public function testGetAddressBooksForUser() {
        $result = $this->backend->getAddressBooksForUser('principals/user1');

        $expected = array(
            array(
                'id' => $this->bookId,
                'uri' => 'book1',
                'principaluri' => 'principals/user1',
                '{DAV:}displayname' => 'book1',
                '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'addressbook 1',
                '{http://calendarserver.org/ns/}getctag' => 1,
                '{DAV:}acl' => ['dav:read', 'dav:write'],
                '{http://open-paas.org/contacts}type' => '',
                '{http://sabredav.org/ns}sync-token' => "1"
            )
        );

        $this->assertEquals($expected, $result);
    }

    public function testGetAddressBooksWithEmptyPropertiesForUser() {
        $result = $this->backend->getAddressBooksForUser('principals/user2');

        $expected = array(
            array(
                'id' => $this->missingPropertiesBookId,
                'uri' => 'book2',
                'principaluri' => 'principals/user2',
                '{DAV:}displayname' => '',
                '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => '',
                '{http://calendarserver.org/ns/}getctag' => 1,
                '{DAV:}acl' => ['dav:read', 'dav:write'],
                '{http://open-paas.org/contacts}type' => 'social',
                '{http://sabredav.org/ns}sync-token' => "1"
            )
        );

        $this->assertEquals($expected, $result);
    }

    public function testUpdateAddressBookInvalidProp() {
        $propPatch = new \Sabre\DAV\PropPatch([
            '{DAV:}displayname' => 'updated',
            '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'updated',
            '{DAV:}foo' => 'bar',
        ]);

        $this->backend->updateAddressBook($this->bookId, $propPatch);
        $result = $propPatch->commit();

        $this->assertFalse($result);

        $result = $this->backend->getAddressBooksForUser('principals/user1');

        $expected = array(
            array(
                'id' => $this->bookId,
                'uri' => 'book1',
                'principaluri' => 'principals/user1',
                '{DAV:}acl' => ['dav:read', 'dav:write'],
                '{DAV:}displayname' => 'book1',
                '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'addressbook 1',
                '{http://calendarserver.org/ns/}getctag' => 1,
                '{http://sabredav.org/ns}sync-token' => 1,
                '{http://open-paas.org/contacts}type' => ''
            )
        );

        $this->assertEquals($expected, $result);
    }

    public function testUpdateAddressBookNoProps() {
        $propPatch = new \Sabre\DAV\PropPatch([
        ]);

        $this->backend->updateAddressBook($this->bookId, $propPatch);
        $result = $propPatch->commit();
        $this->assertTrue($result);

        $result = $this->backend->getAddressBooksForUser('principals/user1');

        $expected = array(
            array(
                'id' => $this->bookId,
                'uri' => 'book1',
                'principaluri' => 'principals/user1',
                '{DAV:}displayname' => 'book1',
                '{DAV:}acl' => ['dav:read', 'dav:write'],
                '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'addressbook 1',
                '{http://calendarserver.org/ns/}getctag' => 1,
                '{http://sabredav.org/ns}sync-token' => 1,
                '{http://open-paas.org/contacts}type' => ''
            )
        );

        $this->assertEquals($expected, $result);
    }

    public function testUpdateAddressBookSuccess() {
        $propPatch = new \Sabre\DAV\PropPatch([
            '{DAV:}displayname' => 'updated',
            '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'updated',
            '{DAV:}acl' => ['dav:read'],
        ]);

        $this->backend->updateAddressBook($this->bookId, $propPatch);
        $result = $propPatch->commit();

        $this->assertTrue($result);

        $result = $this->backend->getAddressBooksForUser('principals/user1');

        $expected = array(
            array(
                'id' => $this->bookId,
                'uri' => 'book1',
                'principaluri' => 'principals/user1',
                '{DAV:}displayname' => 'updated',
                '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'updated',
                '{DAV:}acl' => ['dav:read'],
                '{http://calendarserver.org/ns/}getctag' => 2,
                '{http://sabredav.org/ns}sync-token' => 2,
                '{http://open-paas.org/contacts}type' => ''
            )
        );

        $this->assertEquals($expected, $result);
    }

    public function testDeleteAddressBook() {
        $this->backend->deleteAddressBook($this->bookId);
        $this->assertEquals(array(), $this->backend->getAddressBooksForUser('principals/user1'));
    }

    /**
     * @expectedException Sabre\DAV\Exception\BadRequest
     */
    public function testCreateAddressBookUnsupportedProp() {
        $this->backend->createAddressBook('principals/user1','book2', array(
            '{DAV:}foo' => 'bar',
        ));
    }

    public function testCreateAddressBookSuccess() {
        $book2Id = $this->backend->createAddressBook('principals/user1','book2', array(
            '{DAV:}displayname' => 'book2',
            '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'addressbook 2',
            '{DAV:}acl' => ['dav:read'],
            '{http://open-paas.org/contacts}type' => 'social'
        ));

        $expected = array(
            array(
                'id' => $this->bookId,
                'uri' => 'book1',
                'principaluri' => 'principals/user1',
                '{DAV:}displayname' => 'book1',
                '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'addressbook 1',
                '{DAV:}acl' => ['dav:read', 'dav:write'],
                '{http://calendarserver.org/ns/}getctag' => 1,
                '{http://sabredav.org/ns}sync-token' => 1,
                '{http://open-paas.org/contacts}type' => '',
            ),
            array(
                'id' => $book2Id,
                'uri' => 'book2',
                'principaluri' => 'principals/user1',
                '{DAV:}displayname' => 'book2',
                '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'addressbook 2',
                '{DAV:}acl' => ['dav:read'],
                '{http://calendarserver.org/ns/}getctag' => 1,
                '{http://sabredav.org/ns}sync-token' => 1,
                '{http://open-paas.org/contacts}type' => 'social'
            )
        );
        $result = $this->backend->getAddressBooksForUser('principals/user1');
        $this->assertEquals($expected, $result);
    }

    public function testGetCardCount() {
        $this->assertEquals($this->backend->getCardCount($this->bookId), 2);
    }

    public function testGetCard() {
        $result = $this->backend->getCard($this->bookId,'card1');

        $expected = array(
            'id' => $this->cardId,
            'uri' => 'card1',
            'carddata' => 'card1',
            'lastmodified' => 0,
            'etag' => '"' . md5('card1') . '"',
            'size' => 5
        );

        $this->assertEquals($expected, $result);
    }

    /**
     * @depends testGetCard
     */
    public function testCreateCard() {
        $result = $this->backend->createCard($this->bookId, 'card3', $this->dummyCard);
        $this->assertEquals('"' . md5($this->dummyCard) . '"', $result);
        $result = $this->backend->getCard($this->bookId,'card3');
        $this->assertEquals('card3', $result['uri']);
        $this->assertEquals($this->dummyCard, $result['carddata']);
    }

    /**
     * @depends testCreateCard
     */
    public function testGetMultiple() {
        $result = $this->backend->createCard($this->bookId, 'card3', $this->dummyCard);
        $result = $this->backend->createCard($this->bookId, 'card4', $this->dummyCard2);
        $check = [
            [
                'uri' => 'card1',
                'carddata' => 'card1',
                'lastmodified' => 0,
            ],
            [
                'uri' => 'card3',
                'carddata' => $this->dummyCard,
                'lastmodified' => time(),
            ],
            [
                'uri' => 'card4',
                'carddata' => $this->dummyCard2,
                'lastmodified' => time(),
            ],
        ];

        $result = $this->backend->getMultipleCards($this->bookId, ['card1','card3','card4']);

        foreach($check as $index=>$node) {
            foreach($node as $k=>$v) {
                if ($k!=='lastmodified') {
                    $this->assertEquals($v, $result[$index][$k]);
                } else {
                    $this->assertTrue(isset($result[$index][$k]));
                }
            }
        }
    }

    /**
     * @depends testGetCard
     */
    public function testUpdateCard() {
        $result = $this->backend->updateCard($this->bookId, 'card1', $this->dummyCard2);
        $this->assertEquals('"' . md5($this->dummyCard2) . '"', $result);

        $result = $this->backend->getCard($this->bookId,'card1');
        $this->assertEquals($this->cardId, $result['id']);
        $this->assertEquals($this->dummyCard2, $result['carddata']);
    }

    /**
     * @depends testGetCard
     */
    public function testDeleteCard() {
        $this->backend->deleteCard($this->bookId, 'card1');
        $result = $this->backend->getCard($this->bookId,'card1');
        $this->assertFalse($result);
    }

    function testGetChanges() {
        $backend = $this->backend;
        $id = $backend->createAddressBook(
            'principals/user1',
            'bla',
            []
        );
        $result = $backend->getChangesForAddressBook($id, null, 1);

        $this->assertEquals([
            'syncToken' => 1,
            "added"     => [],
            'modified'  => [],
            'deleted'   => [],
        ], $result);

        $currentToken = $result['syncToken'];

        $backend->createCard($id, "card1.ics", $this->dummyCard);
        $backend->createCard($id, "card2.ics", $this->dummyCard);
        $backend->createCard($id, "card3.ics", $this->dummyCard);
        $backend->updateCard($id, "card1.ics", $this->dummyCard);
        $backend->deleteCard($id, "card2.ics");

        $result = $backend->getChangesForAddressBook($id, $currentToken, 1);

        $this->assertEquals([
            'syncToken' => 6,
            'modified'  => ["card1.ics"],
            'deleted'   => ["card2.ics"],
            "added"     => ["card3.ics"],
        ], $result);

        $result = $backend->getChangesForAddressBook($id, null, 1);

        $this->assertEquals([
            'syncToken' => 6,
            'modified'  => [],
            'deleted'   => [],
            "added"     => ["card1.ics", "card3.ics"],
        ], $result);
    }

    public function testDecapitalizeFn() {
        $backend = $this->backend;
        $id = $backend->createAddressBook(
            'principals/admin',
            'admin',
            []
        );
        $backend->createCard($id, 'hello', $this->dummyCard);
        $backend->createCard($id, 'world', $this->dummyCard2);
        $backend->createCard($id, 'Zelda', $this->dummyCard3);

        $result = $backend->getCards($id, 0, 0, 'fn');
        $this->assertEquals($result[0]['uri'], 'hello');
        $this->assertEquals($result[1]['uri'], 'world');
        $this->assertEquals($result[2]['uri'], 'Zelda');
    }

    public function testgetCardsShouldReturnNonAlphabeticFnFirst() {
        $backend = $this->backend;
        $id = $backend->createAddressBook(
            'principals/admin',
            'admin',
            []
        );
        $backend->createCard($id, 'hello', $this->dummyCard);
        $backend->createCard($id, '拔君', $this->nonStandardFNCard1);
        $backend->createCard($id, '++++', $this->nonStandardFNCard2);

        $result = $backend->getCards($id, 0, 0, 'fn');
        $this->assertEquals($result[0]['uri'], '拔君');
        $this->assertEquals($result[1]['uri'], '++++');
        $this->assertEquals($result[2]['uri'], 'hello');
    }
}
