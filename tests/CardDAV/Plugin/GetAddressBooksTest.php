<?php

namespace ESN\CardDAV\Plugin;

use Sabre\DAV\ServerPlugin;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class GetAddressBooksTest extends \ESN\CardDAV\PluginTestBase {

    function setUp() {
        parent::setUp();

        // TODO: the plugin is added in tests/DAV/ServerMock.php hence we do not
        // add it again in this file. We will need to move those mocks and test cases to this file
        // and uncomment 2 lines below
        //$plugin = new Plugin();
        //$this->server->addPlugin($plugin);
    }

    function testGetAddressBooksRespondsEmptyListIfNoFilterSpecfied() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f.json',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f.json');

        $addressBooks = $jsonResponse->{'_embedded'}->{'dav:addressbook'};
        $this->assertCount(0, $addressBooks);
    }

    function testGetPersonalAddressBooks() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/54b64eadf6d7d8e41d263e0f.json?personal=true',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f.json');

        $addressBooks = $jsonResponse->{'_embedded'}->{'dav:addressbook'};
        $this->assertCount(3, $addressBooks);
        $this->assertEquals($addressBooks[0]->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/book1.json');
        $this->assertEquals($addressBooks[1]->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/collected.json');
        $this->assertEquals($addressBooks[2]->{'_links'}->self->href, '/addressbooks/54b64eadf6d7d8e41d263e0f/contacts.json');
    }

    function testGetPuclicAddressBooks() {
        $publicBookId = $this->carddavBackend->createAddressBook(
            'principals/users/' . $this->userTestId1,
            'publicBook',
            [
                '{DAV:}displayname' => 'Public book',
                '{urn:ietf:params:xml:ns:carddav}addressbook-description' => 'Public book',
                '{http://open-paas.org/contacts}type' => 'social',
            ]
        );

        $this->carddavBackend->setPublishStatus(array( 'id' => $publicBookId ), '{DAV:}read');

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/' . $this->userTestId1 . '.json?public=true',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);

        $addressBooks = $jsonResponse->{'_embedded'}->{'dav:addressbook'};

        $this->assertCount(1, $addressBooks);
        $this->assertEquals($addressBooks[0]->{'_links'}->self->href, '/addressbooks/' . $this->userTestId1 . '/publicBook.json');
        $this->assertEquals($addressBooks[0]->{'dav:name'}, 'Public book');
        $this->assertEquals($addressBooks[0]->{'carddav:description'}, 'Public book');
        $this->assertEquals($addressBooks[0]->{'dav:acl'}, ['dav:read', 'dav:write']);
        $this->assertEquals($addressBooks[0]->{'type'}, 'social');
        $this->assertEquals(
            $this->getAddressBookAcl($addressBooks[0]),
            array(
                'principals/users/' . $this->userTestId1 => '{DAV:}all',
                '{DAV:}authenticated' => '{DAV:}read'
            )
        );
    }

    function testGetAddressBooksContainGroupAddressBook() {
        $domainID = '54313fcc398fef406b0041b8';
        $userID = '54313fcc398fef406b0041b7';

        $this->authBackend->setPrincipal('principals/users/' . $userID);
        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($domainID),
            'administrators' => [
                [ 'user_id' => new \MongoDB\BSON\ObjectId($userID) ]
            ]
        ]);
        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($userID),
            'firstname' => 'admin',
            'lastname' => 'admin',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                      'admin@lng.com'
                    ]
                ]
                    ],
            'domains' => [ [ 'domain_id' => $domainID] ]
        ]);

        $publicBookId = $this->carddavBackend->createAddressBook(
            'principals/domains/' . $domainID,
            'gab',
            [ '{DAV:}acl' => [ '{DAV:}read' ] ]
        );

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/' . $userID . '.json?personal=true',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);

        $addressBooks = $jsonResponse->{'_embedded'}->{'dav:addressbook'};

        $this->assertCount(3, $addressBooks);
        $this->assertEquals($addressBooks[0]->{'_links'}->self->href, '/addressbooks/' . $userID . '/collected.json');
        $this->assertEquals($addressBooks[1]->{'_links'}->self->href, '/addressbooks/' . $userID . '/contacts.json');
        $this->assertEquals($addressBooks[2]->{'_links'}->self->href, '/addressbooks/' . $domainID . '/gab.json');
    }

    function testGetSubscribedAddressBooks() {
        $this->carddavBackend->createSubscription(
            'principals/users/'. $this->userTestId2,
            'user2Subscription1',
            [
                '{DAV:}displayname' => 'user2Subscription1',
                '{http://open-paas.org/contacts}source' => new \Sabre\DAV\Xml\Property\Href('addressbooks/' . $this->userTestId1 . '/book1', false)
            ]
        );

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'GET',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/' . $this->userTestId2 . '.json?subscribed=true',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString());
        $this->assertEquals($response->status, 200);
        $this->assertEquals($jsonResponse->{'_links'}->self->href, '/addressbooks/' . $this->userTestId2 . '.json');
        $addressBooks = $jsonResponse->{'_embedded'}->{'dav:addressbook'};
        $this->assertCount(1, $addressBooks);

        $this->assertEquals($addressBooks[0]->{'_links'}->self->href, '/addressbooks/' . $this->userTestId2 . '/user2Subscription1.json');
        $this->assertEquals($addressBooks[0]->{'dav:name'}, 'user2Subscription1');
        $this->assertEquals(
            $this->getAddressBookAcl($addressBooks[0]),
            array(
                'principals/users/'. $this->userTestId2 => '{DAV:}all'
            )
        );

        $this->assertEquals($addressBooks[0]->{'openpaas:source'}, '/addressbooks/' . $this->userTestId1 . '/book1.json');
    }

    private function getAddressBookAcl($addressBook) {
        $result = [];
        foreach( $addressBook->{'acl'} as $ace ) {
            $result[$ace->principal] = $ace->privilege;
        }

        return $result;
    }
}
