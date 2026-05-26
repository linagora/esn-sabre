<?php

namespace ESN\DAVACL\PrincipalBackend;

class PrivatePrincipalBackendTest extends \PHPUnit\Framework\TestCase {
    const USER_PRINCIPAL = 'principals/users/123';
    const OTHER_USER_PRINCIPAL = 'principals/users/456';
    const DOMAIN_PRINCIPAL = 'principals/domains/789';
    const OTHER_DOMAIN_PRINCIPAL = 'principals/domains/999';
    const RESOURCE_PRINCIPAL = 'principals/resources/resource-1';
    const OTHER_RESOURCE_PRINCIPAL = 'principals/resources/resource-2';

    function testGetPrincipalsByPrefixShouldExposeOnlyCurrentUserAndCurrentUserDomains() {
        $backend = $this->newPrivateBackend(self::USER_PRINCIPAL);

        $this->assertEquals(
            [$this->principal(self::USER_PRINCIPAL, 'Bob User', 'bob@example.com')],
            $backend->getPrincipalsByPrefix('principals/users')
        );
        $this->assertEquals(
            [$this->principal(self::DOMAIN_PRINCIPAL, 'Acme Domain')],
            $backend->getPrincipalsByPrefix('principals/domains')
        );
        $this->assertEquals([], $backend->getPrincipalsByPrefix('principals/resources'));
    }

    function testGetPrincipalsByPrefixShouldDelegateForTechnicalPrincipal() {
        $mongo = $this->getMockBuilder(Mongo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPrincipalsByPrefix'])
            ->getMock();

        $mongo->expects($this->once())
            ->method('getPrincipalsByPrefix')
            ->with('principals/users')
            ->willReturn([['uri' => 'principals/users/123'], ['uri' => 'principals/users/456']]);

        $backend = new PrivatePrincipalBackend($mongo, function() {
            return 'principals/technicalUser';
        });

        $this->assertEquals(
            [['uri' => 'principals/users/123'], ['uri' => 'principals/users/456']],
            $backend->getPrincipalsByPrefix('principals/users')
        );
    }

    function testSearchPrincipalsShouldSearchOnlyCurrentUserAndCurrentUserDomains() {
        $backend = $this->newPrivateBackend(self::USER_PRINCIPAL);

        $this->assertEquals(
            [self::USER_PRINCIPAL],
            $backend->searchPrincipals('principals/users', ['{DAV:}displayname' => 'Bob'])
        );
        $this->assertEquals(
            [],
            $backend->searchPrincipals('principals/users', ['{DAV:}displayname' => 'Alice'])
        );
        $this->assertEquals(
            [self::DOMAIN_PRINCIPAL],
            $backend->searchPrincipals('principals/domains', ['{DAV:}displayname' => 'Acme'])
        );
        $this->assertEquals(
            [],
            $backend->searchPrincipals('principals/domains', ['{DAV:}displayname' => 'Other'])
        );
        $this->assertEquals(
            [],
            $backend->searchPrincipals(
                'principals/resources',
                ['{http://sabredav.org/ns}email-address' => 'resource-1@example.com']
            )
        );
    }

    function testGetPrincipalsByPrefixShouldExposeOnlyCurrentResourceForResourcePrincipal() {
        $backend = $this->newPrivateBackend(self::RESOURCE_PRINCIPAL);

        $this->assertEquals(
            [$this->principal(self::RESOURCE_PRINCIPAL, 'Room One', 'resource-1@example.com')],
            $backend->getPrincipalsByPrefix('principals/resources')
        );
        $this->assertEquals([], $backend->getPrincipalsByPrefix('principals/users'));
        $this->assertEquals([], $backend->getPrincipalsByPrefix('principals/domains'));
    }

    function testSearchPrincipalsShouldExposeOnlyCurrentResourceForResourcePrincipal() {
        $backend = $this->newPrivateBackend(self::RESOURCE_PRINCIPAL);

        $this->assertEquals(
            [self::RESOURCE_PRINCIPAL],
            $backend->searchPrincipals(
                'principals/resources',
                ['{http://sabredav.org/ns}email-address' => 'resource-1@example.com']
            )
        );
        $this->assertEquals(
            [],
            $backend->searchPrincipals(
                'principals/resources',
                ['{http://sabredav.org/ns}email-address' => 'resource-2@example.com']
            )
        );
        $this->assertEquals(
            [],
            $backend->searchPrincipals('principals/users', ['{DAV:}displayname' => 'Bob'])
        );
    }

    function testSearchPrincipalsShouldDelegateForTechnicalPrincipal() {
        $searchProperties = ['{DAV:}displayname' => 'Bob'];
        $mongo = $this->getMockBuilder(Mongo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['searchPrincipals', 'getPrincipalByPath'])
            ->getMock();

        $mongo->expects($this->once())
            ->method('searchPrincipals')
            ->with('principals/users', $searchProperties, 'anyof')
            ->willReturn(['principals/users/123']);
        $mongo->expects($this->never())
            ->method('getPrincipalByPath');

        $backend = new PrivatePrincipalBackend($mongo, function() {
            return 'principals/technicalUser';
        });

        $this->assertEquals(
            ['principals/users/123'],
            $backend->searchPrincipals('principals/users', $searchProperties, 'anyof')
        );
    }

    function testGetPrincipalsByPrefixShouldReturnNothingWithoutCurrentPrincipal() {
        $backend = $this->newPrivateBackend(null);

        $this->assertEquals([], $backend->getPrincipalsByPrefix('principals/users'));
        $this->assertEquals([], $backend->getPrincipalsByPrefix('principals/domains'));
        $this->assertEquals([], $backend->getPrincipalsByPrefix('principals/resources'));
        $this->assertEquals(
            [],
            $backend->searchPrincipals('principals/users', ['{DAV:}displayname' => 'Bob'])
        );
    }

    function testSearchPrincipalsShouldHonorAllofAndAnyofForVisiblePrincipal() {
        $backend = $this->newPrivateBackend(self::USER_PRINCIPAL);

        $this->assertEquals(
            [self::USER_PRINCIPAL],
            $backend->searchPrincipals(
                'principals/users',
                [
                    '{DAV:}displayname' => 'Bob',
                    '{http://sabredav.org/ns}email-address' => 'bob@example.com'
                ]
            )
        );
        $this->assertEquals(
            [],
            $backend->searchPrincipals(
                'principals/users',
                [
                    '{DAV:}displayname' => 'Bob',
                    '{http://sabredav.org/ns}email-address' => 'alice@example.com'
                ]
            )
        );
        $this->assertEquals(
            [self::USER_PRINCIPAL],
            $backend->searchPrincipals(
                'principals/users',
                [
                    '{DAV:}displayname' => 'Bob',
                    '{http://sabredav.org/ns}email-address' => 'alice@example.com'
                ],
                'anyof'
            )
        );
    }

    function testFindByUriShouldDelegateExactLookup() {
        $mongo = $this->getMockBuilder(Mongo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByUri'])
            ->getMock();

        $mongo->expects($this->once())
            ->method('findByUri')
            ->with('mailto:user@example.com', 'principals/users')
            ->willReturn('principals/users/123');

        $backend = new PrivatePrincipalBackend($mongo, function() {
            return 'principals/users/456';
        });

        $this->assertEquals('principals/users/123', $backend->findByUri('mailto:user@example.com', 'principals/users'));
    }

    function testShouldExposeCustomMongoLookupMethods() {
        $mongo = $this->getMockBuilder(Mongo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPrincipalIdByEmail', 'getPrincipalIdByResourceEmail'])
            ->getMock();

        $mongo->expects($this->once())
            ->method('getPrincipalIdByEmail')
            ->with('user@example.com')
            ->willReturn('123');
        $mongo->expects($this->once())
            ->method('getPrincipalIdByResourceEmail')
            ->with('resource@example.com')
            ->willReturn('456');

        $backend = new PrivatePrincipalBackend($mongo, function() {
            return 'principals/users/123';
        });

        $this->assertTrue(method_exists($backend, 'getPrincipalIdByEmail'));
        $this->assertEquals('123', $backend->getPrincipalIdByEmail('user@example.com'));
        $this->assertEquals('456', $backend->getPrincipalIdByResourceEmail('resource@example.com'));
    }

    private function newPrivateBackend($currentPrincipal) {
        return new PrivatePrincipalBackend($this->principalStore(), function() use ($currentPrincipal) {
            return $currentPrincipal;
        });
    }

    private function principalStore() {
        return new PrivatePrincipalBackendTestMongo(
            [
                self::USER_PRINCIPAL => $this->principal(self::USER_PRINCIPAL, 'Bob User', 'bob@example.com'),
                self::OTHER_USER_PRINCIPAL => $this->principal(self::OTHER_USER_PRINCIPAL, 'Alice User', 'alice@example.com'),
                self::DOMAIN_PRINCIPAL => $this->principal(self::DOMAIN_PRINCIPAL, 'Acme Domain'),
                self::OTHER_DOMAIN_PRINCIPAL => $this->principal(self::OTHER_DOMAIN_PRINCIPAL, 'Other Domain'),
                self::RESOURCE_PRINCIPAL => $this->principal(self::RESOURCE_PRINCIPAL, 'Room One', 'resource-1@example.com'),
                self::OTHER_RESOURCE_PRINCIPAL => $this->principal(self::OTHER_RESOURCE_PRINCIPAL, 'Room Two', 'resource-2@example.com')
            ],
            [
                self::USER_PRINCIPAL => [self::DOMAIN_PRINCIPAL],
                self::OTHER_USER_PRINCIPAL => [self::OTHER_DOMAIN_PRINCIPAL]
            ]
        );
    }

    private function principal($uri, $displayName, $email = null) {
        $principal = [
            'uri' => $uri,
            '{DAV:}displayname' => $displayName
        ];

        if ($email !== null) {
            $principal['{http://sabredav.org/ns}email-address'] = $email;
        }

        return $principal;
    }
}

class PrivatePrincipalBackendTestMongo extends Mongo {
    private $principalsByPath;
    private $memberships;

    function __construct($principalsByPath, $memberships) {
        $this->principalsByPath = $principalsByPath;
        $this->memberships = $memberships;
    }

    function getPrincipalsByPrefix($prefixPath) {
        $principals = [];

        foreach ($this->principalsByPath as $principal) {
            if (str_starts_with($principal['uri'], $prefixPath . '/')) {
                $principals[] = $principal;
            }
        }

        return $principals;
    }

    function getPrincipalByPath($path) {
        return $this->principalsByPath[$path] ?? null;
    }

    function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof') {
        $result = [];

        foreach ($this->getPrincipalsByPrefix($prefixPath) as $principal) {
            $result[] = $principal['uri'];
        }

        return $result;
    }

    function getGroupMembership($principal) {
        return $this->memberships[$principal] ?? [];
    }
}
