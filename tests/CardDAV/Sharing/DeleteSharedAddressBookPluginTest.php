<?php

namespace ESN\CardDAV\Sharing;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class DeleteSharedAddressBookPluginTest extends \ESN\CardDAV\PluginTestBase {

    function setUp(): void {
        parent::setUp();

        $plugin = new Plugin();
        $this->server->addPlugin($plugin);
    }

    function testDeleteSharedAddressBookResponds204() {
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
            'DELETE',
            '/addressbooks/' . $this->userTestId1 . '/' . $shareAddressBook['uri'] . '.json'
        );

        $this->assertEquals(204, $response->status);
        $this->assertCount(0, $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1));
    }
}
