<?php

namespace ESN\CardDAV\Sharing;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class ShareAddressBookPluginTest extends \ESN\CardDAV\PluginTestBase {

    function setUp() {
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
        $this->assertContains('User did not have the required privileges ({DAV:}share) for path', $response->getBodyAsString());
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
        $this->assertContains('User did not have the required privileges ({DAV:}share) for path', $response->getBodyAsString());
    }
}
