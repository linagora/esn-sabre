<?php

namespace ESN\DAVACL\PrincipalBackend;

class MongoTest extends \PHPUnit_Framework_TestCase {
    protected static $esndb;

    const USER_ID = '54313fcc398fef406b0041b6';
    const COMMUNITY_ID = '54313fcc398fef406b0041b4';
    const PROJECT_ID= '54b64eadf6d7d8e41d263e0f';

    static function setUpBeforeClass() {
        $mc = new \MongoClient(ESN_MONGO_ESNURI);
        self::$esndb = $mc->selectDB(ESN_MONGO_ESNDB);
        self::$esndb->drop();

        self::$esndb->users->insert([
            '_id' => new \MongoId(self::USER_ID),
            'firstname' => 'first',
            'lastname' => 'last',
            'emails' => [ 'user@example.com' ]
        ]);
        self::$esndb->communities->insert([
            '_id' => new \MongoId(self::COMMUNITY_ID),
            'title' => 'community',
            'members' => [
              [
                'member' => [
                  'objectType' => 'user',
                  'id' => new \MongoId(self::USER_ID)
                ]
              ]
            ]
        ]);
        self::$esndb->projects->insert([
            '_id' => new \MongoId(self::PROJECT_ID),
            'title' => 'project',
            'members' => [
              [
                'member' => [
                  'objectType' => 'user',
                  'id' => new \MongoId(self::USER_ID)
                ]
              ]
            ]
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
        $this->assertEquals(count($principals), 1);

        $expected = [
            'uri' => 'principals/users/' . self::USER_ID,
            'id' => self::USER_ID,
            '{DAV:}displayname' => 'first last',
            '{http://sabredav.org/ns}email-address' => 'user@example.com'
        ];
        $this->assertEquals($expected, $principals[0]);
        $this->assertEquals($expected, $principal);

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
        ];
        $this->assertEquals($expected, $principals[0]);
        $this->assertEquals($expected, $principal);

        // Extra check to make sure no mongo ids are used
        $this->assertSame($expected['id'], $principals[0]['id']);
        $this->assertSame($expected['id'], $principal['id']);
    }

    function testGetGroupMemberSet() {
        $backend = new Mongo(self::$esndb);
        $expected = array('principals/users/' . self::USER_ID);

        $this->assertEquals($expected,$backend->getGroupMemberSet('principals/communities/' . self::COMMUNITY_ID));
        $this->assertEquals($expected,$backend->getGroupMemberSet('principals/projects/' . self::PROJECT_ID));
    }

    function testGetGroupMembership() {
        $backend  = new Mongo(self::$esndb);

        $expected = array(
            'principals/communities/' . self::COMMUNITY_ID,
            'principals/projects/' . self::PROJECT_ID
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

        $result = $backend->searchPrincipals('principals/users', array('{DAV:}displayname' => 'FIrST', '{http://sabredav.org/ns}email-address' => 'USER@EXAMPLE'));
        $this->assertEquals(array('principals/users/' . self::USER_ID), $result);

        $result = $backend->searchPrincipals('mom', array('{DAV:}displayname' => 'FIrST', '{http://sabredav.org/ns}email-address' => 'USER@EXAMPLE'));
        $this->assertEquals(array(), $result);

        $result = $backend->searchPrincipals('principals/users', array('{DAV:}displayname' => 'FIrST', '{http://sabredav.org/ns}email-address' => 'NOTFOUND'), 'anyof');
        $this->assertEquals(array('principals/users/' . self::USER_ID), $result);
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
