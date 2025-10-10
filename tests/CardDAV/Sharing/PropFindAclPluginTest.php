<?php

namespace ESN\CardDAV\Sharing;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class PropFindAclPluginTest extends \ESN\CardDAV\PluginTestBase {

    function setUp(): void {
        parent::setUp();

        $plugin = new Plugin();
        $this->server->addPlugin($plugin);
    }

    function testAclOfSharerAddressBookContainsOnlyRequesterPrincipal() {
        $this->authBackend->setPrincipal('principals/users/' . $this->userTestId2);

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
                'properties' => array('acl')
            )
        );

        $this->assertEquals(200, $response->status);
        $acl = json_decode($response->getBodyAsString(), true)['acl'];
        $this->assertEquals([
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/users/' . $this->userTestId2,
                'protected' => true
            ]
        ], $acl);
    }

    function testAclOfShareeAddressBook() {
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
                'properties' => array('acl')
            )
        );

        $this->assertEquals(200, $response->status);
        $acl = json_decode($response->getBodyAsString(), true)['acl'];
        $this->assertEquals([
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/users/' . $this->userTestId1,
                'protected' => true
            ],
            [
                'privilege' => '{DAV:}write-properties',
                'principal' => 'principals/users/' . $this->userTestId1,
                'protected' => true
            ]
        ], $acl);
    }
}
