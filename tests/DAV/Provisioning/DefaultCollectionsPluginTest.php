<?php

namespace ESN\DAV\Provisioning;

use ESN\Utils\AuthTenant;
use ESN\Utils\TenantType;

class DefaultCollectionsPluginTest extends \PHPUnit\Framework\TestCase {
    const USER_PRINCIPAL = 'principals/users/54b64eadf6d7d8e41d263e0f';

    private $addressBookBackend;
    private $calendarBackend;
    private $provisionedPrincipals;
    private $server;

    function setUp(): void {
        $this->addressBookBackend = $this->createMock(\ESN\CardDAV\Backend\Esn::class);
        $this->calendarBackend = $this->createMock(\ESN\CalDAV\Backend\Esn::class);
        $this->provisionedPrincipals = new class implements ProvisionedPrincipals {
            private array $principalUris = [];

            function contains(string $principalUri): bool {
                return in_array($principalUri, $this->principalUris, true);
            }

            function add(string $principalUri): void {
                $this->principalUris[] = $principalUri;
            }
        };

        $this->server = new \Sabre\DAV\Server([]);
        $this->server->addPlugin(new DefaultCollectionsPlugin($this->addressBookBackend, $this->calendarBackend, $this->provisionedPrincipals));
    }

    function testShouldProvisionTheDefaultCollectionsOfANewPrincipal() {
        $this->addressBookBackend->expects($this->once())->method('ensureDefaultAddressBooks')->with(self::USER_PRINCIPAL);
        $this->calendarBackend->expects($this->once())->method('ensureDefaultCalendar')->with(self::USER_PRINCIPAL);

        $this->authenticate(new AuthTenant('54b64eadf6d7d8e41d263e0f', 'domainId'));

        $this->assertTrue($this->provisionedPrincipals->contains(self::USER_PRINCIPAL));
    }

    function testShouldProvisionAPrincipalOnlyOnce() {
        $this->addressBookBackend->expects($this->once())->method('ensureDefaultAddressBooks');
        $this->calendarBackend->expects($this->once())->method('ensureDefaultCalendar');

        $this->authenticate(new AuthTenant('54b64eadf6d7d8e41d263e0f', 'domainId'));
        $this->authenticate(new AuthTenant('54b64eadf6d7d8e41d263e0f', 'domainId'));
    }

    function testShouldProvisionEachPrincipal() {
        $this->addressBookBackend->expects($this->exactly(2))->method('ensureDefaultAddressBooks');
        $this->calendarBackend->expects($this->exactly(2))->method('ensureDefaultCalendar');

        $this->authenticate(new AuthTenant('54b64eadf6d7d8e41d263e0f', 'domainId'));
        $this->authenticate(new AuthTenant('54b64eadf6d7d8e41d263e10', 'domainId'));
    }

    function testShouldNotProvisionTechnicalUsers() {
        $this->addressBookBackend->expects($this->never())->method('ensureDefaultAddressBooks');
        $this->calendarBackend->expects($this->never())->method('ensureDefaultCalendar');

        $this->authenticate(new AuthTenant('technical', 'domainId', TenantType::Technical));
    }

    function testShouldNotRememberAPrincipalWhoseProvisioningFailed() {
        $this->calendarBackend->method('ensureDefaultCalendar')->willThrowException(new \Sabre\DAV\Exception('boom'));

        try {
            $this->authenticate(new AuthTenant('54b64eadf6d7d8e41d263e0f', 'domainId'));
            $this->fail('The provisioning failure should propagate');
        } catch (\Sabre\DAV\Exception $e) {
            $this->assertFalse($this->provisionedPrincipals->contains(self::USER_PRINCIPAL));
        }
    }

    private function authenticate(AuthTenant $authTenant) {
        $this->server->emit('auth:success', [$authTenant]);
    }
}
