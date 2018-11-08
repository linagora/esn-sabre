<?php

namespace ESN\CardDAV\Sharing;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

require_once ESN_TEST_BASE . '/CardDAV/PluginTestBase.php';

class SharedAddressBookTest extends \ESN\CardDAV\PluginTestBase {
    function setUp() {
        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};
        $this->sabredb->drop();

        $this->userPrincipal = 'principals/users/5aa1f5b44efaa96afba5b12d';
        $this->carddavBackend = new \ESN\CardDAV\Backend\Esn($this->sabredb);
    }

    function testGetACLWhenSharedFromGroupAddressBook() {
        $addressBook = [
            'id' => '54b64eadf6d7d8e41d263e7e',
            'uri' => 'test',
            'principaluri' => $this->userPrincipal,
            'share_owner' => 'principals/domains/54b64eadf6d7d8e41d263e9f'
        ];
        $sharedAddressBook = new SharedAddressBook($this->carddavBackend, $addressBook);

        $this->assertEquals($sharedAddressBook->getACL(), [
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->userPrincipal,
                'protected' => true
            ]
        ]);
    }

    function testGetACLWhenSharedFromUserAddressBook() {
        $addressBook = [
            'id' => '54b64eadf6d7d8e41d263e7e',
            'uri' => 'test',
            'principaluri' => $this->userPrincipal,
            'share_owner' => 'principals/users/54b64eadf6d7d8e41d263e9f'
        ];
        $sharedAddressBook = new SharedAddressBook($this->carddavBackend, $addressBook);

        $this->assertEquals($sharedAddressBook->getACL(), [
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->userPrincipal,
                'protected' => true
            ],
            [
                'privilege' => '{DAV:}write-properties',
                'principal' => $this->userPrincipal,
                'protected' => true
            ]
        ]);
    }
}
