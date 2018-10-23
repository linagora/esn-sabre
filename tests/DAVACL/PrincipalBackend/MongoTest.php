<?php

namespace ESN\DAVACL\PrincipalBackend;

class MongoTest extends \PHPUnit_Framework_TestCase {
    protected static $esndb;

    const USER_ID = '54313fcc398fef406b0041b6';
    const USER_WITH_TWO_ACCOUNTS_ID = '54313fcc398fef406b0041b7';
    const USER_WITH_ACCOUNT_ID = '54313fcc398fef406b0041b8';
    const USER_WITH_NO_EMAIL_ID = '54313fcc398fef406b0041b9';
    const USER_WITH_NO_EMAIL_ACCOUNT_ID = '54313fcc398fef406b0041c0';
    const USER_WITH_NO_FIRSTNAME = '54313fcc398fef406b0041c1';
    const USER_WITH_NO_LASTNAME = '54313fcc398fef406b0041c2';
    const USER_DOMAIN_ADMIN_ID = '54313fcc398fef406b0041c3';
    const COMMUNITY_ID = '54313fcc398fef406b0041b4';
    const PROJECT_ID = '54b64eadf6d7d8e41d263e0f';
    const RESOURCE_ID = '82113fcc398fef406b0041b7';
    const DOMAIN_ID = '5a095e2c46b72521d03f6d75';

    static function setUpBeforeClass() {
        $mc = new \MongoDB\Client(ESN_MONGO_ESNURI);
        self::$esndb = $mc->{ESN_MONGO_ESNDB};
        self::$esndb->drop();

        self::$esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID),
            'name' => 'test',
            'administrators' => [
                [
                    'user_id' => self::USER_DOMAIN_ADMIN_ID
                ]
            ]
        ]);
        self::$esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER_ID),
            'firstname' => 'first',
            'lastname' => 'last',
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ 'user@example.com' ] ]
            ],
            'domains' => [
                [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ]
            ]
        ]);
        self::$esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER_WITH_NO_LASTNAME),
            'firstname' => 'Originalname',
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ 'userNoLast@example.com' ] ]
            ],
            'domains' => [
                [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ]
            ]
        ]);
        self::$esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER_WITH_NO_FIRSTNAME),
            'lastname' => 'lastname',
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ 'userNoFirst@example.com' ] ]
            ],
            'domains' => [
                [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ]
            ]
        ]);
        self::$esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER_WITH_ACCOUNT_ID),
            'firstname' => 'withAccount',
            'lastname' => 'last',
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ 'userWithAccount@example.com' ] ]
            ],
            'domains' => [
                [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ]
            ]
        ]);
        self::$esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER_WITH_TWO_ACCOUNTS_ID),
            'firstname' => 'withTwoAccounts',
            'lastname' => 'last',
            'accounts' => [
                [ 'type' => 'twitter' ],
                [ 'type' => 'email', 'emails' => [ 'userWithTwoAccounts@example.com' ] ]
            ],
            'domains' => [
                [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ]
            ]
        ]);
        self::$esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER_WITH_NO_EMAIL_ID),
            'firstname' => 'withNoEmail',
            'lastname' => 'last',
            'domains' => [
                [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ]
            ]
        ]);
        self::$esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER_WITH_NO_EMAIL_ACCOUNT_ID),
            'firstname' => 'withNoEmailAccount',
            'lastname' => 'last',
            'accounts' => [
                [ 'type' => 'twitter' ]
            ],
            'domains' => [
                [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ]
            ]
        ]);
        self::$esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::USER_DOMAIN_ADMIN_ID),
            'firstname' => 'admin',
            'lastname' => 'admin',
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ 'admin@example.com' ] ]
            ],
            'domains' => [
                [ 'domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID) ]
            ]
        ]);
        self::$esndb->communities->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::COMMUNITY_ID),
            'title' => 'community',
            'members' => [
              [
                'member' => [
                  'objectType' => 'user',
                  'id' => new \MongoDB\BSON\ObjectId(self::USER_ID)
                ]
              ]
            ]
        ]);
        self::$esndb->projects->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::PROJECT_ID),
            'title' => 'project',
            'members' => [
              [
                'member' => [
                  'objectType' => 'user',
                  'id' => new \MongoDB\BSON\ObjectId(self::USER_ID)
                ]
              ]
            ]
        ]);

        self::$esndb->resources->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::RESOURCE_ID),
            'name' => 'resource',
            'domain' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID)
        ]);
    }

    static function tearDownAfterClass() {
        self::$esndb->drop();
        self::$esndb = null;
    }

    function testInvalidPrincipal() {
        $backend = new Mongo(self::$esndb);
        $principals = $backend->getPrincipalsByPrefix('unknown');
        $this->assertEquals(count($principals), 0);

        $principals = $backend->getPrincipalsByPrefix('principals/unknown');
        $this->assertEquals(count($principals), 0);

        $principals = $backend->getPrincipalsByPrefix('principals/users/unknown');
        $this->assertEquals(count($principals), 0);

        $principal = $backend->getPrincipalByPath('principals/users/54315d8c398fef1c6d0041a7');
        $this->assertNull($principal);

        $principal = $backend->getPrincipalByPath('principals/users');
        $this->assertNull($principal);
    }

    function testUserPrincipals() {
        $backend = new Mongo(self::$esndb);

        $principals = $backend->getPrincipalsByPrefix('principals/users');
        $principal = $backend->getPrincipalByPath('principals/users/' . self::USER_ID);
        $principalWithAccount = $backend->getPrincipalByPath('principals/users/' . self::USER_WITH_ACCOUNT_ID);
        $principalWithTwoAccounts = $backend->getPrincipalByPath('principals/users/' . self::USER_WITH_TWO_ACCOUNTS_ID);
        $principalWithNoEmail = $backend->getPrincipalByPath('principals/users/' . self::USER_WITH_NO_EMAIL_ID);
        $principalWithNoEmailAccount = $backend->getPrincipalByPath('principals/users/' . self::USER_WITH_NO_EMAIL_ACCOUNT_ID);
        $principalWithNoFirstname = $backend->getPrincipalByPath('principals/users/' . self::USER_WITH_NO_FIRSTNAME);
        $principalWithNoLastname = $backend->getPrincipalByPath('principals/users/' . self::USER_WITH_NO_LASTNAME);
        $principalAdmin = $backend->getPrincipalByPath('principals/users/' . self::USER_DOMAIN_ADMIN_ID);
        $this->assertEquals(count($principals), 8);

        $expected = [
            'uri' => 'principals/users/' . self::USER_ID,
            'id' => self::USER_ID,
            '{DAV:}displayname' => 'first last',
            '{http://sabredav.org/ns}email-address' => 'user@example.com',
            'groupPrincipals' => [
                [
                    'uri' => 'principals/communities/' . self::COMMUNITY_ID,
                    'administrators' => []
                ],
                [
                    'uri' => 'principals/projects/' . self::PROJECT_ID,
                    'administrators' => []
                ],
                [
                    'uri' => 'principals/domains/' . self::DOMAIN_ID,
                    'administrators' => ['principals/users/' . self::USER_DOMAIN_ADMIN_ID]
                ]
            ]
        ];
        $expectedWithNoEmail = [
            'uri' => 'principals/users/' . self::USER_WITH_NO_EMAIL_ID,
            'id' => self::USER_WITH_NO_EMAIL_ID,
            '{DAV:}displayname' => 'withNoEmail last',
            '{http://sabredav.org/ns}email-address' => null,
            'groupPrincipals' => [
                [
                    'uri' => 'principals/domains/' . self::DOMAIN_ID,
                    'administrators' => ['principals/users/' . self::USER_DOMAIN_ADMIN_ID]
                ]
            ]
        ];
        $expectedWithNoEmailAccount = [
            'uri' => 'principals/users/' . self::USER_WITH_NO_EMAIL_ACCOUNT_ID,
            'id' => self::USER_WITH_NO_EMAIL_ACCOUNT_ID,
            '{DAV:}displayname' => 'withNoEmailAccount last',
            '{http://sabredav.org/ns}email-address' => null,
            'groupPrincipals' => [
                [
                    'uri' => 'principals/domains/' . self::DOMAIN_ID,
                    'administrators' => ['principals/users/' . self::USER_DOMAIN_ADMIN_ID]
                ]
            ]
        ];
        $expectedWithNoLastname = [
            'uri' => 'principals/users/' . self::USER_WITH_NO_LASTNAME,
            'id' => self::USER_WITH_NO_LASTNAME,
            '{DAV:}displayname' => 'Originalname',
            '{http://sabredav.org/ns}email-address' => 'userNoLast@example.com',
            'groupPrincipals' => [
                [
                    'uri' => 'principals/domains/' . self::DOMAIN_ID,
                    'administrators' => ['principals/users/' . self::USER_DOMAIN_ADMIN_ID]
                ]
            ]
        ];
        $expectedWithNoFirstname = [
            'uri' => 'principals/users/' . self::USER_WITH_NO_FIRSTNAME,
            'id' => self::USER_WITH_NO_FIRSTNAME,
            '{DAV:}displayname' => ' lastname',
            '{http://sabredav.org/ns}email-address' => 'userNoFirst@example.com',
            'groupPrincipals' => [
                [
                    'uri' => 'principals/domains/' . self::DOMAIN_ID,
                    'administrators' => ['principals/users/' . self::USER_DOMAIN_ADMIN_ID]
                ]
            ]
        ];
        $expectedWithAccount = [
            'uri' => 'principals/users/' . self::USER_WITH_ACCOUNT_ID,
            'id' => self::USER_WITH_ACCOUNT_ID,
            '{DAV:}displayname' => 'withAccount last',
            '{http://sabredav.org/ns}email-address' => 'userWithAccount@example.com',
            'groupPrincipals' => [
                [
                    'uri' => 'principals/domains/' . self::DOMAIN_ID,
                    'administrators' => ['principals/users/' . self::USER_DOMAIN_ADMIN_ID]
                ]
            ]
        ];
        $expectedWithTwoAccounts = [
            'uri' => 'principals/users/' . self::USER_WITH_TWO_ACCOUNTS_ID,
            'id' => self::USER_WITH_TWO_ACCOUNTS_ID,
            '{DAV:}displayname' => 'withTwoAccounts last',
            '{http://sabredav.org/ns}email-address' => 'userWithTwoAccounts@example.com',
            'groupPrincipals' => [
                [
                    'uri' => 'principals/domains/' . self::DOMAIN_ID,
                    'administrators' => ['principals/users/' . self::USER_DOMAIN_ADMIN_ID]
                ]
            ]
        ];
        $expectedAdmin = [
            'uri' => 'principals/users/' . self::USER_DOMAIN_ADMIN_ID,
            'id' => self::USER_DOMAIN_ADMIN_ID,
            '{DAV:}displayname' => 'admin admin',
            '{http://sabredav.org/ns}email-address' => 'admin@example.com',
            'groupPrincipals' => [
                [
                    'uri' => 'principals/domains/' . self::DOMAIN_ID,
                    'administrators' => ['principals/users/' . self::USER_DOMAIN_ADMIN_ID]
                ]
            ],
            'adminForDomains' => [ self::DOMAIN_ID ]
        ];

        $this->assertEquals($expected, $principals[0]);
        $this->assertEquals($expected, $principal);
        $this->assertEquals($expectedWithNoEmail, $principalWithNoEmail);
        $this->assertEquals($expectedWithNoEmailAccount, $principalWithNoEmailAccount);
        $this->assertEquals($expectedWithNoFirstname, $principalWithNoFirstname);
        $this->assertEquals($expectedWithNoLastname, $principalWithNoLastname);
        $this->assertEquals($expectedWithAccount, $principalWithAccount);
        $this->assertEquals($expectedWithTwoAccounts, $principalWithTwoAccounts);
        $this->assertEquals($expectedAdmin, $principalAdmin);

        // Extra check to make sure no mongo ids are used
        $this->assertSame($expected['id'], $principals[0]['id']);
        $this->assertSame($expected['id'], $principal['id']);
    }

    function testCommunityPrincipalsByPrefix() {
        $backend = new Mongo(self::$esndb);

        $principals = $backend->getPrincipalsByPrefix('principals/communities');
        $principal = $backend->getPrincipalByPath('principals/communities/' . self::COMMUNITY_ID);
        $this->assertEquals(count($principals), 1);

        $expected = [
            'uri' => 'principals/communities/' . self::COMMUNITY_ID,
            'id' => self::COMMUNITY_ID,
            '{DAV:}displayname' => 'community',
            'groupPrincipals' => []
        ];
        $this->assertEquals($expected, $principals[0]);
        $this->assertEquals($expected, $principal);

        // Extra check to make sure no mongo ids are used
        $this->assertSame($expected['id'], $principals[0]['id']);
        $this->assertSame($expected['id'], $principal['id']);
    }

    function testDomainPrincipalsByPrefix() {
        $backend = new Mongo(self::$esndb);

        $principals = $backend->getPrincipalsByPrefix('principals/domains');
        $principal = $backend->getPrincipalByPath('principals/domains/' . self::DOMAIN_ID);
        $this->assertEquals(count($principals), 1);

        $expected = [
            'uri' => 'principals/domains/' . self::DOMAIN_ID,
            'id' => self::DOMAIN_ID,
            '{DAV:}displayname' => 'test',
            'groupPrincipals' => [],
            'administrators' => [
                'principals/users/' . self::USER_DOMAIN_ADMIN_ID
            ]
        ];
        $this->assertEquals($expected, $principals[0]);
        $this->assertEquals($expected, $principal);

        // Extra check to make sure no mongo ids are used
        $this->assertSame($expected['id'], $principals[0]['id']);
        $this->assertSame($expected['id'], $principal['id']);
    }

    function testProjectPrincipalsByPrefix() {
        $backend = new Mongo(self::$esndb);

        $principals = $backend->getPrincipalsByPrefix('principals/projects');
        $principal = $backend->getPrincipalByPath('principals/projects/' . self::PROJECT_ID);
        $this->assertEquals(count($principals), 1);

        $expected = [
            'uri' => 'principals/projects/' . self::PROJECT_ID,
            'id' => self::PROJECT_ID,
            '{DAV:}displayname' => 'project',
            'groupPrincipals' => []
        ];
        $this->assertEquals($expected, $principals[0]);
        $this->assertEquals($expected, $principal);

        // Extra check to make sure no mongo ids are used
        $this->assertSame($expected['id'], $principals[0]['id']);
        $this->assertSame($expected['id'], $principal['id']);
    }

    function testResourcesPrincipalsByPrefix() {
        $backend = new Mongo(self::$esndb);

        $principals = $backend->getPrincipalsByPrefix('principals/resources');
        $principal = $backend->getPrincipalByPath('principals/resources/' . self::RESOURCE_ID);
        $this->assertEquals(count($principals), 1);

        $expected = [
            'uri' => 'principals/resources/' . self::RESOURCE_ID,
            'id' => self::RESOURCE_ID,
            '{DAV:}displayname' => 'resource',
            '{http://sabredav.org/ns}email-address' => self::RESOURCE_ID . '@test',
            'groupPrincipals' => []
        ];

        $expectedFromPrefix = [
            'uri' => 'principals/resources/' . self::RESOURCE_ID,
            'id' => self::RESOURCE_ID,
            '{DAV:}displayname' => 'resource',
            'groupPrincipals' => []
        ];

        $this->assertEquals($expectedFromPrefix, $principals[0]);
        $this->assertEquals($expected, $principal);

        // Extra check to make sure no mongo ids are used
        $this->assertSame($expectedFromPrefix['id'], $principals[0]['id']);
        $this->assertSame($expected['id'], $principal['id']);
    }

    function testGetGroupMemberSet() {
        $backend = new Mongo(self::$esndb);
        $expected = array('principals/users/' . self::USER_ID);

        $this->assertEquals($expected,$backend->getGroupMemberSet('principals/communities/' . self::COMMUNITY_ID));
        $this->assertEquals($expected,$backend->getGroupMemberSet('principals/projects/' . self::PROJECT_ID));

        $expectedFromDomain = [
            'principals/users/' . self::USER_ID,
            'principals/users/' . self::USER_WITH_NO_LASTNAME,
            'principals/users/' . self::USER_WITH_NO_FIRSTNAME,
            'principals/users/' . self::USER_WITH_ACCOUNT_ID,
            'principals/users/' . self::USER_WITH_TWO_ACCOUNTS_ID,
            'principals/users/' . self::USER_WITH_NO_EMAIL_ID,
            'principals/users/' . self::USER_WITH_NO_EMAIL_ACCOUNT_ID,
            'principals/users/' . self::USER_DOMAIN_ADMIN_ID

        ];

        $this->assertEquals($expectedFromDomain,$backend->getGroupMemberSet('principals/domains/' . self::DOMAIN_ID));
    }

    function testGetGroupMembership() {
        $backend  = new Mongo(self::$esndb);

        $expected = array(
            'principals/communities/' . self::COMMUNITY_ID,
            'principals/projects/' . self::PROJECT_ID,
            'principals/domains/' . self::DOMAIN_ID
        );
        $this->assertEquals($expected,$backend->getGroupMembership('principals/users/' . self::USER_ID));
    }

    /**
     * @expectedException \Sabre\DAV\Exception\MethodNotAllowed
     */
    function testSetGroupMemberSetCommunity() {
        $backend = new Mongo(self::$esndb);
        $backend->setGroupMemberSet('principals/' . self::COMMUNITY_ID, array());
    }

    /**
     * @expectedException \Sabre\DAV\Exception\MethodNotAllowed
     */
    function testSetGroupMemberSetPRoject() {
        $backend = new Mongo(self::$esndb);
        $backend->setGroupMemberSet('principals/' . self::PROJECT_ID, array());
    }

    function testSearchPrincipals() {
        $backend = new Mongo(self::$esndb);

        $result = $backend->searchPrincipals('principals/users', array('{DAV:}blabla' => 'foo'));
        $this->assertEquals(array(), $result);

        $result = $backend->searchPrincipals('principals/communities', array('{DAV:}displayname' => 'com'));
        $this->assertEquals(array('principals/communities/' . self::COMMUNITY_ID), $result);

        $result = $backend->searchPrincipals('principals/projects', array('{DAV:}displayname' => 'proj'));
        $this->assertEquals(array('principals/projects/' . self::PROJECT_ID), $result);

        $result = $backend->searchPrincipals('principals/users', array('{DAV:}displayname' => 'FIrST', '{http://sabredav.org/ns}email-address' => 'USER@EXAMPLE.CoM'));
        $this->assertEquals(array('principals/users/' . self::USER_ID), $result);

        $result = $backend->searchPrincipals('mom', array('{DAV:}displayname' => 'FIrST', '{http://sabredav.org/ns}email-address' => 'USER@EXAMPLE.CoM'));
        $this->assertEquals(array(), $result);

        $result = $backend->searchPrincipals('principals/users', array('{DAV:}displayname' => 'FIrST', '{http://sabredav.org/ns}email-address' => 'NOTFOUND'), 'anyof');
        $this->assertEquals(array('principals/users/' . self::USER_ID), $result);

        $result = $backend->searchPrincipals('principals/resources', array('{http://sabredav.org/ns}email-address' => self::RESOURCE_ID . '@EXAMPLE.CoM'));
        $this->assertEquals(array('principals/resources/' . self::RESOURCE_ID), $result);

        $result = $backend->searchPrincipals('principals/resources', array('{http://sabredav.org/ns}email-address' => 'resource@EXAMPLE.CoM'));
        $this->assertEquals([], $result);

        $result = $backend->searchPrincipals('principals/domains', array('{DAV:}displayname' => 'test'));
        $this->assertEquals(array('principals/domains/' . self::DOMAIN_ID), $result);

        $result = $backend->searchPrincipals('principals/domains', array('{DAV:}displayname' => 'notexist'));
        $this->assertEquals([], $result);
    }

    /**
     * @expectedException \Sabre\DAV\Exception\MethodNotAllowed
     */
    function testUpdatePrincipal() {
        $backend = new Mongo(self::$esndb);
        $propPatch = new \Sabre\DAV\PropPatch([
            '{DAV:}displayname' => 'pietje',
            '{http://sabredav.org/ns}vcard-url' => 'blabla',
        ]);
        $backend->updatePrincipal('principals/users/' . self::USER_ID, $propPatch);
    }
}
