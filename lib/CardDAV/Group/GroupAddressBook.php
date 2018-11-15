<?php

namespace ESN\CardDAV\Group;

use ESN\DAV\Sharing\Plugin as SPlugin;

/**
 * Group address book node
 *
 * This node represents a group address book.
 */
class GroupAddressBook extends \ESN\CardDAV\AddressBook {
    function getACL() {
        $acl = [];
        $acl = $this->updateAclWithShareAccess($acl);

        if (isset($this->addressBookInfo['members'])) {
            $acl = $this->updateAclWithMembersAccess($acl);
        }

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

    function getInvites() {
        return $this->carddavBackend->getInvites($this->addressBookInfo['id']);
    }

    function setMembersRight($privileges) {
        return $this->carddavBackend->setMembersRight($this->addressBookInfo['id'], $privileges);
    }

    function isDisabled() {
        return isset($this->addressBookInfo['{http://open-paas.org/contacts}state']) && $this->addressBookInfo['{http://open-paas.org/contacts}state'] === 'disabled';
    }

    private function updateAclWithMembersAccess($acl) {
        $shareePrincipals = [];

        foreach ($this->getInvites() as $sharee) {
            $shareePrincipals[] = $sharee->principal;
        }

        foreach ($this->addressBookInfo['members'] as $member) {
            
            // If a member is delegated, he does not have group members rights
            if (in_array($member, $shareePrincipals)) continue;

            // Group administrators rights is handled in #updateAclWithAdministratorsRight function
            if (in_array($member, $this->addressBookInfo['administrators'])) continue;

            if($properties = $this->getProperties(['{DAV:}acl'])) {
                foreach ($properties['{DAV:}acl'] as $privilege) {
                    $acl[] = [
                        'privilege' => $privilege,
                        'principal' => $member,
                        'protected' => true
                    ];
                }
            }
        }

        return $acl;
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

    /**
     * Updates the list of sharees.
     *
     * Every item must be a Sharee object.
     *
     * There's no invite status handling in group object so ACCEPTED is forced
     *
     *
     * @param \Sabre\DAV\Xml\Element\Sharee[] $sharees
     * @return void
     */
    function updateInvites(array $sharees) {
        foreach ($sharees as $sharee) {
            if (in_array($sharee->principal, $this->addressBookInfo['administrators'])) {
                throw new \Sabre\DAV\Exception\MethodNotAllowed('Can not delegate for group administrators');
            }

            $sharee->inviteStatus = SPlugin::INVITE_ACCEPTED;
        }

        parent::updateInvites($sharees);
    }
}