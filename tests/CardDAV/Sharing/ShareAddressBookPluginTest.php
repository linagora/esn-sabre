<?php

namespace ESN\CardDAV\Sharing;

use ESN\DAV\Sharing\Plugin as SPlugin;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class ShareAddressBookPluginTest extends \ESN\CardDAV\PluginTestBase {

    function setUp(): void {
        parent::setUp();

        $plugin = new Plugin();
        $this->server->addPlugin($plugin);
    }

    function testShareAddressBookSuccessResponds204() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/' . $this->userTestId1 . '/book1.json',
        ));

        $body = array(
            'dav:share-resource' => array(
                'dav:sharee' => array([
                    'dav:href' => 'mailto:'.$this->userTestEmail2,
                    'dav:share-access' => SPlugin::ACCESS_READ
                ])
            )
        );
        $request->setBody(json_encode($body));

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);

        $shareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId2);
        $this->assertCount(1, $shareAddressBooks);
        $this->assertEquals($shareAddressBooks[0]['share_access'], SPlugin::ACCESS_READ);
        $this->assertEquals($shareAddressBooks[0]['share_owner'], 'principals/users/' . $this->userTestId1);
    }

    function testShareAddressBookWithUnsupportedAccess() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/' . $this->userTestId1 . '/book1.json',
        ));

        $body = array(
            'dav:share-resource' => array(
                'dav:sharee' => array([
                    'dav:href' => 'mailto:'.$this->userTestEmail2,
                    'dav:share-access' => SPlugin::ACCESS_SHAREDOWNER
                ])
            )
        );
        $request->setBody(json_encode($body));

        $response = $this->request($request);
        $this->assertEquals(204, $response->status);

        $shareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId2);
        $this->assertCount(0, $shareAddressBooks);
    }

    function testShareAddressBookWithOwnerPrincipalShareeResponds204() {
        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId1 . '/book1.json',
            ['dav:share-resource' => ['dav:sharee' => [
                ['dav:href' => 'mailto:' . $this->userTestEmail2, 'dav:share-access' => SPlugin::ACCESS_READ],
                ['dav:href' => 'principals/users/' . $this->userTestId1, 'dav:share-access' => SPlugin::ACCESS_SHAREDOWNER]
            ]]]
        );

        $this->assertEquals(204, $response->status);
        $this->assertCount(1, $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId2));
    }

    function testShareAddressBookWithUnknownPrincipalHrefResponds400() {
        $response = $this->shareBook1With('principals/users/54b64eadf6d7d8e41d263e00', SPlugin::ACCESS_READ);

        $this->assertEquals(400, $response->status);
        $this->assertStringContainsString('dav:href must be a mailto: URI or a principal', $response->getBodyAsString());
    }

    function testShareAddressBookWithMalformedPrincipalIdHrefResponds400() {
        $response = $this->shareBook1With('principals/users/}2', SPlugin::ACCESS_READ);

        $this->assertEquals(400, $response->status);
        $this->assertStringContainsString('dav:href must be a mailto: URI or a principal', $response->getBodyAsString());
    }

    function testShareAddressBookWithUnknownAccessResponds400() {
        $response = $this->shareBook1With('mailto:' . $this->userTestEmail2, 99);

        $this->assertEquals(400, $response->status);
        $this->assertStringContainsString('dav:share-access must be one of', $response->getBodyAsString());
        $this->assertCount(0, $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId2));
    }

    function testShareAddressBookWithNonNumericAccessResponds400() {
        $this->shareBook1With('mailto:' . $this->userTestEmail2, SPlugin::ACCESS_READ);

        $response = $this->shareBook1With('mailto:' . $this->userTestEmail2, 'x');

        $this->assertEquals(400, $response->status);
        $shareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId2);
        $this->assertCount(1, $shareAddressBooks);
        $this->assertEquals(SPlugin::ACCESS_READ, $shareAddressBooks[0]['share_access']);
    }

    function testShareAddressBookWithNonPrincipalHrefResponds400() {
        $response = $this->shareBook1With('javascript:alert(1)', SPlugin::ACCESS_READ);

        $this->assertEquals(400, $response->status);
        $this->assertStringContainsString('dav:href must be a mailto: URI or a principal', $response->getBodyAsString());
    }

    function testShareAddressBookWithInvalidMailtoHrefResponds400() {
        $response = $this->shareBook1With('mailto:not-an-email', SPlugin::ACCESS_READ);

        $this->assertEquals(400, $response->status);
        $this->assertStringContainsString('dav:href is not a valid mailto: URI', $response->getBodyAsString());
    }

    function testShareAddressBookWithMissingHrefResponds400() {
        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId1 . '/book1.json',
            ['dav:share-resource' => ['dav:sharee' => [['dav:share-access' => SPlugin::ACCESS_READ]]]]
        );

        $this->assertEquals(400, $response->status);
        $this->assertStringContainsString('dav:href must be a non-empty string', $response->getBodyAsString());
    }

    function testShareAddressBookWithNonArrayShareeResponds400() {
        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId1 . '/book1.json',
            ['dav:share-resource' => ['dav:sharee' => 'mailto:' . $this->userTestEmail2]]
        );

        $this->assertEquals(400, $response->status);
        $this->assertStringContainsString('dav:sharee must be an array', $response->getBodyAsString());
    }

    function testPostMalformedJsonOnAddressBookResponds400() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/' . $this->userTestId1 . '/book1.json',
        ));
        $request->setBody('{x');

        $response = $this->request($request);

        $this->assertEquals(400, $response->status);
        $this->assertStringContainsString('Invalid JSON body', $response->getBodyAsString());
    }

    private function shareBook1With($href, $access) {
        return $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId1 . '/book1.json',
            array(
                'dav:share-resource' => array(
                    'dav:sharee' => array([
                        'dav:href' => $href,
                        'dav:share-access' => $access
                    ])
                )
            )
        );
    }

    function testShareAddressBookToOwnerResponds400() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/' . $this->userTestId1 . '/book1.json',
        ));

        $body = array(
            'dav:share-resource' => array(
                'dav:sharee' => array([
                    'dav:href' => 'mailto:'.$this->userTestEmail1,
                    'dav:share-access' => SPlugin::ACCESS_READ
                ])
            )
        );
        $request->setBody(json_encode($body));

        $response = $this->request($request);
        $this->assertEquals(400, $response->status);
        $this->assertStringContainsString('Cannot delegate an address book to yourself', $response->getBodyAsString());

        $shareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1);
        $this->assertCount(0, $shareAddressBooks);
    }

    function testShareAddressBookOfOtherUserResponds403() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/' . $this->userTestId2 . '/user2book1.json',
        ));

        $body = array(
            'dav:share-resource' => array(
                'dav:sharee' => array([
                    'dav:href' => 'mailto:'.$this->userTestEmail1,
                    'dav:share-access' => SPlugin::ACCESS_READ
                ])
            )
        );
        $request->setBody(json_encode($body));
        $response = $this->request($request);

        $this->assertEquals(403, $response->status);
        $this->assertStringContainsString('User did not have the required privileges ({DAV:}share) for path', $response->getBodyAsString());
    }

    function testShareAddressBookOfOtherUserWithAdminAccessResponds204() {
        $this->carddavBackend->updateInvites($this->user2Book1Id, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:' . $this->userTestEmail1,
                'principal' => 'principals/users/' . $this->userTestId1,
                'access' => SPlugin::ACCESS_ADMINISTRATION,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                'properties' => []
            ])
        ]);

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'POST',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/addressbooks/' . $this->userTestId2 . '/user2book1.json',
        ));

        $body = array(
            'dav:share-resource' => array(
                'dav:sharee' => array([
                    'dav:href' => 'mailto:'.$this->userTestEmail3,
                    'dav:share-access' => SPlugin::ACCESS_READ
                ])
            )
        );
        $request->setBody(json_encode($body));
        $response = $this->request($request);

        $this->assertEquals(204, $response->status);

        $shareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId3);
        $this->assertCount(1, $shareAddressBooks);
        $this->assertEquals($shareAddressBooks[0]['share_access'], SPlugin::ACCESS_READ);
        $this->assertEquals($shareAddressBooks[0]['share_owner'], 'principals/users/' . $this->userTestId2); // owner is still user test 2
    }

    function testShareAddressBookOfOtherUserWithAdminAccessCanKeepOwnDelegationWhenSharing() {
        $this->carddavBackend->updateInvites($this->user2Book1Id, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:' . $this->userTestEmail1,
                'principal' => 'principals/users/' . $this->userTestId1,
                'access' => SPlugin::ACCESS_ADMINISTRATION,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                'properties' => []
            ])
        ]);

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId2 . '/user2book1.json',
            array(
                'dav:share-resource' => array(
                    'dav:sharee' => array(
                        [
                            'dav:href' => 'mailto:'.$this->userTestEmail3,
                            'dav:share-access' => SPlugin::ACCESS_ADMINISTRATION
                        ],
                        [
                            'dav:href' => 'mailto:'.$this->userTestEmail1,
                            'dav:share-access' => SPlugin::ACCESS_ADMINISTRATION
                        ]
                    )
                )
            )
        );

        $this->assertEquals(204, $response->status);

        $currentUserShareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1);
        $this->assertCount(1, $currentUserShareAddressBooks);
        $this->assertEquals(SPlugin::ACCESS_ADMINISTRATION, $currentUserShareAddressBooks[0]['share_access']);
        $this->assertEquals('principals/users/' . $this->userTestId2, $currentUserShareAddressBooks[0]['share_owner']);

        $newShareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId3);
        $this->assertCount(1, $newShareAddressBooks);
        $this->assertEquals(SPlugin::ACCESS_ADMINISTRATION, $newShareAddressBooks[0]['share_access']);
        $this->assertEquals('principals/users/' . $this->userTestId2, $newShareAddressBooks[0]['share_owner']);
    }

    function testShareAddressBookOfOtherUserWithAdminAccessToSelfResponds400() {
        $this->carddavBackend->updateInvites($this->user2Book1Id, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:' . $this->userTestEmail1,
                'principal' => 'principals/users/' . $this->userTestId1,
                'access' => SPlugin::ACCESS_ADMINISTRATION,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                'properties' => []
            ])
        ]);

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId2 . '/user2book1.json',
            array(
                'dav:share-resource' => array(
                    'dav:sharee' => array([
                        'dav:href' => 'mailto:'.$this->userTestEmail1,
                        'dav:share-access' => SPlugin::ACCESS_READ
                    ])
                )
            )
        );

        $this->assertEquals(400, $response->status);
        $this->assertStringContainsString('Cannot delegate an address book to yourself', $response->getBodyAsString());

        $shareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1);
        $this->assertCount(1, $shareAddressBooks);
        $this->assertEquals(SPlugin::ACCESS_ADMINISTRATION, $shareAddressBooks[0]['share_access']);
        $this->assertEquals('principals/users/' . $this->userTestId2, $shareAddressBooks[0]['share_owner']);
    }

    function testShareASharedAddressBookResponds403() {
        $this->carddavBackend->updateInvites($this->user2Book1Id, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:' . $this->userTestEmail1,
                'principal' => 'principals/users/' . $this->userTestId1,
                'access' => SPlugin::ACCESS_READ,
                'properties' => []
            ])
        ]);

        // now trying to share a shared address book
        $shareAddressBook = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1)[0];

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId1 . '/' . $shareAddressBook['uri'] . '.json',
            array(
                'dav:share-resource' => array(
                    'dav:sharee' => array([
                        'dav:href' => 'mailto:'.$this->userTestEmail2,
                        'dav:share-access' => SPlugin::ACCESS_READ
                    ])
                )
            )
        );

        $this->assertEquals(403, $response->status);
        $this->assertStringContainsString('User did not have the required privileges ({DAV:}share) for path', $response->getBodyAsString());
    }
}
