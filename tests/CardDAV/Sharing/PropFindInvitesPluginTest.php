<?php

namespace ESN\CardDAV\Sharing;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class PropFindInvitesPluginTest extends \ESN\CardDAV\PluginTestBase {

    function setUp(): void {
        parent::setUp();

        $plugin = new Plugin();
        $this->server->addPlugin($plugin);
    }

    function testPropFindInviteslOfSharerAddressBook() {
        $this->carddavBackend->updateInvites($this->user1Book1Id, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:' . $this->userTestEmail2,
                'principal' => 'principals/users/' . $this->userTestId2,
                'access' => SPlugin::ACCESS_READ,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                'properties' => []
            ])
        ]);

        $response = $this->makeRequest(
            'PROPFIND',
            '/addressbooks/' . $this->userTestId1 . '/book1.json',
            array(
                'properties' => array('{DAV:}invite')
            )
        );

        $this->assertEquals(200, $response->status);
        $invites = json_decode($response->getBodyAsString(), true)['{DAV:}invite'];
        $this->assertEquals([
            [
                'principal' => 'principals/users/' . $this->userTestId2,
                'href' => 'mailto:' . $this->userTestEmail2,
                'properties' => array (),
                'access' => SPlugin::ACCESS_READ,
                'comment' => null,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED

            ],
            [
                'principal' => 'principals/users/' . $this->userTestId1,
                'href' => 'principals/users/' . $this->userTestId1,
                'properties' => array (),
                'access' => SPlugin::ACCESS_SHAREDOWNER,
                'comment' => null,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED
            ]
        ], $invites);
    }

    function testPropFindInviteslOfShareeAddressBook() {
        $this->carddavBackend->updateInvites($this->user2Book1Id, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:' . $this->userTestEmail1,
                'principal' => 'principals/users/' . $this->userTestId1,
                'access' => SPlugin::ACCESS_READ,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                'properties' => []
            ])
        ]);

        $shareAddressBook = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1)[0];

        $response = $this->makeRequest(
            'PROPFIND',
            '/addressbooks/' . $this->userTestId1 . '/' . $shareAddressBook['uri'] . '.json',
            array(
                'properties' => array('{DAV:}invite')
            )
        );

        $this->assertEquals(200, $response->status);
        $invites = json_decode($response->getBodyAsString(), true)['{DAV:}invite'];
        $this->assertEquals([
            [
                'principal' => 'principals/users/' . $this->userTestId1,
                'href' => 'mailto:' . $this->userTestEmail1,
                'properties' => array (),
                'access' => SPlugin::ACCESS_READ,
                'comment' => null,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED

            ]
        ], $invites);
    }
}
