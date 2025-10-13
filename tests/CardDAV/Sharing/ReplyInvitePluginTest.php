<?php

namespace ESN\CardDAV\Sharing;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class ReplyInvitePluginTest extends \ESN\CardDAV\PluginTestBase {

    function setUp(): void {
        parent::setUp();

        $plugin = new Plugin();
        $this->server->addPlugin($plugin);
    }

    function testReplyInviteAcceptResponds204() {
        $this->carddavBackend->updateInvites($this->user2Book1Id, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:' . $this->userTestEmail1,
                'principal' => 'principals/users/' . $this->userTestId1,
                'access' => SPlugin::ACCESS_READ,
                'properties' => []
            ])
        ]);

        $shareAddressBook = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1)[0];

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId1 . '/' . $shareAddressBook['uri'] . '.json',
            array(
                'dav:invite-reply' => array(
                    'dav:invite-accepted' => true
                )
            )
        );

        $this->assertEquals(204, $response->status);

        $shareAddressBook = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1)[0];

        $this->assertEquals(SPlugin::INVITE_ACCEPTED, $shareAddressBook['share_invitestatus']);
    }

    function testReplyInviteWithSlugToSetAddressBookDisplayName() {
        $this->carddavBackend->updateInvites($this->user2Book1Id, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:' . $this->userTestEmail1,
                'principal' => 'principals/users/' . $this->userTestId1,
                'access' => SPlugin::ACCESS_READ,
                'properties' => []
            ])
        ]);

        $shareAddressBook = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1)[0];

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . $this->userTestId1 . '/' . $shareAddressBook['uri'] . '.json',
            array(
                'dav:invite-reply' => array(
                    'dav:invite-accepted' => true,
                    'dav:slug' => 'My new AB name'
                )
            )
        );

        $this->assertEquals(204, $response->status);

        $shareAddressBook = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1)[0];

        $this->assertEquals('My new AB name', $shareAddressBook['{DAV:}displayname']);
    }
}
