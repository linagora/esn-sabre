<?php

namespace ESN\CardDAV\Sharing;

use ESN\DAV\Sharing\Plugin as SPlugin;
use PHPUnit\Framework\Attributes\DataProvider;

require_once ESN_TEST_BASE. '/CardDAV/PluginTestBase.php';

class ShareGroupAddressBookPluginTest extends \ESN\CardDAV\PluginTestBase {
    const DOMAIN_ID = '54b64eadf6d7d8e41d263e7e';
    const ADMINISTRATOR_ID_1 = '54b64eadf6d7d8e41d263e8f';
    const ADMINISTRATOR_ID_2 = '54b64eadf6d7d8e41d263e9f';

    function setUp(): void {
        parent::setUp();

        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID),
            'name' => 'test',
            'administrators' => [
                [
                    'user_id' => new \MongoDB\BSON\ObjectId(self::ADMINISTRATOR_ID_1)
                ],
                [
                    'user_id' => new \MongoDB\BSON\ObjectId(self::ADMINISTRATOR_ID_2)
                ]
            ]
        ]);

        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::ADMINISTRATOR_ID_1),
            'firstname' => 'foo',
            'lastname' => 'bar',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                      'admin1@lng.com'
                    ]
                ]
            ],
            'domains' => [ [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ] ]
        ]);

        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::ADMINISTRATOR_ID_2),
            'firstname' => 'admin',
            'lastname' => 'admin',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                      'admin2@lng.com'
                    ]
                ]
            ],
            'domains' => [ [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ] ]
        ]);

        $this->gabId = $this->carddavBackend->createAddressBook('principals/domains/' . self::DOMAIN_ID, 'gab', [ '{DAV:}acl' => [ '{DAV:}read' ] ]);

        $plugin = new Plugin();
        $this->server->addPlugin($plugin);
    }

    function testShareGroupAddressBookForGroupAdministratorRespond405() {
        $this->authBackend->setAuthTenant(new \ESN\Utils\AuthTenant(self::ADMINISTRATOR_ID_1, self::DOMAIN_ID));

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . self::DOMAIN_ID . '/gab.json',
            [
                'dav:share-resource' => [
                    'dav:sharee' => [
                        [
                            'dav:href' => 'mailto:admin2@lng.com',
                            'dav:share-access' => SPlugin::ACCESS_READ
                        ]
                    ]
                ]
            ]
        );

        $this->assertEquals(405, $response->status);
        $this->assertStringContainsString('Can not delegate for group administrators', $response->getBodyAsString());
    }

    function testShareGroupAddressBookForGroupMemberRespond204() {
        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId('54b64eadf6d7d8e41d263e9e'),
            'firstname' => 'member',
            'lastname' => 'member',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                      'member@lng.com'
                    ]
                ]
            ],
            'domains' => [ [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ] ]
        ]);

        $this->authBackend->setAuthTenant(new \ESN\Utils\AuthTenant(self::ADMINISTRATOR_ID_1, self::DOMAIN_ID));

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . self::DOMAIN_ID . '/gab.json',
            [
                'dav:share-resource' => [
                    'dav:sharee' => [
                        [
                            'dav:href' => 'mailto:member@lng.com',
                            'dav:share-access' => SPlugin::ACCESS_READWRITE
                        ]
                    ]
                ]
            ]
        );

        $this->assertEquals(204, $response->status);
    }

    #[DataProvider('domainMembersRequestsThroughPathVariants')]
    function testUpdatingDomainMembersRightsThroughPathVariantRespond405(string $prefix, array $body) {
        $this->carddavBackend->createAddressBook('principals/domains/' . self::DOMAIN_ID, 'domain-members', []);
        $this->authBackend->setAuthTenant(new \ESN\Utils\AuthTenant(self::ADMINISTRATOR_ID_1, self::DOMAIN_ID));

        $response = $this->makeRequest(
            'POST',
            '/addressbooks/' . self::DOMAIN_ID . $prefix . 'domain-members.json',
            $body
        );

        $this->assertEquals(405, $response->status);
        $this->assertStringContainsString('Cannot update public rights or delegations for domain addressbook', $response->getBodyAsString());
    }

    static function domainMembersRequestsThroughPathVariants() {
        $bodies = [
            'share' => [
                'dav:share-resource' => [
                    'dav:sharee' => [
                        [
                            'dav:href' => 'mailto:admin2@lng.com',
                            'dav:share-access' => SPlugin::ACCESS_READWRITE
                        ]
                    ]
                ]
            ],
            'publish' => [ 'dav:publish-addressbook' => [ 'privilege' => '{DAV:}read' ] ],
            'members right' => [ 'dav:group-addressbook' => [ 'privileges' => [ '{DAV:}read', '{DAV:}write' ] ] ]
        ];
        $prefixes = [
            'plain path' => '/',
            'encoded slash' => '/%2F',
            'repeated slashes' => '///'
        ];

        return array_merge(...array_map(
            fn($prefixName) => array_combine(
                array_map(fn($bodyName) => $bodyName . ' through ' . $prefixName, array_keys($bodies)),
                array_map(fn($body) => [ $prefixes[$prefixName], $body ], array_values($bodies))
            ),
            array_keys($prefixes)
        ));
    }
}
