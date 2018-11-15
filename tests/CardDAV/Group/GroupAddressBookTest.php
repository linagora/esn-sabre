<?php

namespace ESN\CardDAV\Group;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

require_once ESN_TEST_BASE . '/CardDAV/PluginTestBase.php';

class GroupAddressBookTest extends \ESN\CardDAV\PluginTestBase {
    const DOMAIN_ID = '54b64eadf6d7d8e41d263e7e';
    const USER_ID = '54b64eadf6d7d8e41d263e8f';
    const ADMINISTRATOR_ID = '54b64eadf6d7d8e41d263e9f';

    function setUp() {
        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};
        $this->sabredb->drop();

        $this->domainPrincipal = 'principals/domains/' . self::DOMAIN_ID;
        $this->carddavBackend = new \ESN\CardDAV\Backend\Esn($this->sabredb);

        $this->gabID = $this->carddavBackend->createAddressBook($this->domainPrincipal, 'GAB', [ '{DAV:}acl' => [ '{DAV:}write' ] ]);
        $this->addressBook = [
            'id' => $this->gabID,
            'uri' => $this->domainPrincipal,
            'administrators' => [ 'principals/users/' . self::ADMINISTRATOR_ID ],
            'members' => [
                'principals/users/' . self::ADMINISTRATOR_ID,
                'principals/users/' . self::USER_ID
            ]
        ];
        $this->book = new GroupAddressBook($this->carddavBackend, $this->addressBook);
    }

    function testGetACL() {
        // Share GAB for user
        $this->carddavBackend->updateInvites(
            $this->gabID,
            [
                new \Sabre\DAV\Xml\Element\Sharee([
                    'href' => self::USER_ID,
                    'access' => SPlugin::ACCESS_READ,
                    'inviteStatus' => SPlugin::INVITE_ACCEPTED,
                    'properties' => [],
                    'principal' => 'principals/users/' . self::USER_ID
                ])
            ]
        );

        $this->assertEquals($this->book->getACL(), [
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/users/' . self::USER_ID,
                'protected' => true
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => 'principals/users/' . self::ADMINISTRATOR_ID,
                'protected' => true
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => 'principals/users/' . self::ADMINISTRATOR_ID,
                'protected' => true
            ],
            [
                'privilege' => '{DAV:}share',
                'principal' => 'principals/users/' . self::ADMINISTRATOR_ID,
                'protected' => true
            ]
        ]);
    }
}
