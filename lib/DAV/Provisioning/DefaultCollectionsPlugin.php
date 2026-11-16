<?php

namespace ESN\DAV\Provisioning;

use ESN\Utils\AuthTenant;
use ESN\Utils\TenantType;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * Makes sure an authenticated principal owns its default address books and
 * default calendar.
 *
 * The check costs a few database reads, so it runs once per principal: the
 * principals already provisioned are remembered and skipped afterwards.
 */
class DefaultCollectionsPlugin extends ServerPlugin {
    private $addressBookBackend;
    private $calendarBackend;
    private ProvisionedPrincipals $provisionedPrincipals;

    /**
     * @param \ESN\CardDAV\Backend\Esn $addressBookBackend
     * @param \ESN\CalDAV\Backend\Esn $calendarBackend
     * @param ProvisionedPrincipals $provisionedPrincipals
     */
    function __construct($addressBookBackend, $calendarBackend, ProvisionedPrincipals $provisionedPrincipals) {
        $this->addressBookBackend = $addressBookBackend;
        $this->calendarBackend = $calendarBackend;
        $this->provisionedPrincipals = $provisionedPrincipals;
    }

    function initialize(Server $server) {
        $server->on('auth:success', [$this, 'provision']);
    }

    function getPluginName() {
        return 'default-collections';
    }

    function provision(AuthTenant $authTenant) {
        $principalUri = (string) $authTenant->getPrincipal();

        if ($authTenant->tenantType === TenantType::Technical || $this->provisionedPrincipals->contains($principalUri)) {
            return;
        }

        $this->addressBookBackend->ensureDefaultAddressBooks($principalUri);
        $this->calendarBackend->ensureDefaultCalendar($principalUri);
        $this->provisionedPrincipals->add($principalUri);
    }
}
