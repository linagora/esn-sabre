<?php

namespace ESN\CardDAV\Sharing;

use \ESN\Utils\Utils as Utils;
use \ESN\DAV\Sharing\Plugin as SPlugin;

#[\AllowDynamicProperties]
class SharedAddressBook extends \Sabre\CardDAV\AddressBook implements \ESN\DAV\ISortableCollection, ISharedAddressBook {
    function getACL() {
        $acl = [];
        $shareAccess = $this->getShareAccess();

        // Grant privileges based on the share access level
        switch($shareAccess) {
            case SPlugin::ACCESS_ADMINISTRATION:
                $acl[] = [
                    'privilege' => '{DAV:}share',
                    'principal' => $this->getOwner(),
                    'protected' => true
                ];
                // Fall through to add read/write privileges
            case SPlugin::ACCESS_READWRITE:
                $acl[] = [
                    'privilege' => '{DAV:}write-content',
                    'principal' => $this->getOwner(),
                    'protected' => true
                ];
                $acl[] = [
                    'privilege' => '{DAV:}bind',
                    'principal' => $this->getOwner(),
                    'protected' => true
                ];
                $acl[] = [
                    'privilege' => '{DAV:}unbind',
                    'principal' => $this->getOwner(),
                    'protected' => true
                ];
                // Fall through to add read privilege
            case SPlugin::ACCESS_READ:
                $acl[] = [
                    'privilege' => '{DAV:}read',
                    'principal' => $this->getOwner(),
                    'protected' => true
                ];
                break;
        }

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
        // Get the card from the source address book
        $sourceAddressBookId = (string)$this->addressBookInfo['addressbookid'];
        $obj = $this->carddavBackend->getCard($sourceAddressBookId, $uri);
        if (!$obj) throw new \Sabre\DAV\Exception\NotFound('Card not found');
        $obj['acl'] = $this->getChildACL();

        // Pass source address book info to Card so CRUD operations work on the source
        $sourceAddressBookInfo = $this->getSourceAddressBookInfo();
        return new \Sabre\CardDAV\Card($this->carddavBackend, $sourceAddressBookInfo, (array) $obj);
    }

    function getChildren($offset = 0, $limit = 0, $sort = null, $filters = null) {
        // Get cards from the source address book
        $sourceAddressBookId = (string)$this->addressBookInfo['addressbookid'];
        $objs = $this->carddavBackend->getCards($sourceAddressBookId, $offset, $limit, $sort, $filters);
        $children = [];

        // Pass source address book info to Cards so CRUD operations work on the source
        $sourceAddressBookInfo = $this->getSourceAddressBookInfo();
        foreach($objs as $obj) {
            $obj['acl'] = $this->getChildACL();
            $children[] = new \Sabre\CardDAV\Card($this->carddavBackend, $sourceAddressBookInfo, $obj);
        }
        return $children;
    }

    function getMultipleChildren(array $paths) {
        // Get multiple cards from the source address book
        $sourceAddressBookId = (string)$this->addressBookInfo['addressbookid'];
        $objs = $this->carddavBackend->getMultipleCards($sourceAddressBookId, $paths);
        $children = [];

        // Pass source address book info to Cards so CRUD operations work on the source
        $sourceAddressBookInfo = $this->getSourceAddressBookInfo();
        foreach($objs as $obj) {
            $obj['acl'] = $this->getChildACL();
            $children[] = new \Sabre\CardDAV\Card($this->carddavBackend, $sourceAddressBookInfo, $obj);
        }
        return $children;
    }

    /**
     * Returns address book info for the source address book.
     * This is used when creating Card objects so that update/delete operations
     * are performed on the source address book instead of the shared instance.
     *
     * @return array
     */
    protected function getSourceAddressBookInfo() {
        $sourceAddressBookId = (string)$this->addressBookInfo['addressbookid'];

        // Create a modified addressBookInfo with the source ID
        // This ensures Card's put() and delete() operations use the source address book
        $sourceInfo = $this->addressBookInfo;
        $sourceInfo['id'] = $sourceAddressBookId;

        return $sourceInfo;
    }

    function getChildCount() {
        // Get count from the source address book
        $sourceAddressBookId = (string)$this->addressBookInfo['addressbookid'];
        return $this->carddavBackend->getCardCount($sourceAddressBookId);
    }

    /**
     * Creates a new file in the directory
     *
     * Data will either be supplied as a stream resource, or in certain cases
     * as a string. Keep in mind that you may have to support either.
     *
     * After successful creation of the file, you may choose to return the ETag
     * of the new file here.
     *
     * The returned ETag must be surrounded by double-quotes (The quotes should
     * be part of the actual string).
     *
     * If you cannot accurately determine the ETag, you should not return it.
     * If you don't store the file exactly as-is (you're transforming it
     * somehow) you should also not return an ETag.
     *
     * This means that if a subsequent GET to this new file does not exactly
     * return the same contents of what was submitted here, you are strongly
     * recommended to omit the ETag.
     *
     * @param string $name Name of the file
     * @param resource|string $vcardData Initial payload
     * @return string|null
     */
    function createFile($name, $vcardData = null) {
        if (is_resource($vcardData)) {
            $vcardData = stream_get_contents($vcardData);
        }
        // Converting to UTF-8, if needed
        $vcardData = \Sabre\DAV\StringUtil::ensureUTF8($vcardData);

        // Create the card in the source address book
        $sourceAddressBookId = (string)$this->addressBookInfo['addressbookid'];
        return $this->carddavBackend->createCard($sourceAddressBookId, $name, $vcardData);
    }

    /**
     * Returns the changes for this address book.
     *
     * This method should return changes from the source address book, not the shared instance.
     *
     * @param string $syncToken
     * @param int $syncLevel
     * @param int $limit
     * @return array|null
     */
    function getChanges($syncToken, $syncLevel, $limit = null) {
        if (!$this->carddavBackend instanceof \Sabre\CardDAV\Backend\SyncSupport) {
            return null;
        }

        // Use the source address book ID for sync operations
        $sourceAddressBookId = (string)$this->addressBookInfo['addressbookid'];
        return $this->carddavBackend->getChangesForAddressBook(
            $sourceAddressBookId,
            $syncToken,
            $syncLevel,
            $limit
        );
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

    /**
     * Returns the list of subscribers (addressbook) of this shared addressbook.
     *
     * A shared addressbook itself doesn't have subscribers in the subscription sense.
     * This method exists to satisfy the interface expected by plugins.
     *
     * @return array Empty array (shared addressbooks don't have their own subscribers)
     */
    function getSubscribedAddressBooks() {
        return [];
    }
}
