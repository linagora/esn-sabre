<?php

namespace ESN\CardDAV\Subscription;

use ESN\Utils\AuthTenant;
use Sabre\DAV\ServerPlugin;
use PHPUnit\Framework\Attributes\DataProvider;
use Sabre\VObject\Document;
use Sabre\VObject\ITip\Message;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class PluginTest extends \ESN\CardDAV\PluginTestBase {

    function setUp(): void {
        parent::setUp();

        // TODO: the plugin is added in tests/DAV/ServerMock.php hence we do not
        // add it again in this file. We will need to move those mocks and test cases to this file
        // and uncomment 2 lines below
        //$plugin = new Plugin();
        //$this->server->addPlugin($plugin);
    }

    function testPropFindRequestSubscriptionAddressBook() {
        $this->carddavBackend->createSubscription(
            'principals/users/' . $this->userTestId2,
            'user2subscription1',
            [
                '{DAV:}displayname' => 'user2subscription1',
                '{http://open-paas.org/contacts}source' => new \Sabre\DAV\Xml\Property\Href('addressbooks/' . $this->userTestId1 . '/book1', false)
            ]
        );
        $this->authBackend->setPrincipal('principals/users/' . $this->userTestId2);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/' . $this->userTestId2 . '/user2subscription1.json',
        ));

        $body = '{"properties": ["uri","{http://open-paas.org/contacts}source","acl"]}';
        $request->setBody($body);
        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(200, $response->status);
        $this->assertEquals($jsonResponse['uri'], 'user2subscription1');
        $this->assertEquals($jsonResponse['{http://open-paas.org/contacts}source'], '/addressbooks/' . $this->userTestId1 . '/book1.json');

        // Old subscriptions without share_access default to ACCESS_READ
        // ACL contains: read + write-properties
        $this->assertCount(2, $jsonResponse['acl']);
        $this->assertEquals($jsonResponse['acl'][0]['privilege'], '{DAV:}read');
        $this->assertEquals($jsonResponse['acl'][1]['privilege'], '{DAV:}write-properties');
    }

    #[DataProvider('subscriptionCreationPermissions')]
    function testCreateSubscriptionAddressBook($actor, $publicRight, $expectedStatus, $expectedPrivileges) {
        $tenants = [
            'subscriber' => new AuthTenant($this->userTestId2, SERVER_MOCK_DOMAIN_ID),
            'source owner' => new AuthTenant($this->userTestId1, SERVER_MOCK_DOMAIN_ID)
        ];
        $this->authBackend->setAuthTenant($tenants[$actor]);
        $this->carddavBackend->setPublishStatus($this->carddavAddressBook, $publicRight);
        $events = [];
        $this->carddavBackend->getEventEmitter()->on('sabre:addressBookSubscriptionCreated', function ($event) use (&$events) {
            $events[] = $event;
        });
        $addressBook = [
            'id' => 'ID',
            'dav:name' => 'SUB NAME',
            'openpaas:source' => [
                '_links' => [
                     'self' => [
                          'href' => '/addressbooks/' . $this->userTestId1 . '/book1.json'
                     ]
                ]
            ]
        ];
        $response = $this->makeRequest(
          'POST',
          '/addressbooks/' . $this->userTestId2 . '.json',
          $addressBook
        );

        $this->assertEquals($expectedStatus, $response->status, $response->getBodyAsString());

        $addressBooks = $this->carddavBackend->getSubscriptionsForUser('principals/users/' . $this->userTestId2);
        if ($expectedStatus === 403) {
            $this->assertCount(0, $addressBooks);
            $this->assertSame([], $events);
            return;
        }
        $this->assertCount(1, $addressBooks);

        $this->assertEquals($addressBooks[0]['{DAV:}displayname'], 'SUB NAME');
        $this->assertEquals($addressBooks[0]['uri'], 'ID');
        $this->assertEquals($addressBooks[0]['principaluri'], 'principals/users/' . $this->userTestId2);
        $this->assertEquals($addressBooks[0]['{http://open-paas.org/contacts}source'], 'addressbooks/' . $this->userTestId1 . '/book1');
        $this->assertSame($expectedPrivileges, $addressBooks[0]['{DAV:}acl']);
        $this->assertSame([['path' => 'addressbooks/' . $this->userTestId2 . '/ID']], $events);
    }

    static function subscriptionCreationPermissions() {
        // Reading the source and writing the destination home are independent permissions.
        return [
            'subscribe to public read source' => ['subscriber', '{DAV:}read', 201, ['dav:read']],
            'subscribe to public write source' => ['subscriber', '{DAV:}write', 201, ['dav:read', 'dav:write']],
            'private source is denied' => ['subscriber', '', 403, []],
            'source owner cannot write subscriber home' => ['source owner', '', 403, []]
        ];
    }

    #[DataProvider('invalidSubscriptionSources')]
    function testCreateSubscriptionAddressBookRejectsInvalidSource($source) {
        $response = $this->makeRequest('POST', '/addressbooks/' . $this->userTestId2 . '.json', [
            'id' => 'subscription-repro',
            'dav:name' => 'Subscription reproduction',
            'type' => 'subscription',
            'openpaas:source' => $source
        ]);

        $this->assertEquals(400, $response->status);
        $this->assertCount(0, $this->carddavBackend->getSubscriptionsForUser('principals/users/' . $this->userTestId2));
    }

    static function invalidSubscriptionSources() {
        $href = '/addressbooks/userTestId1/book1.json';

        return [
            'string source' => [$href],
            'list source' => [[$href]],
            'missing _links' => [['self' => ['href' => $href]]],
            'string _links' => [['_links' => $href]],
            'missing self' => [['_links' => ['href' => $href]]],
            'string self' => [['_links' => ['self' => $href]]],
            'missing href' => [['_links' => ['self' => []]]],
            'empty href' => [['_links' => ['self' => ['href' => '']]]],
            'non string href' => [['_links' => ['self' => ['href' => ['nested' => $href]]]]]
        ];
    }

    #[DataProvider('invalidAddressBookIds')]
    function testCreateSubscriptionAddressBookRejectsInvalidId($id) {
        $response = $this->makeRequest('POST', '/addressbooks/' . $this->userTestId2 . '.json', [
            'id' => $id,
            'openpaas:source' => ['_links' => ['self' => [
                'href' => '/addressbooks/' . $this->userTestId1 . '/book1.json'
            ]]]
        ]);

        $this->assertEquals(400, $response->status);
        $this->assertCount(0, $this->carddavBackend->getSubscriptionsForUser('principals/users/' . $this->userTestId2));
    }

    #[DataProvider('invalidAddressBookIds')]
    function testCreateAddressBookRejectsInvalidId($id) {
        $principal = 'principals/users/' . $this->userTestId2;
        $addressBookCount = count($this->carddavBackend->getAddressBooksForUser($principal));

        $response = $this->makeRequest('POST', '/addressbooks/' . $this->userTestId2 . '.json', [
            'id' => $id,
            'dav:name' => 'NAME'
        ]);

        $this->assertEquals(400, $response->status);
        $this->assertCount($addressBookCount, $this->carddavBackend->getAddressBooksForUser($principal));
    }

    static function invalidAddressBookIds() {
        return [
            'empty string' => [''],
            'number' => [42],
            'list' => [['ID']],
            'object' => [['value' => 'ID']]
        ];
    }

    #[DataProvider('delegationAccessLevels')]
    function testResubscribeDelegationThroughHttp($access) {
        $principal = 'principals/users/' . $this->userTestId1;
        $this->carddavBackend->updateInvites($this->user2Book1Id, [new \Sabre\DAV\Xml\Element\Sharee([
            'href' => 'mailto:' . $this->userTestEmail1,
            'principal' => $principal,
            'access' => $access,
            'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED
        ])]);
        $shared = $this->carddavBackend->getSharedAddressBooksForUser($principal)[0];
        $home = '/addressbooks/' . $this->userTestId1;
        $body = [
            'id' => 'restored',
            'openpaas:source' => ['_links' => ['self' => [
                'href' => '/addressbooks/' . $this->userTestId2 . '/user2book1.json'
            ]]]
        ];

        $this->assertEquals(204, $this->makeRequest('DELETE', $home . '/' . $shared['uri'] . '.json')->status);
        $events = [];
        $this->carddavBackend->getEventEmitter()->on('sabre:addressBookSubscriptionCreated', function ($event) use (&$events) {
            $events[] = $event;
        });
        $this->assertEquals(201, $this->makeRequest('POST', $home . '.json', $body)->status);
        $this->assertEquals([['path' => ltrim($home . '/restored', '/')]], $events);
        $this->assertCount(1, $this->carddavBackend->getSharedAddressBookSourcesForUser($principal));
        $this->assertCount(1, $this->carddavBackend->getSharedAddressBooksBySource($this->user2Book1Id));
        $restored = $this->carddavBackend->getSharedAddressBooksForUser($principal)[0];
        $this->assertEquals($shared['id'], $restored['id']);
        $this->assertEquals('restored', $restored['uri']);
        $this->assertEquals($shared['{DAV:}displayname'], $restored['{DAV:}displayname']);
        $this->assertEquals($shared['{urn:ietf:params:xml:ns:carddav}addressbook-description'], $restored['{urn:ietf:params:xml:ns:carddav}addressbook-description']);
        $this->assertEquals($access, $restored['share_access']);
        $this->assertCount(0, $this->carddavBackend->getSubscriptionsForUser($principal));

        $acl = $this->server->getPlugin('acl');
        $path = ltrim($home . '/restored', '/');
        $this->assertTrue($acl->checkPrivileges($path, '{DAV:}read', \Sabre\DAVACL\Plugin::R_PARENT, false));
        $this->assertEquals($access !== \Sabre\DAV\Sharing\Plugin::ACCESS_READ, $acl->checkPrivileges($path, '{DAV:}write-content', \Sabre\DAVACL\Plugin::R_PARENT, false));
        $this->assertEquals($access === \ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION, $acl->checkPrivileges($path, '{DAV:}share', \Sabre\DAVACL\Plugin::R_PARENT, false));

        $this->assertEquals(204, $this->makeRequest('DELETE', $home . '/restored.json')->status);
        $body['id'] = 'cleared';
        $body['dav:name'] = '';
        $body['carddav:description'] = '';
        $this->assertEquals(201, $this->makeRequest('POST', $home . '.json', $body)->status);
        $shares = $this->carddavBackend->getSharedAddressBooksForUser($principal);
        $this->assertCount(1, $shares);
        $this->assertCount(0, $this->carddavBackend->getSubscriptionsForUser($principal));
        $restored = $shares[0];
        $this->assertEquals('', $restored['{DAV:}displayname']);
        $this->assertEquals('', $restored['{urn:ietf:params:xml:ns:carddav}addressbook-description']);
    }

    static function delegationAccessLevels() {
        return [
            [\Sabre\DAV\Sharing\Plugin::ACCESS_READ],
            [\Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE],
            [\ESN\DAV\Sharing\Plugin::ACCESS_ADMINISTRATION]
        ];
    }

    #[DataProvider('subscriptionCollisionStates')]
    function testSubscriptionRejectsExistingUri($hiddenDelegation) {
        $principal = 'principals/users/' . $this->userTestId1;
        $this->carddavBackend->updateInvites($this->user2Book1Id, [new \Sabre\DAV\Xml\Element\Sharee([
            'href' => 'mailto:' . $this->userTestEmail1,
            'principal' => $principal,
            'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_READ,
            'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED
        ])]);
        $shared = $this->carddavBackend->getSharedAddressBooksForUser($principal)[0];
        if ($hiddenDelegation) {
            $this->carddavBackend->deleteSharedAddressBook($shared['id']);
        }
        $response = $this->makeRequest('POST', '/addressbooks/' . $this->userTestId1 . '.json', [
            'id' => 'book1',
            'openpaas:source' => ['_links' => ['self' => [
                'href' => '/addressbooks/' . $this->userTestId2 . '/user2book1.json'
            ]]]
        ]);

        $this->assertEquals(409, $response->status);
        $this->assertCount(0, $this->carddavBackend->getSubscriptionsForUser($principal));
        $shares = $this->carddavBackend->getSharedAddressBooksForUser($principal);
        $this->assertCount($hiddenDelegation ? 0 : 1, $shares);
        if (!$hiddenDelegation) {
            $this->assertEquals($shared, $shares[0]);
        }
        $this->assertCount(1, $this->carddavBackend->getInvites($this->user2Book1Id));
    }

    static function subscriptionCollisionStates() {
        return [[false], [true]];
    }

    #[DataProvider('subscriptionPrivileges')]
    function testDeleteSubscriptionAddressBook($privileges) {
        $this->carddavBackend->createSubscription(
            'principals/users/' . $this->userTestId1,
            'subscription1',
            [
                '{DAV:}displayname' => 'Subscription 1',
                '{http://open-paas.org/contacts}source' => new \Sabre\DAV\Xml\Property\Href('addressbooks/' . $this->userTestId2 . '/user2book1', false),
                '{DAV:}acl' => $privileges
            ]
        );

        $subscriptions = $this->carddavBackend->getSubscriptionsForUser('principals/users/' . $this->userTestId1);
        $this->assertCount(1, $subscriptions);

        $response = $this->makeRequest(
          'DELETE',
          '/addressbooks/' . $this->userTestId1 . '/subscription1.json'
        );

        // The subscriber can unsubscribe even when the source is read-only.
        $this->assertEquals($response->status, 204);

        $subscriptions = $this->carddavBackend->getSubscriptionsForUser('principals/users/' . $this->userTestId1);
        $this->assertCount(0, $subscriptions);
    }

    static function subscriptionPrivileges() {
        return [
            [['dav:read']],
            [['dav:read', 'dav:write']]
        ];
    }

    function testOtherUserCannotDeleteSubscriptionAddressBook() {
        $this->carddavBackend->createSubscription(
            'principals/users/' . $this->userTestId1,
            'subscription1',
            [
                '{http://open-paas.org/contacts}source' => new \Sabre\DAV\Xml\Property\Href('addressbooks/' . $this->userTestId2 . '/user2book1', false),
                '{DAV:}acl' => ['dav:read']
            ]
        );
        $this->authBackend->setPrincipal('principals/users/' . $this->userTestId3);

        $response = $this->makeRequest('DELETE', '/addressbooks/' . $this->userTestId1 . '/subscription1.json');

        $this->assertEquals(403, $response->status);
        $this->assertCount(1, $this->carddavBackend->getSubscriptionsForUser('principals/users/' . $this->userTestId1));
    }

    function testPropPatchSubscriptionAddressBook() {
        $this->carddavBackend->createSubscription(
            'principals/users/' . $this->userTestId1,
            'subscription1',
            [
                '{DAV:}displayname' => 'Subscription 1',
                '{http://open-paas.org/contacts}source' => new \Sabre\DAV\Xml\Property\Href('addressbooks/' . $this->userTestId2 . '/user2book1', false)
            ]
        );

        $response = $this->makeRequest(
          'PROPPATCH',
          '/addressbooks/' . $this->userTestId1 . '/subscription1.json',
          [
              'dav:name' => 'subscription tested',
              'carddav:description' => 'description updated'
          ]
        );

        $this->assertEquals(204, $response->status);

        $subscriptions = $this->carddavBackend->getSubscriptionsForUser($this->cal['principaluri']);
        $this->assertEquals('subscription tested', $subscriptions[0]['{DAV:}displayname']);
        $this->assertEquals('description updated', $subscriptions[0]['{urn:ietf:params:xml:ns:carddav}addressbook-description']);
    }
}
