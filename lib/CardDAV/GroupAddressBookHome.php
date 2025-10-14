<?php

namespace ESN\CardDAV;

#[\AllowDynamicProperties]
class GroupAddressBookHome extends AddressBookHome {
    /**
     * This method override the parent which:
     * - Group members have read rights
     * - Group administrators have all rights
     */
    function getACL() {
        $acl = [
            [
                'privilege' => '{DAV:}read',
                'principal' => '{DAV:}owner',
                'protected' => true
            ]
        ];

        if (isset($this->principal['administrators'])) {
            $acl = $this->updateAclWithAdministratorsRight($acl);
        }

        return $acl;
    }

    /**
     * Returns a list of addressbooks. In contrast to the sabre version of this
     * method, the returned addressbook instance has extra methods.
     *
     * @return array
     */
    function getChildren() {
        $this->sourcesOfSharedAddressBooks = [];
        $children = [];

        $addressBooks = $this->carddavBackend->getAddressBooksFor($this->principalUri);

        foreach($addressBooks as $addressBook) {
            $addressBook['administrators'] = $this->principal['administrators'];
            $addressBook['members'] = $this->principal['members'];

            $children[] = new Group\GroupAddressBook($this->carddavBackend, $addressBook);
        }

        // If the backend supports shared address books, we'll add those as well
        if ($this->carddavBackend instanceof Backend\SharingSupport) {
            $children = $this->updateChildrenWithSharedAddressBooks($children);
        }

        // Remove children that are shared by group address books
        if (isset($this->principal['groupPrincipals'])) {
            $children = $this->removeChildrenSharedByGroupAddressBooks($children);
        }

        return $children;
    }

    private function updateAclWithAdministratorsRight($acl) {
        foreach ($this->principal['administrators'] as $administrator) {
            $acl[] = [
                'privilege' => '{DAV:}all',
                'principal' => $administrator,
                'protected' => true
            ];
        }

        return $acl;
    }
}
