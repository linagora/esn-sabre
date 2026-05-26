<?php

namespace ESN\DAVACL\PrincipalBackend;

class PrivatePrincipalBackendTest extends \PHPUnit\Framework\TestCase {

    function testGetPrincipalsByPrefixShouldUseCurrentPrincipal() {
        $currentPrincipal = 'principals/users/123';
        $mongo = $this->getMockBuilder(Mongo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPrincipalsByPrefix', 'getPrincipalByPath'])
            ->getMock();

        $mongo->expects($this->never())
            ->method('getPrincipalsByPrefix');
        $mongo->expects($this->once())
            ->method('getPrincipalByPath')
            ->with($currentPrincipal)
            ->willReturn(['uri' => $currentPrincipal]);

        $backend = new PrivatePrincipalBackend($mongo, function() use ($currentPrincipal) {
            return $currentPrincipal;
        });

        $this->assertEquals([['uri' => $currentPrincipal]], $backend->getPrincipalsByPrefix('principals/users'));
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

    function testSearchPrincipalsShouldSearchOnlyCurrentPrincipal() {
        $currentPrincipal = 'principals/users/123';
        $searchProperties = ['{DAV:}displayname' => 'Bob'];
        $mongo = $this->getMockBuilder(Mongo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['searchPrincipals', 'getPrincipalByPath'])
            ->getMock();

        $mongo->expects($this->never())
            ->method('searchPrincipals');
        $mongo->expects($this->once())
            ->method('getPrincipalByPath')
            ->with($currentPrincipal)
            ->willReturn([
                'uri' => $currentPrincipal,
                '{DAV:}displayname' => 'Bob Smith'
            ]);

        $backend = new PrivatePrincipalBackend($mongo, function() use ($currentPrincipal) {
            return $currentPrincipal;
        });

        $this->assertEquals([$currentPrincipal], $backend->searchPrincipals('principals/users', $searchProperties));
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

    function testGetPrincipalsByPrefixShouldExposeCurrentUserDomains() {
        $currentPrincipal = 'principals/users/123';
        $domainPrincipal = 'principals/domains/456';
        $mongo = $this->getMockBuilder(Mongo::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getGroupMembership', 'getPrincipalByPath'])
            ->getMock();

        $mongo->expects($this->once())
            ->method('getGroupMembership')
            ->with($currentPrincipal)
            ->willReturn([$domainPrincipal]);
        $mongo->expects($this->once())
            ->method('getPrincipalByPath')
            ->with($domainPrincipal)
            ->willReturn(['uri' => $domainPrincipal]);

        $backend = new PrivatePrincipalBackend($mongo, function() use ($currentPrincipal) {
            return $currentPrincipal;
        });

        $this->assertEquals([['uri' => $domainPrincipal]], $backend->getPrincipalsByPrefix('principals/domains'));
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
}
