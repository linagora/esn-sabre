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
        $addressbooks = $this->carddavBackend->getAddressBooksFor($this->principalUri);
        $children = [];

        foreach($addressbooks as $addressbook) {
            $addressbook['administrators'] = $this->principal['administrators'];
            $addressBook['members'] = $this->principal['members'];

            $children[] = new Group\GroupAddressBook($this->carddavBackend, $addressbook);
        }

        $sourcesOfSharedAddressBooks = [];

        // If the backend supports shared address books, we'll add those as well
        if ($this->carddavBackend instanceof Backend\SharingSupport) {
            foreach ($this->carddavBackend->getSharedAddressBooksForUser($this->principalUri) as $sharedAddressBook) {
                $sourcesOfSharedAddressBooks[] = (string)$sharedAddressBook['addressbookid'];
                $children[] = new Sharing\SharedAddressBook($this->carddavBackend, $sharedAddressBook);
            }
        }

        // Add group address books
        if (isset($this->principal['groupPrincipals'])) {
            foreach ($this->principal['groupPrincipals'] as $groupPrincipal) {
                foreach ($this->carddavBackend->getAddressBooksFor($groupPrincipal['uri']) as $addressBook) {
                    
                    // Once group address book is delegated to user, the delegated one will override the source.
                    if (!in_array((string)$addressBook['id'], $sourcesOfSharedAddressBooks)) {
                        $addressBook['administrators'] = $groupPrincipal['administrators'];
                        $addressBook['members'] = $groupPrincipal['members'];
                        $groupAddressBook = new Group\GroupAddressBook($this->carddavBackend, $addressBook);

                        if (!$groupAddressBook->isDisabled()) {
                            $children[] = $groupAddressBook;
                        }
                    }
                }
            }
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
