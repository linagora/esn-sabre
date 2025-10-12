<?php

namespace ESN\CardDAV\Sharing;

use \ESN\Utils\Utils as Utils;
use \Sabre\DAV\Sharing\Plugin as SPlugin;

#[\AllowDynamicProperties]
class SharedAddressBook extends \Sabre\CardDAV\AddressBook implements \ESN\DAV\ISortableCollection, ISharedAddressBook {
    function getACL() {
        $acl[] = [
            'privilege' => '{DAV:}read',
            'principal' => $this->getOwner(),
            'protected' => true
        ];

        // If user is delegated from another user, he can change delegated address book properties
        if (Utils::isUserPrincipal($this->addressBookInfo['share_owner'])) {
            $acl[] = [
                'privilege' => '{DAV:}write-properties',
                'principal' => $this->getOwner(),
                'protected' => true
            ];
        }

        return $acl;
    }

    function getChildACL() {
        return $this->getACL();
    }

    function getChild($uri) {
        return null;
    }

    function getChildren($offset = 0, $limit = 0, $sort = null, $filters = null) {
        return [];
    }

    function getMultipleChildren(array $paths) {
        return [];
    }

    function getChildCount() {
        return 0;
    }

    function getProperties($properties) {
        $response = parent::getProperties($properties);

        if (in_array('acl', $properties)) {
            $response['acl'] = $this->getACL();
        }

        if (in_array('{DAV:}invite', $properties)) {
            $response['{DAV:}invite'] = $this->getInvites();
        }

        if (in_array('{DAV:}share-access', $properties)) {
            $response['{DAV:}share-access'] = $this->getShareAccess();
        }

        if (in_array('{http://open-paas.org/contacts}source', $properties)) {
            $response['{http://open-paas.org/contacts}source'] = new \Sabre\DAV\Xml\Property\Href($this->getShareResourceUri());
        }

        return $response;
    }

    /**
     * Updates properties on this node.
     *
     * This method received a PropPatch object, which contains all the
     * information about the update.
     *
     * To update specific properties, call the 'handle' method on this object.
     * Read the PropPatch documentation for more information.
     *
     * @param PropPatch $propPatch
     * @return void
     */
    function propPatch(\Sabre\DAV\PropPatch $propPatch) {
        return $this->carddavBackend->updateSharedAddressBook(
            $this->addressBookInfo['id'],
            $propPatch
        );
    }

    /**
     * Deletes the current node
     *
     * @return void
     */
    function delete() {

        $this->carddavBackend->deleteSharedAddressBook(
            $this->addressBookInfo['id']
        );

    }

    function getInviteStatus() {
        return $this->addressBookInfo['share_invitestatus'];
    }

    function replyInvite($inviteStatus, $options) {
        $this->carddavBackend->replyInvite($this->addressBookInfo['id'], $inviteStatus, $options);
    }

    function getShareOwner() {
        return $this->addressBookInfo['share_owner'];
    }

    /**
     * Returns the 'access level' for the instance of this shared resource.
     *
     * The value should be one of the Sabre\DAV\Sharing\Plugin::ACCESS_
     * constants.
     *
     * @return int
     */
    function getShareAccess() {
        return $this->addressBookInfo['share_access'];
    }

    /**
     * This function must return a URI that uniquely identifies the shared
     * resource. This URI should be identical across instances, and is
     * also used in several other XML bodies to connect invites to
     * resources.
     *
     * This may simply be a relative reference to the original shared instance,
     * but it could also be a urn. As long as it's a valid URI and unique.
     *
     * @return string
     */
    function getShareResourceUri() {
        return 'addressbooks/' . Utils::getPrincipalIdFromPrincipalUri($this->getShareOwner()) . '/' . $this->addressBookInfo['share_resource_uri'];
    }

    /**
     * Updates the list of sharees.
     *
     * Every item must be a Sharee object.
     *
     * @param \Sabre\DAV\Xml\Element\Sharee[] $sharees
     * @return void
     */
    function updateInvites(array $sharees) {
        throw new DAV\Exception\MethodNotAllowed('You are not allowed to share a shared address book');
    }

    /**
     * Returns the list of people whom this resource is shared with.
     *
     * Every item in the returned array must be a Sharee object with
     * at least the following properties set:
     *
     * * $href
     * * $shareAccess
     * * $inviteStatus
     *
     * and optionally:
     *
     * * $properties
     *
     * @return \Sabre\DAV\Xml\Element\Sharee[]
     */
    function getInvites() {
        $result[] = new \Sabre\DAV\Xml\Element\Sharee([
            'href' => $this->addressBookInfo['share_href'],
            'access' => (int)$this->addressBookInfo['share_access'],
            'inviteStatus' => (int)$this->addressBookInfo['share_invitestatus'],
            'properties' => !empty($this->addressBookInfo['share_displayname']) ? [ '{DAV:}displayname' => $this->addressBookInfo['share_displayname'] ] : [],
            'principal' => $this->addressBookInfo['principaluri']
        ]);

        return $result;
    }

    function setPublishStatus($value) {
        throw new DAV\Exception\MethodNotAllowed('You are not allowed to publish a shared address book');
    }
}
