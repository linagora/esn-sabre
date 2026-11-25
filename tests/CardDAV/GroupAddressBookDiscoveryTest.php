<?php

namespace ESN\CardDAV;

use PHPUnit\Framework\Attributes\DataProvider;

require_once ESN_TEST_BASE . '/CardDAV/PluginTestBase.php';

class GroupAddressBookDiscoveryTest extends PluginTestBase {

    function setUp(): void {
        parent::setUp();

        $this->esndb->domains->updateOne(
            ['_id' => new \MongoDB\BSON\ObjectId(SERVER_MOCK_DOMAIN_ID)],
            ['$set' => ['administrators' => [['user_id' => $this->userTestId1]]]]
        );
        $this->carddavBackend->createAddressBook('principals/domains/' . SERVER_MOCK_DOMAIN_ID, 'gab', [
            '{DAV:}displayname' => 'Domain contacts',
            '{DAV:}acl' => ['{DAV:}read'],
            '{http://open-paas.org/contacts}state' => 'disabled'
        ]);
    }

    static function discoveryRequests() {
        return [
            'without mobile plugin or user agent' => [null, false],
            'unknown client with mobile plugin' => ['Another DAV client/1.0', true],
            'Thunderbird without requested resource type' => ['Thunderbird/160.0', true],
        ];
    }

    #[DataProvider('discoveryRequests')]
    function testDisabledGroupBookIsHiddenAndCanBeReenabled($userAgent, $withMobilePlugin) {
        if ($withMobilePlugin) {
            $this->server->addPlugin(new MobileRequestPlugin());
        }
        $request = new \Sabre\HTTP\Request('PROPFIND', '/addressbooks/' . SERVER_MOCK_DOMAIN_ID . '/', ['Depth' => '1']);
        if ($userAgent !== null) {
            $request->setHeader('User-Agent', $userAgent);
        }
        $request->setBody('<d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>');

        $response = $this->request($request);

        $this->assertEquals(207, $response->status);
        $this->assertNotContains('/addressbooks/' . SERVER_MOCK_DOMAIN_ID . '/gab/', $this->responseHrefs($response));

        // Discovery filtering must not prevent the administrator from reading or enabling the book.
        $response = $this->makeRequest('PROPFIND', '/addressbooks/' . SERVER_MOCK_DOMAIN_ID . '/gab.json', [
            'properties' => ['{http://open-paas.org/contacts}state']
        ]);
        $this->assertEquals(200, $response->status);
        $this->assertSame('disabled', json_decode($response->getBodyAsString(), true)['{http://open-paas.org/contacts}state']);

        $response = $this->makeRequest('PROPPATCH', '/addressbooks/' . SERVER_MOCK_DOMAIN_ID . '/gab.json', ['state' => 'enabled']);
        $this->assertEquals(204, $response->status);
        $this->assertSame('enabled', $this->carddavBackend->getAddressBooksFor('principals/domains/' . SERVER_MOCK_DOMAIN_ID)[0]['{http://open-paas.org/contacts}state']);

        $response = $this->request($request);
        $this->assertEquals(207, $response->status);
        $this->assertContains('/addressbooks/' . SERVER_MOCK_DOMAIN_ID . '/gab/', $this->responseHrefs($response));
    }

    function testGroupStateDoesNotHidePersonalBooks() {
        $this->carddavBackend->createAddressBook('principals/users/' . $this->userTestId1, 'personal', [
            '{DAV:}displayname' => 'Personal contacts',
            '{http://open-paas.org/contacts}state' => 'disabled'
        ]);
        $request = new \Sabre\HTTP\Request('PROPFIND', '/addressbooks/' . $this->userTestId1 . '/', ['Depth' => '1']);
        $request->setBody('<d:propfind xmlns:d="DAV:"><d:prop><d:displayname/></d:prop></d:propfind>');

        $response = $this->request($request);

        $this->assertEquals(207, $response->status);
        $this->assertContains('/addressbooks/' . $this->userTestId1 . '/personal/', $this->responseHrefs($response));
    }

    private function responseHrefs($response) {
        return array_map(fn($item) => $item->getHref(),
            $this->server->xml->expect('{DAV:}multistatus', $response->getBodyAsString())->getResponses());
    }
}
