<?php

namespace ESN\CardDAV\Backend;

use Sabre\CardDAV\Backend\BackendInterface;

/**
 * Every CardDAV backend must at least implement this interface.
 */
interface GroupSupport extends BackendInterface {
    /**
     * Set members rights
     *
     * @param mixed $addressBookId
     * @param array $privileges
     * @return void
     */
    function setMembersRight($addressBookId, $privileges);

    /**
     * Set the access policy of the domain-members address book. The cards are left untouched.
     *
     * @param mixed $addressBookId
     * @param \ESN\CardDAV\Group\DomainMembersAccessPolicy $policy
     * @return void
     */
    function setAccessPolicy($addressBookId, \ESN\CardDAV\Group\DomainMembersAccessPolicy $policy);
}