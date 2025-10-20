<?php

namespace ESN\CardDAV\Sharing;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class ListAddressBookPluginTest extends \ESN\CardDAV\PluginTestBase {

    function setUp(): void {
        parent::setUp();

        $plugin = new Plugin();
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

        $this->carddavBackend->updateInvites($this->user3Book1Id, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:' . $this->userTestEmail1,
                'principal' => 'principals/users/' . $this->userTestId1,
                'access' => SPlugin::ACCESS_READWRITE,
                'inviteStatus' => SPlugin::INVITE_NORESPONSE,
                'properties' => []
            ])
        ]);
    }

    function testListSharedAddressBooks() {
        $shareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1);

        $response = $this->makeRequest(
            'GET',
            '/addressbooks/' . $this->userTestId1 . '.json?shared=true'
        );

        $this->assertEquals(200, $response->status);
        $addressBooks = json_decode($response->getBodyAsString(), true)['_embedded']['dav:addressbook'];

        $this->assertCount(2, $addressBooks);
        $this->assertEquals('/addressbooks/' . $this->userTestId1 . '/' . $shareAddressBooks[0]['uri'] . '.json', $addressBooks[0]['_links']['self']['href']);
        $this->assertEquals('/addressbooks/' . $this->userTestId1 . '/' . $shareAddressBooks[1]['uri'] . '.json', $addressBooks[1]['_links']['self']['href']);
    }

    function testListSharedAddressBooksFilteredByShareOwner() {
        $shareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1);

        $response = $this->makeRequest(
            'GET',
            '/addressbooks/' . $this->userTestId1 . '.json?shared=true&shareOwner='.$this->userTestId2
        );

        $this->assertEquals(200, $response->status);
        $addressBooks = json_decode($response->getBodyAsString(), true)['_embedded']['dav:addressbook'];

        $this->assertCount(1, $addressBooks);
        $this->assertEquals('/addressbooks/' . $this->userTestId1 . '/' . $shareAddressBooks[0]['uri'] . '.json', $addressBooks[0]['_links']['self']['href']);
    }

    function testListSharedAddressBooksFilteredByInviteStatus() {
        $shareAddressBooks = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId1);

        $response = $this->makeRequest(
            'GET',
            '/addressbooks/' . $this->userTestId1 . '.json?shared=true&inviteStatus='.SPlugin::INVITE_ACCEPTED
        );

        $this->assertEquals(200, $response->status);
        $addressBooks = json_decode($response->getBodyAsString(), true)['_embedded']['dav:addressbook'];

        $this->assertCount(1, $addressBooks);
        $this->assertEquals('/addressbooks/' . $this->userTestId1 . '/' . $shareAddressBooks[0]['uri'] . '.json', $addressBooks[0]['_links']['self']['href']);
    }
}
