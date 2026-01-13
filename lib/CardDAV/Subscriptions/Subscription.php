<?php

namespace ESN\CardDAV\Subscriptions;

use ESN\CardDAV\Backend\SubscriptionSupport;
use ESN\DAV\Sharing\Plugin as SPlugin;
use ESN\Utils\Utils;
use Sabre\DAV\Collection;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Xml\Property\Href;
use Sabre\DAVACL\ACLTrait;
use Sabre\DAVACL\IACL;

/**
 * Subscription Node
 *
 * This node represents a subscription.
 */
#[\AllowDynamicProperties]
class Subscription extends Collection implements ISubscription, IACL {

    use ACLTrait;

    /**
     * carddavBackend
     *
     * @var SubscriptionSupport
     */
    protected $carddavBackend;

    /**
     * subscriptionInfo
     *
     * @var array
     */
    protected $subscriptionInfo;

    /**
     * Constructor
     *
     * @param SubscriptionSupport $carddavBackend
     * @param array $subscriptionInfo
     */
    function __construct(SubscriptionSupport $carddavBackend, array $subscriptionInfo) {

        $this->carddavBackend = $carddavBackend;
        $this->subscriptionInfo = $subscriptionInfo;

        $required = [
            'id',
            'uri',
            'principaluri',
            '{DAV:}acl',
            '{http://open-paas.org/contacts}source',
            ];

        foreach ($required as $r) {
            if (!isset($subscriptionInfo[$r])) {
                throw new \InvalidArgumentException('The ' . $r . ' field is required when creating a subscription node');
            }
        }

    }

    /**
     * Returns the name of the node.
     *
     * This is used to generate the url.
     *
     * @return string
     */
    function getName() {

        return $this->subscriptionInfo['uri'];

    }

    /**
     * Returns the last modification time
     *
     * @return int
     */
    function getLastModified() {

        if (isset($this->subscriptionInfo['lastmodified'])) {
            return $this->subscriptionInfo['lastmodified'];
        }

    }

    /**
     * Deletes the current node
     *
     * @return void
     */
    function delete() {
        // Check unbind privilege before deleting
        $this->checkDeleteAccess();

        $this->carddavBackend->deleteSubscription(
            $this->subscriptionInfo['id']
        );

    }

    /**
     * Returns an array with all the child nodes
     *
     * @return \Sabre\DAV\INode[]
     */
    function getChildren() {
        // Get cards from the source address book
        $sourceAddressBookInfo = $this->getSourceAddressBookInfo();
        if (!$sourceAddressBookInfo) {
            return [];
        }

        $objs = $this->carddavBackend->getCards($sourceAddressBookInfo['id']);
        $children = [];

        foreach($objs as $obj) {
            $obj = (array) $obj; // Convert BSONDocument to array
            $obj['acl'] = $this->getChildACL();
            $children[] = new SubscriptionCard($this->carddavBackend, $sourceAddressBookInfo, $obj, $this);
        }
        return $children;
    }

    /**
     * Returns a single child node by name
     *
     * @param string $name
     * @return \Sabre\DAV\INode
     */
    function getChild($name) {
        $sourceAddressBookInfo = $this->getSourceAddressBookInfo();
        if (!$sourceAddressBookInfo) {
            throw new \Sabre\DAV\Exception\NotFound('Card not found');
        }

        $obj = $this->carddavBackend->getCard($sourceAddressBookInfo['id'], $name);
        if (!$obj) {
            throw new \Sabre\DAV\Exception\NotFound('Card not found');
        }
        $obj = (array) $obj; // Convert BSONDocument to array
        $obj['acl'] = $this->getChildACL();

        return new SubscriptionCard($this->carddavBackend, $sourceAddressBookInfo, $obj, $this);
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
     * @param string $name Name of the file
     * @param resource|string $vcardData Initial payload
     * @return string|null
     */
    function createFile($name, $vcardData = null) {
        // Check write access before creating
        $this->checkWriteAccess();

        if (is_resource($vcardData)) {
            $vcardData = stream_get_contents($vcardData);
        }
        // Converting to UTF-8, if needed
        $vcardData = \Sabre\DAV\StringUtil::ensureUTF8($vcardData);

        // Create the card in the source address book
        $sourceAddressBookInfo = $this->getSourceAddressBookInfo();
        if (!$sourceAddressBookInfo) {
            throw new \Sabre\DAV\Exception\Forbidden('Cannot create card: source address book not found');
        }

        return $this->carddavBackend->createCard($sourceAddressBookInfo['id'], $name, $vcardData);
    }

    /**
     * Returns address book info for the source address book that this subscription points to.
     * This is used when creating/updating/deleting Card objects so that operations
     * are performed on the source address book instead of the subscription.
     *
     * @return array|null
     */
    protected function getSourceAddressBookInfo() {
        $sourceKey = '{http://open-paas.org/contacts}source';
        if (!isset($this->subscriptionInfo[$sourceKey])) {
            return null;
        }

        // Parse the source URL to extract principalUri and addressbook URI
        // Format: /addressbooks/{principalId}/{addressbookUri}
        $sourcePath = $this->subscriptionInfo[$sourceKey];
        $parts = explode('/', trim($sourcePath, '/'));

        if (count($parts) < 3 || $parts[0] !== 'addressbooks') {
            error_log("Invalid subscription source format: " . $sourcePath);
            return null;
        }

        $principalId = $parts[1];
        $addressbookUri = $parts[2];
        $principalUri = 'principals/users/' . $principalId;

        // Get the source address book
        $addressbooks = $this->carddavBackend->getAddressBooksForUser($principalUri);
        foreach ($addressbooks as $addressbook) {
            if ($addressbook['uri'] === $addressbookUri) {
                return $addressbook;
            }
        }

        error_log("Source address book not found for subscription: " . $sourcePath);
        return null;
    }

    /**
     * Returns the ACL for child nodes (contacts)
     *
     * @return array
     */
    function getChildACL() {
        return $this->getACL();
    }

    /**
     * Checks if the subscription has write privileges
     *
     * @throws \Sabre\DAV\Exception\Forbidden
     */
    protected function checkWriteAccess() {
        $acl = $this->getACL();
        $hasWrite = false;

        foreach ($acl as $ace) {
            // Check for write privileges: write, write-content, bind, unbind, or all
            if (in_array($ace['privilege'], [
                '{DAV:}write',
                '{DAV:}write-content',
                '{DAV:}bind',
                '{DAV:}unbind',
                '{DAV:}all'
            ])) {
                $hasWrite = true;
                break;
            }
        }

        if (!$hasWrite) {
            throw new \Sabre\DAV\Exception\Forbidden('You do not have write access to this subscription');
        }
    }

    /**
     * Checks if the subscription can be deleted
     *
     * @throws \Sabre\DAV\Exception\Forbidden
     */
    protected function checkDeleteAccess() {
        $acl = $this->getACL();
        $hasDelete = false;

        foreach ($acl as $ace) {
            // Check for delete privileges: unbind or all
            if (in_array($ace['privilege'], [
                '{DAV:}unbind',
                '{DAV:}all'
            ])) {
                $hasDelete = true;
                break;
            }
        }

        if (!$hasDelete) {
            throw new \Sabre\DAV\Exception\Forbidden('You do not have permission to delete this subscription');
        }
    }

    /**
     * Checks if properties can be updated on this subscription
     *
     * @throws \Sabre\DAV\Exception\Forbidden
     */
    protected function checkWritePropertiesAccess() {
        $acl = $this->getACL();
        $hasWriteProperties = false;

        foreach ($acl as $ace) {
            // Check for write-properties or all privilege
            if (in_array($ace['privilege'], [
                '{DAV:}write-properties',
                '{DAV:}write',
                '{DAV:}all'
            ])) {
                $hasWriteProperties = true;
                break;
            }
        }

        if (!$hasWriteProperties) {
            throw new \Sabre\DAV\Exception\Forbidden('You do not have permission to modify properties of this subscription');
        }
    }

    /**
     * Returns the list of subscribers (addressbook) of this subscription.
     *
     * A subscription itself doesn't have subscribers, but we implement this
     * method to satisfy the interface expected by plugins.
     *
     * @return array Empty array (subscriptions don't have their own subscribers)
     */
    function getSubscribedAddressBooks() {
        return [];
    }

    /**
     * Returns the number of contacts in the source address book.
     *
     * @return int
     */
    function getChildCount() {
        $sourceAddressBookInfo = $this->getSourceAddressBookInfo();
        if (!$sourceAddressBookInfo) {
            return 0;
        }

        return $this->carddavBackend->getCardCount($sourceAddressBookInfo['id']);
    }

    /**
     * Returns the sync token for the source address book.
     *
     * This is used by clients to detect changes in the address book.
     *
     * @return string|null
     */
    function getSyncToken() {
        $sourceAddressBookInfo = $this->getSourceAddressBookInfo();
        if (!$sourceAddressBookInfo) {
            return null;
        }

        if (
            $this->carddavBackend instanceof \Sabre\CardDAV\Backend\SyncSupport &&
            isset($sourceAddressBookInfo['{DAV:}sync-token'])
        ) {
            return $sourceAddressBookInfo['{DAV:}sync-token'];
        }
        if (
            $this->carddavBackend instanceof \Sabre\CardDAV\Backend\SyncSupport &&
            isset($sourceAddressBookInfo['{http://sabredav.org/ns}sync-token'])
        ) {
            return $sourceAddressBookInfo['{http://sabredav.org/ns}sync-token'];
        }

        return null;
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
    function propPatch(PropPatch $propPatch) {
        // Check write-properties privilege before updating
        $this->checkWritePropertiesAccess();

        return $this->carddavBackend->updateSubscription(
            $this->subscriptionInfo['id'],
            $propPatch
        );

    }

    /**
     * Returns a list of properties for this nodes.
     *
     * The properties list is a list of propertynames the client requested,
     * encoded in clark-notation {xmlnamespace}tagname.
     *
     * If the array is empty, it means 'all properties' were requested.
     *
     * Note that it's fine to liberally give properties back, instead of
     * conforming to the list of requested properties.
     * The Server class will filter out the extra.
     *
     * @param array $properties
     * @return array
     */
    function getProperties($properties) {
        $response = [];

        foreach ($properties as $prop) {

            switch ($prop) {
                case '{http://open-paas.org/contacts}source' :
                    $response[$prop] = new Href($this->subscriptionInfo[$prop]);
                    break;
                case 'acl':
                    $response['acl'] = $this->getACL();
                    break;
                default :
                    if (array_key_exists($prop, $this->subscriptionInfo)) {
                        $response[$prop] = $this->subscriptionInfo[$prop];
                    }
                    break;
            }

        }

        return $response;

    }

    /**
     * Returns the owner principal.
     *
     * This must be a url to a principal, or null if there's no owner
     *
     * @return string|null
     */
    function getOwner() {

        return $this->subscriptionInfo['principaluri'];

    }

    /**
     * Returns a list of ACE's for this node.
     *
     * Each ACE has the following properties:
     *   * 'privilege', a string such as {DAV:}read or {DAV:}write. These are
     *     currently the only supported privileges
     *   * 'principal', a url to the principal who owns the node
     *   * 'protected' (optional), indicating that this ACE is not allowed to
     *      be updated.
     *
     * @return array
     */
    function getACL() {
        $acl = [];
        $principal = $this->getOwner();

        // Get privilege from subscriptionInfo (default to read-only)
        $privilege = isset($this->subscriptionInfo['{DAV:}acl'])
            ? $this->subscriptionInfo['{DAV:}acl']
            : ['dav:read'];

        // Convert privilege array to detailed ACL
        // Check what privileges are in the array
        $hasWrite = in_array('dav:write', $privilege) || in_array('dav:unbind', $privilege);
        $hasShare = in_array('dav:share', $privilege) || in_array('dav:administration', $privilege);

        // Grant privileges based on the privilege array
        if ($hasShare) {
            $acl[] = [
                'privilege' => '{DAV:}share',
                'principal' => $principal,
                'protected' => true
            ];
        }

        if ($hasWrite) {
            $acl[] = [
                'privilege' => '{DAV:}write-content',
                'principal' => $principal,
                'protected' => true
            ];
            $acl[] = [
                'privilege' => '{DAV:}bind',
                'principal' => $principal,
                'protected' => true
            ];
            $acl[] = [
                'privilege' => '{DAV:}unbind',
                'principal' => $principal,
                'protected' => true
            ];
        }

        // Always grant read access
        $acl[] = [
            'privilege' => '{DAV:}read',
            'principal' => $principal,
            'protected' => true
        ];

        // Always allow the owner to modify subscription properties (displayname, description)
        $acl[] = [
            'privilege' => '{DAV:}write-properties',
            'principal' => $principal,
            'protected' => true
        ];

        return $acl;
    }

}
