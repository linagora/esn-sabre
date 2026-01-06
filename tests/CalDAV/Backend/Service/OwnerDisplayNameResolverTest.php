<?php

namespace ESN\CalDAV\Backend\Service;

use PHPUnit\Framework\TestCase;

/**
 * @medium
 */
class OwnerDisplayNameResolverTest extends TestCase {

    private $principalBackend;
    private $resolver;

    function setUp(): void {
        // Create mock principal backend
        $this->principalBackend = $this->createMock(\Sabre\DAVACL\PrincipalBackend\BackendInterface::class);
        $this->resolver = new OwnerDisplayNameResolver($this->principalBackend);
    }

    /**
     * Test extracting owner principal URI from calendar source path
     */
    function testExtractOwnerPrincipalFromSource() {
        // Test standard calendar path
        $sourcePath = 'calendars/54b64eadf6d7d8e41d263e0e/publicCal1';
        $expected = 'principals/users/54b64eadf6d7d8e41d263e0e';
        $this->assertEquals($expected, $this->resolver->extractOwnerPrincipalFromSource($sourcePath));

        // Test with leading slash
        $sourcePath = '/calendars/54b64eadf6d7d8e41d263e0e/publicCal1';
        $this->assertEquals($expected, $this->resolver->extractOwnerPrincipalFromSource($sourcePath));

        // Test with different calendar URI
        $sourcePath = 'calendars/123456789/events.json';
        $expected = 'principals/users/123456789';
        $this->assertEquals($expected, $this->resolver->extractOwnerPrincipalFromSource($sourcePath));

        // Test invalid path
        $sourcePath = 'invalid/path';
        $this->assertNull($this->resolver->extractOwnerPrincipalFromSource($sourcePath));

        // Test empty path
        $sourcePath = '';
        $this->assertNull($this->resolver->extractOwnerPrincipalFromSource($sourcePath));
    }

    /**
     * Test getting owner principal from calendar instances
     */
    function testGetOwnerPrincipalFromInstances() {
        $instances = [
            [
                'principaluri' => 'principals/users/user1',
                'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_READ
            ],
            [
                'principaluri' => 'principals/users/owner',
                'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_SHAREDOWNER
            ],
            [
                'principaluri' => 'principals/users/user2',
                'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE
            ]
        ];

        $ownerPrincipal = $this->resolver->getOwnerPrincipalFromInstances($instances);
        $this->assertEquals('principals/users/owner', $ownerPrincipal);

        // Test with no owner
        $instancesNoOwner = [
            [
                'principaluri' => 'principals/users/user1',
                'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_READ
            ]
        ];
        $this->assertNull($this->resolver->getOwnerPrincipalFromInstances($instancesNoOwner));

        // Test with empty array
        $this->assertNull($this->resolver->getOwnerPrincipalFromInstances([]));
    }

    /**
     * Test getting display name from principal backend
     */
    function testGetDisplayName() {
        $principalUri = 'principals/users/54b64eadf6d7d8e41d263e0e';

        // Test with displayname available
        $this->principalBackend->expects($this->once())
            ->method('getPrincipalByPath')
            ->with($principalUri)
            ->willReturn([
                '{DAV:}displayname' => 'Michel MAUDET',
                '{http://sabredav.org/ns}email-address' => 'michel.maudet@example.com'
            ]);

        $displayName = $this->resolver->getDisplayName($principalUri);
        $this->assertEquals('Michel MAUDET', $displayName);
    }

    /**
     * Test getting display name falls back to email when no displayname
     */
    function testGetDisplayNameFallbackToEmail() {
        $principalUri = 'principals/users/54b64eadf6d7d8e41d263e0e';

        // Mock principal backend to return only email
        $principalBackend = $this->createMock(\Sabre\DAVACL\PrincipalBackend\BackendInterface::class);
        $principalBackend->expects($this->once())
            ->method('getPrincipalByPath')
            ->with($principalUri)
            ->willReturn([
                '{DAV:}displayname' => '',
                '{http://sabredav.org/ns}email-address' => 'user@example.com'
            ]);

        $resolver = new OwnerDisplayNameResolver($principalBackend);
        $displayName = $resolver->getDisplayName($principalUri);
        $this->assertEquals('user@example.com', $displayName);
    }

    /**
     * Test getting display name returns null when principal not found
     */
    function testGetDisplayNamePrincipalNotFound() {
        $principalUri = 'principals/users/nonexistent';

        $principalBackend = $this->createMock(\Sabre\DAVACL\PrincipalBackend\BackendInterface::class);
        $principalBackend->expects($this->once())
            ->method('getPrincipalByPath')
            ->with($principalUri)
            ->willReturn(null);

        $resolver = new OwnerDisplayNameResolver($principalBackend);
        $displayName = $resolver->getDisplayName($principalUri);
        $this->assertNull($displayName);
    }

    /**
     * Test getting display name with null URI
     */
    function testGetDisplayNameNullUri() {
        $displayName = $this->resolver->getDisplayName(null);
        $this->assertNull($displayName);
    }

    /**
     * Test appending owner name to calendar displayname
     */
    function testAppendOwnerName() {
        // Test with existing displayname
        $result = $this->resolver->appendOwnerName('Villa', 'Michel MAUDET');
        $this->assertEquals('Villa (Michel MAUDET)', $result);

        // Test with null displayname
        $result = $this->resolver->appendOwnerName(null, 'Michel MAUDET');
        $this->assertEquals('Calendar (Michel MAUDET)', $result);

        // Test with empty displayname
        $result = $this->resolver->appendOwnerName('', 'Michel MAUDET');
        $this->assertEquals('Calendar (Michel MAUDET)', $result);

        // Test with null owner name
        $result = $this->resolver->appendOwnerName('Villa', null);
        $this->assertEquals('Villa', $result);

        // Test with both null
        $result = $this->resolver->appendOwnerName(null, null);
        $this->assertEquals('Calendar', $result);

        // Test with special characters in displayname
        $result = $this->resolver->appendOwnerName('Château de Versailles', 'Louis XIV');
        $this->assertEquals('Château de Versailles (Louis XIV)', $result);

        // Test with email as owner name
        $result = $this->resolver->appendOwnerName('My Calendar', 'user@example.com');
        $this->assertEquals('My Calendar (user@example.com)', $result);
    }

    /**
     * Test trimming whitespace in display names
     */
    function testDisplayNameTrimming() {
        $principalUri = 'principals/users/54b64eadf6d7d8e41d263e0e';

        $principalBackend = $this->createMock(\Sabre\DAVACL\PrincipalBackend\BackendInterface::class);
        $principalBackend->expects($this->once())
            ->method('getPrincipalByPath')
            ->with($principalUri)
            ->willReturn([
                '{DAV:}displayname' => '  Michel MAUDET  ',
                '{http://sabredav.org/ns}email-address' => 'michel@example.com'
            ]);

        $resolver = new OwnerDisplayNameResolver($principalBackend);
        $displayName = $resolver->getDisplayName($principalUri);
        $this->assertEquals('Michel MAUDET', $displayName);
    }
}
