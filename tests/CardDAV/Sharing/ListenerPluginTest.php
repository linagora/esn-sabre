<?php

namespace ESN\CardDAV\Sharing;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class ListenerPluginTest extends \ESN\CardDAV\PluginTestBase {
    function setUp(): void {
        parent::setUp();

        $plugin = new ListenerPlugin($this->carddavBackend);
        $this->server->addPlugin($plugin);

        $this->carddavBackend->updateInvites($this->user2Book1Id, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:' . $this->userTestEmail1,
                'principal' => 'principals/users/' . $this->userTestId1,
                'access' => SPlugin::ACCESS_READ,
                'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                'properties' => []
            ])
        ]);
    }

    function testDeleteSharedAddressBookWhenSourceIsDeleted() {
        $shareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1);
        $this->assertCount(1, $shareAddressBooks);

        // now delete the source addres book
        $this->carddavBackend->deleteAddressBook($this->user2Book1Id);

        $shareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1);
        $this->assertCount(0, $shareAddressBooks);
    }
}
