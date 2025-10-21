<?php

namespace ESN\CardDAV;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class PluginTest extends PluginTestBase {

    function setUp() {
        parent::setUp();

        // TODO: the plugin is added in tests/DAV/ServerMock.php hence we do not
        // add it again in this file. We will need to move those mocks and test cases to this file
        // and uncomment 2 lines below
        //$plugin = new Plugin();
        //$this->server->addPlugin($plugin);
    }

    function testGETAddressBookHomesWithoutTechnicalUser() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(403, $response->status);
    }

    function testGETAddressBookHomesWithTechnicalUser() {
        $this->authBackend->setPrincipal('principals/technicalUser');

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals([
            '54b64eadf6d7d8e41d263e0f',
            '54b64eadf6d7d8e41d263e0e',
            '54b64eadf6d7d8e41d263e0d',
            '54b64eadf6d7d8e41d263e0c'
        ], $jsonResponse);

        $this->assertEquals(200, $response->status);
    }

    function testPropFindRequestAddressBook() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $body = '{"properties": ["{DAV:}acl","uri"]}';
        $request->setBody($body);
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(200, $response->status);
        $this->assertEquals(['dav:read', 'dav:write'], $jsonResponse['{DAV:}acl']);
        $this->assertEquals('book1', $jsonResponse['uri']);
    }

    function testPropFindAclOfAddressBook() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $body = '{"properties": ["acl"]}';
        $request->setBody($body);
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(200, $response->status);
        $this->assertEquals([
            [
                'privilege' => '{DAV:}all',
                'principal' => 'principals/users/54b64eadf6d7d8e41d263e0f',
                'protected' => true
            ]
        ], $jsonResponse['acl']);
    }

    function testPropFindAclOfPublishedAddressBook() {
        $this->carddavBackend->setPublishStatus($this->carddavAddressBook, '{DAV:}read');

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $body = '{"properties": ["acl"]}';
        $request->setBody($body);
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(200, $response->status);
        $this->assertEquals([
            [
                'privilege' => '{DAV:}all',
                'principal' => 'principals/users/54b64eadf6d7d8e41d263e0f',
                'protected' => true
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => '{DAV:}authenticated'
            ]
        ], $jsonResponse['acl']);
    }

    function testProppatchDefaultAddressBook() {
        $contactsAddressBook = array(
            'uri' => 'contacts',
            'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
        );

        $this->carddavBackend->createAddressBook($contactsAddressBook['principaluri'],
            $contactsAddressBook['uri'],
            [
                '{DAV:}displayname' => 'contacts',
                '{urn:ietf:params:xml:ns:carddav}addressbook-description' => 'Contacts description'
            ]);

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(3, $addressbooks);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPPATCH',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/contacts.json',
        ));

        $data = [ 'dav:name' => 'Patched name' ];
        $request->setBody(json_encode($data));

        $response = $this->request($request);
        $this->assertEquals(403, $response->status);
    }

    function testProppatchCollectedAddressBook() {
        $collectedAddressBook = array(
            'uri' => 'collected',
            'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
        );

        $returnValue =  $this->carddavBackend->createAddressBook($collectedAddressBook['principaluri'],
            $collectedAddressBook['uri'],
            [
                '{DAV:}displayname' => 'collected',
                '{urn:ietf:params:xml:ns:carddav}addressbook-description' => 'Collected description'
            ]);

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(3, $addressbooks);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPPATCH',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/collected.json',
        ));

        $data = [ 'dav:name' => 'Patched name' ];
        $request->setBody(json_encode($data));

        $response = $this->request($request);
        $this->assertEquals(403, $response->status);
    }

    function testProppatchAddressBook() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPPATCH',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $patchedName = 'Patched name';
        $patchedDescription = 'Patched description';
        $data = [
            'dav:name' => $patchedName,
            'carddav:description' => $patchedDescription
        ];
        $request->setBody(json_encode($data));

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(3, $addressbooks);

        $patchedAddressBook;

        foreach ($addressbooks as $addressbook) {
            if ($addressbook['uri'] === 'book1') {
                $patchedAddressBook = $addressbook;
                break;
            }
        }

        $this->assertEquals($patchedName, $patchedAddressBook['{DAV:}displayname']);
        $this->assertEquals($patchedDescription, $patchedAddressBook['{urn:ietf:params:xml:ns:carddav}addressbook-description']);
    }

    function testProppatchGroupAddressBook() {
        $DOMAIN_ID = '54b64eadf6d7d8e41d263e7e';

        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($DOMAIN_ID),
            'administrators' => [
                [
                    'user_id' => $this->userTestId1
                ]
            ]
        ]);

        $domainBook1Id = $this->createAddressBook('principals/domains/' . $DOMAIN_ID, 'domainAB');

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPPATCH',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/' . $DOMAIN_ID . '/domainAB.json',
        ));

        $patchedName = 'Patched name';
        $patchedDescription = 'Patched description';
        $patchedState = 'disabled';
        $data = [
            'dav:name' => $patchedName,
            'carddav:description' => $patchedDescription,
            'state' => $patchedState
        ];
        $request->setBody(json_encode($data));

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);

        $addressbooks = $this->carddavBackend->getAddressBooksFor('principals/domains/' . $DOMAIN_ID);
        $this->assertCount(1, $addressbooks);

        $this->assertEquals($patchedName, $addressbooks[0]['{DAV:}displayname']);
        $this->assertEquals($patchedDescription, $addressbooks[0]['{urn:ietf:params:xml:ns:carddav}addressbook-description']);
        $this->assertEquals($patchedState, $addressbooks[0]['{http://open-paas.org/contacts}state']);
    }

    function testDeleteDefaultAddressBook() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/contacts.json',
        ));

        $contactsAddressBook = array(
            'uri' => 'contacts',
            'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
        );

        $this->carddavBackend->createAddressBook($contactsAddressBook['principaluri'],
            $contactsAddressBook['uri'],
            [
                '{DAV:}displayname' => 'contacts',
                '{urn:ietf:params:xml:ns:carddav}addressbook-description' => 'Contacts description'
            ]);

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(3, $addressbooks);

        $response = $this->request($request);
        $this->assertEquals(403, $response->status);

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(3, $addressbooks);
    }

    function testDeleteCollectedAddressBook() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/collected.json',
        ));

        $collectedAddressBook = array(
            'uri' => 'collected',
            'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
        );

        $this->carddavBackend->createAddressBook($collectedAddressBook['principaluri'],
            $collectedAddressBook['uri'],
            [
                '{DAV:}displayname' => 'collected',
                '{urn:ietf:params:xml:ns:carddav}addressbook-description' => 'Collected description'
            ]);

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(3, $addressbooks);

        $response = $this->request($request);
        $this->assertEquals(403, $response->status);

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(3, $addressbooks);
    }

    function testDeleteAddressBook() {
        $this->carddavBackend->createSubscription(
            'principals/users/' . $this->userTestId2,
            'book2',
            [
                '{DAV:}displayname' => 'Book 1',
                '{http://open-paas.org/contacts}source' => new \Sabre\DAV\Xml\Property\Href('addressbooks/54b64eadf6d7d8e41d263e0f/book1', false)
            ]
        );

        $subscriptions = $this->carddavBackend->getSubscriptionsForUser('principals/users/'. $this->userTestId2);
        $this->assertCount(1, $subscriptions);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'DELETE',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(3, $addressbooks);

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(2, $addressbooks);

        $subscriptions = $this->carddavBackend->getSubscriptionsForUser('principals/users/'. $this->userTestId2);
        $this->assertCount(0, $subscriptions);
    }

    function testGetContacts() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json?limit=3',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(200, $response->status);
        $this->assertCount(3, $jsonResponse['_embedded']['dav:item']);
    }

    function testGetContactsUnknownAddressBook() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.jaysun'
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testGetContactsWrongCollection() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar2.json'
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 404);
    }

    function testGetAllContacts() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json');
        $cards = $jsonResponse->{'_embedded'}->{'dav:item'};
        $this->assertEquals(count($cards), 4);
        $this->assertEquals($cards[0]->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1/card1');
        $this->assertEquals($cards[0]->data[0], 'vcard');
        $this->assertEquals($cards[0]->data[1][0][3], 'd');
    }

    function testGetContactsOffset() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json?limit=1&offset=1&sort=fn'
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json');
        $cards = $jsonResponse->{'_embedded'}->{'dav:item'};
        $this->assertCount(1, $cards);
        $this->assertEquals($cards[0]->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1/card3');
        $this->assertEquals($cards[0]->data[0], 'vcard');
        $this->assertEquals($cards[0]->data[1][0][3], 'b');
    }

    function testCreateAddressBook() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f.json',
        ));

        $addressbook = [
            'id' => 'ID',
            'dav:name' => 'NAME',
            'carddav:description' => 'DESCRIPTION',
            'dav:acl' => ['dav:read'],
            'type' => 'social',
            'state' => 'enabled'
        ];

        $request->setBody(json_encode($addressbook));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals(201, $response->status);

        $addressBooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(4, $addressBooks);

        $createdAddressBook;

        foreach ($addressBooks as $addressBook) {
            if ($addressBook['uri'] === $addressbook['id']) {
                $createdAddressBook = $addressBook;
            }
        }

        $this->assertEquals('NAME', $createdAddressBook['{DAV:}displayname']);
        $this->assertEquals('DESCRIPTION', $createdAddressBook['{urn:ietf:params:xml:ns:carddav}addressbook-description']);
        $this->assertEquals(new \MongoDB\Model\BSONArray(['dav:read']), $createdAddressBook['{DAV:}acl']);
        $this->assertEquals('social', $createdAddressBook['{http://open-paas.org/contacts}type']);
        $this->assertEquals('enabled', $createdAddressBook['{http://open-paas.org/contacts}state']);
    }

    function testCreateAddressBookMissingId() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f.json',
        ));

        $addressbook = [
            'id' => ''
        ];

        $request->setBody(json_encode($addressbook));
        $response = $this->request($request);

        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals(400, $response->status);

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->carddavAddressBook['principaluri']);
        $this->assertCount(3, $addressbooks);
    }

    function testTimeRangeWrongNode() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json',
        ));

        $request->setBody(json_encode($this->timeRangeData));
        $response = $this->request($request);
        $this->assertEquals($response->status, 501);
    }

    function testCreateCardInDomainMembersAsAdminShouldFail() {
        $DOMAIN_ID = '54b64eadf6d7d8e41d263e7e';

        // Create domain with admin user
        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($DOMAIN_ID),
            'administrators' => [
                [
                    'user_id' => $this->userTestId1
                ]
            ]
        ]);

        // Create domain-members addressbook
        $domainMembersId = $this->createAddressBook('principals/domains/' . $DOMAIN_ID, 'domain-members');

        // Try to create a card as regular user (should fail)
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => 'text/vcard',
            'REQUEST_URI'       => '/addressbooks/' . $DOMAIN_ID . '/domain-members/test.vcf',
        ));

        $vcard = "BEGIN:VCARD\r\n" .
                 "VERSION:4.0\r\n" .
                 "FN:Test User\r\n" .
                 "END:VCARD\r\n";

        $request->setBody($vcard);
        $response = $this->request($request);

        $this->assertEquals(403, $response->status);
    }

    function testCreateCardInDomainMembersAsTechnicalUserShouldSucceed() {
        $DOMAIN_ID = '54b64eadf6d7d8e41d263e7e';

        // Create domain
        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($DOMAIN_ID),
            'administrators' => []
        ]);

        // Create domain-members addressbook
        $domainMembersId = $this->createAddressBook('principals/domains/' . $DOMAIN_ID, 'domain-members');

        // Set principal to technical user
        $this->authBackend->setPrincipal('principals/technicalUser');

        // Try to create a card as technical user (should succeed)
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => 'text/vcard',
            'REQUEST_URI'       => '/addressbooks/' . $DOMAIN_ID . '/domain-members/test.vcf',
        ));

        $vcard = "BEGIN:VCARD\r\n" .
                 "VERSION:4.0\r\n" .
                 "FN:Test User\r\n" .
                 "END:VCARD\r\n";

        $request->setBody($vcard);
        $response = $this->request($request);

        $this->assertEquals(201, $response->status);
    }
}
