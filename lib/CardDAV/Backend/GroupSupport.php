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

    /** Store the non-protected ACEs supplied through the WebDAV ACL method. */
    function setAddressBookACL($addressBookId, array $acl);
}