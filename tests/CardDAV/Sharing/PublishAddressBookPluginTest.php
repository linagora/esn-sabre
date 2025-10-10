<?php

namespace ESN\CardDAV\Sharing;

use \ESN\DAV\Sharing\Plugin as EsnSharingPlugin;
use \Sabre\DAV\Sharing\Plugin as SPlugin;

require_once ESN_TEST_BASE . '/CardDAV/PluginTestBase.php';

class PublishAddressBook extends \ESN\CardDAV\PluginTestBase {

    function setUp(): void {
        parent::setUp();

        $plugin = new Plugin();
        $this->server->addPlugin($plugin);
    }

    function testPublishingAddressbookWithUnauthorizedUserResponds403() {
        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId2 . '/user2book1.json',
            array(
                'dav:publish-addressbook' => array(
                    'privilege' => '{DAV:}write'
                )
            )
        );

        $this->assertEquals(403, $response->status);
        $this->assertStringContainsString('User did not have the required privileges ({DAV:}share) for path', $response->getBodyAsString());
    }

    function testPublishingAddressbookWithInvalidPrivilegeResponds400() {
        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId1 . '/book1.json',
            array(
                'dav:publish-addressbook' => array(
                    'privilege' => '{DAV:}invalid'
                )
            )
        );

        $this->assertEquals(400, $response->status);
        $this->assertStringContainsString('Privilege must be either {DAV:}read or {DAV:}write', $response->getBodyAsString());
    }

    function testPublishingAddressbookWithOwnerResponds204() {
        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId1 . '/' . 'book1.json',
            array(
                'dav:publish-addressbook' => array(
                    'privilege' => '{DAV:}write'
                )
            )
        );
        $this->assertEquals(204, $response->status);

        $updatedPublicRight = $this->carddavBackend->getAddressBookPublicRight($this->user1Book1Id);

        $this->assertEquals('{DAV:}write', $updatedPublicRight);
    }

    function testPublishingAddressbookWithDelegatedAdminResponds204() {
        $this->carddavBackend->updateInvites($this->user2Book1Id, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:' . $this->userTestEmail1,
                'principal' => 'principals/users/' . $this->userTestId1,
                'access' => EsnSharingPlugin::ACCESS_ADMINISTRATION,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                'properties' => []
            ])
        ]);

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId2 . '/user2book1.json',
            array(
                'dav:publish-addressbook' => array(
                    'privilege' => '{DAV:}write'
                )
            )
        );
        $this->assertEquals(204, $response->status);

        $updatedPublicRight = $this->carddavBackend->getAddressBookPublicRight($this->user2Book1Id);

        $this->assertEquals('{DAV:}write', $updatedPublicRight);
    }
}
