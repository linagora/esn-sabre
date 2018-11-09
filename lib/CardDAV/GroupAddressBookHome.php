<?php

namespace ESN\CardDAV;

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

        $addressbooks = $this->carddavBackend->getAddressBooksFor($this->principalUri);

        foreach($addressbooks as $addressbook) {
            $addressbook['administrators'] = $this->principal['administrators'];
            $addressBook['members'] = $this->principal['members'];

            $children[] = new Group\GroupAddressBook($this->carddavBackend, $addressbook);
        }

        // If the backend supports shared address books, we'll add those as well
        if ($this->carddavBackend instanceof Backend\SharingSupport) {
            $children = $this->updateChildrenWithSharedAddressBooks($children);
        }

        // Add group address books
        if (isset($this->principal['groupPrincipals'])) {
            $children = $this->updateChildrenWithGroupAddressBooks($children);
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
