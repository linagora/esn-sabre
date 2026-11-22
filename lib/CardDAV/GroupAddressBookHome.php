<?php

namespace ESN\CardDAV;

#[\AllowDynamicProperties]
class GroupAddressBookHome extends AddressBookHome {
    /**
     * @var \Sabre\DAVACL\PrincipalBackend\BackendInterface|null
     */
    private $principalBackend;

    /**
     * The principals of the members of the group, read on first use.
     *
     * @var string[]|null
     */
    private $members;

    /**
     * @param \Sabre\CardDAV\Backend\BackendInterface $carddavBackend
     * @param array $principal The group principal. Its 'members', when it has
     *              them, are used as they are; otherwise they are asked to
     *              $principalBackend, only when an address book of the group is
     *              listed: reading them lists every user of the domain.
     * @param \Sabre\DAVACL\PrincipalBackend\BackendInterface|null $principalBackend
     */
    function __construct(\Sabre\CardDAV\Backend\BackendInterface $carddavBackend, $principal, $principalBackend = null) {
        parent::__construct($carddavBackend, $principal);

        $this->principalBackend = $principalBackend;
        $this->members = $principal['members'] ?? null;
    }

    /**
     * @return string[]
     */
    private function getMembers() {
        if ($this->members === null) {
            $this->members = $this->principalBackend
                ? $this->principalBackend->getGroupMemberSet($this->principalUri)
                : [];
        }

        return $this->members;
    }
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
            $addressBook['members'] = $this->getMembers();

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
