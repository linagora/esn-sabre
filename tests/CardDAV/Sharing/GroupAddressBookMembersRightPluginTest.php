<?php

namespace ESN\CardDAV\Sharing;

use \ESN\DAV\Sharing\Plugin as EsnSharingPlugin;
use \Sabre\DAV\Sharing\Plugin as SPlugin;

require_once ESN_TEST_BASE . '/CardDAV/PluginTestBase.php';

class GroupAddressBookMembersRightPluginTest extends \ESN\CardDAV\PluginTestBase {
    const DOMAIN_ID = '54b64eadf6d7d8e41d263e7e';
    const USER_ID = '54b64eadf6d7d8e41d263e8f';
    const ADMINISTRATOR_ID = '54b64eadf6d7d8e41d263e9f';

    function setUp(): void {
        parent::setUp();

        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID),
            'name' => 'test',
            'administrators' => [
                [
                    'user_id' => self::ADMINISTRATOR_ID
                ]
            ]
        ]);

        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER_ID),
            'firstname' => 'foo',
            'lastname' => 'bar',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                      'foobar@lng.com'
                    ]
                ]
                    ],
            'domains' => [ [ 'domain_id' => self::DOMAIN_ID] ]
        ]);

        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::ADMINISTRATOR_ID),
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
            'domains' => [ [ 'domain_id' => self::DOMAIN_ID] ]
        ]);

        $this->carddavBackend->createAddressBook('principals/domains/' . self::DOMAIN_ID, 'gab', [ '{DAV:}acl' => [ '{DAV:}read' ] ]);

        $plugin = new Plugin();
        $this->server->addPlugin($plugin);
    }

    function testUpdateMembersRightWithUnauthorizedUserResponds403() {
        $this->authBackend->setPrincipal('principals/users/' . self::USER_ID);

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . self::DOMAIN_ID . '/gab.json',
            [
                'dav:group-addressbook' => [ 'privileges' => [] ]
            ]
        );

        $this->assertEquals(403, $response->status);
        $this->assertStringContainsString('User did not have the required privileges ({DAV:}share) for path', $response->getBodyAsString());
    }

    function testUpdateMembersRightWithEmptyPrivilegesResponds400() {
        $this->authBackend->setPrincipal('principals/users/' . self::ADMINISTRATOR_ID);

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . self::DOMAIN_ID . '/gab.json',
            array(
                'dav:group-addressbook' => [ 'privileges' => [] ]
            )
        );
        $this->assertEquals(400, $response->status);
    }

    function testUpdateMembersRightWithInvalidPrivilegesResponds400() {
        $this->authBackend->setPrincipal('principals/users/' . self::ADMINISTRATOR_ID);

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . self::DOMAIN_ID . '/gab.json',
            array(
                'dav:group-addressbook' => [ 'privileges' => [ '{DAV:}invalid' ] ]
            )
        );
        $this->assertEquals(400, $response->status);
    }

    function testUpdateMembersRightWithResponds204() {
        $this->authBackend->setPrincipal('principals/users/' . self::ADMINISTRATOR_ID);

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . self::DOMAIN_ID . '/gab.json',
            array(
                'dav:group-addressbook' => [ 'privileges' => [ '{DAV:}read', '{DAV:}write-content' ] ]
            )
        );
        $this->assertEquals(204, $response->status);

        $groupAddressBooks = $this->carddavBackend->getAddressBooksFor('principals/domains/' . self::DOMAIN_ID);
        $this->assertCount(1, $groupAddressBooks);

        $this->assertEquals((array)$groupAddressBooks[0]['{DAV:}acl'], [ '{DAV:}read', '{DAV:}write-content' ]);
    }
}
