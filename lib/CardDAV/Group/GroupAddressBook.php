<?php

namespace ESN\CardDAV\Group;

/**
 * Group address book node
 *
 * This node represents a group address book.
 */
class GroupAddressBook extends \ESN\CardDAV\AddressBook {
    function getACL() {
        $acl = [];

        if($properties = $this->getProperties(['{DAV:}acl'])) {
            foreach ($properties['{DAV:}acl'] as $privilege) {
                $acl[] = [
                        'privilege' => $privilege,
                        'principal' => $this->getOwner(),
                        'protected' => true
                ];
            }
        }

        $acl = $this->updateAclWithShareAccess($acl);
        $acl = $this->updateAclWithAdministratorsRight($acl);

        return $acl;
    }

    function getProperties($properties) {
        $response = parent::getProperties($properties);

        $response['{DAV:}group'] = $this->getOwner();

        if (in_array('acl', $properties)) {
            $response['acl'] = $this->getACL();
        }

        return $response;
    }

    function setMembersRight($privileges) {
        return $this->carddavBackend->setMembersRight($this->addressBookInfo['id'], $privileges);
    }

    private function updateAclWithAdministratorsRight($acl) {
        foreach ($this->addressBookInfo['administrators'] as $administrator) {
            $acl[] = [
                'privilege' => '{DAV:}read',
                'principal' => $administrator,
                'protected' => true
            ];
            $acl[] = [
                'privilege' => '{DAV:}write',
                'principal' => $administrator,
                'protected' => true
            ];
            $acl[] = [
                'privilege' => '{DAV:}share',
                'principal' => $administrator,
                'protected' => true
            ];
        }

        return $acl;
    }
}