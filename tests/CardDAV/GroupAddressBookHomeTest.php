<?php

namespace ESN\CardDAV;

use \Sabre\DAV\Sharing\Plugin as SPlugin;

/**
 * @medium
 */
#[\AllowDynamicProperties]
class GroupAddressBookHomeTest extends \PHPUnit\Framework\TestCase {
    protected $sabredb;
    protected $carddavBackend;

    const ADMINISTRATOR_ID = '54313fcc398fef406b0041b7';
    const DOMAIN_ID = '54313fcc398fef406b0041b8';
    const USER_ID = '54313fcc398fef406b0041c9';

    function setUp(): void {
        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->sabredb->drop();
        $this->carddavBackend = new \ESN\CardDAV\Backend\Esn($this->sabredb);

        $this->books = new GroupAddressBookHome($this->carddavBackend, [
            'uri' => 'principals/domains/' . self::DOMAIN_ID,
            'administrators' => [ 'principals/users/' . self::ADMINISTRATOR_ID ],
            'members' => [
                'principals/users/' . self::ADMINISTRATOR_ID,
                'principals/users/' . self::USER_ID
            ]
        ]);
    }

    function testGetChildren() {
        $this->carddavBackend->createAddressBook(
            'principals/domains/' . self::DOMAIN_ID,
            'GAB',
            [ '{DAV:}acl' => [ '{DAV:}read' ] ]
        );

        $children = $this->books->getChildren();
        $this->assertInstanceOf('\ESN\CardDAV\Group\GroupAddressBook', $children[0]);

        $expectACL = [
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
        ];
        $this->assertEquals($children[0]->getACL(), $expectACL);
    }

    private function memberSetBackend(array $members) {
        return new class($members) extends \Sabre\DAVACL\PrincipalBackend\AbstractBackend {
            public $groupMemberSetCalls = [];
            private $members;
            function __construct($members) { $this->members = $members; }
            function getGroupMemberSet($principal) { $this->groupMemberSetCalls[] = $principal; return $this->members; }
            function getPrincipalsByPrefix($prefixPath) { return []; }
            function getPrincipalByPath($path) { return null; }
            function updatePrincipal($path, \Sabre\DAV\PropPatch $propPatch) {}
            function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof') { return []; }
            function getGroupMembership($principal) { return []; }
            function setGroupMemberSet($principal, array $members) {}
        };
    }

    function testOnlyTheFullGeneratedAclLoadsTheMemberSet() {
        $this->carddavBackend->createAddressBook('principals/domains/' . self::DOMAIN_ID, 'GAB', [ '{DAV:}acl' => [ '{DAV:}read' ] ]);
        $principalBackend = $this->memberSetBackend(['principals/users/' . self::USER_ID]);
        $home = new GroupAddressBookHome($this->carddavBackend, [
            'uri' => 'principals/domains/' . self::DOMAIN_ID,
            'administrators' => [ 'principals/users/' . self::ADMINISTRATOR_ID ],
        ], $principalBackend);

        $home->getACL();
        $this->assertSame([], $principalBackend->groupMemberSetCalls);

        $children = $home->getChildren();
        $member = 'principals/users/' . self::USER_ID;
        $this->assertContains($member, array_column($children[0]->forMemberPrivilegeCheck($member)->getACL(), 'principal'));
        $this->assertNotContains($member, array_column($children[0]->forMemberPrivilegeCheck(null)->getACL(), 'principal'));
        $this->assertSame([], $principalBackend->groupMemberSetCalls);
        $this->assertContains([
            'privilege' => '{DAV:}read',
            'principal' => 'principals/users/' . self::USER_ID,
            'protected' => true
        ], $children[0]->getACL());
        $home->getChildren()[0]->getACL();
        $this->assertSame(['principals/domains/' . self::DOMAIN_ID], $principalBackend->groupMemberSetCalls);
    }

    function testDomainMembersAclUsesTheDomainWithoutLoadingItsMembers() {
        $domain = 'principals/domains/' . self::DOMAIN_ID;
        $this->carddavBackend->createAddressBook($domain, 'domain-members', [ '{DAV:}acl' => [ '{DAV:}read' ] ]);
        $principalBackend = $this->memberSetBackend(['principals/users/' . self::USER_ID]);
        $home = new GroupAddressBookHome($this->carddavBackend, [
            'uri' => $domain,
            'administrators' => ['principals/users/' . self::ADMINISTRATOR_ID],
        ], $principalBackend);

        $acl = $home->getChildren()[0]->getACL();
        $this->assertContains(['privilege' => '{DAV:}read', 'principal' => $domain, 'protected' => false], $acl);
        $this->assertNotContains('principals/users/' . self::USER_ID, array_column($acl, 'principal'));
        $this->assertCount(4, $acl);
        $this->assertSame($acl, $home->getChildren()[0]->getACL());
        $this->assertSame([], $principalBackend->groupMemberSetCalls);
    }

    function testWithoutMembersNoMemberIsGrantedTheAddressBooks() {
        $this->carddavBackend->createAddressBook('principals/domains/' . self::DOMAIN_ID, 'GAB', [ '{DAV:}acl' => [ '{DAV:}read' ] ]);
        $home = new GroupAddressBookHome($this->carddavBackend, [
            'uri' => 'principals/domains/' . self::DOMAIN_ID,
            'administrators' => [ 'principals/users/' . self::ADMINISTRATOR_ID ],
        ]);

        $principals = array_column($home->getChildren()[0]->getACL(), 'principal');
        $this->assertNotContains('principals/users/' . self::USER_ID, $principals);
        $this->assertContains('principals/users/' . self::ADMINISTRATOR_ID, $principals);
    }
}
