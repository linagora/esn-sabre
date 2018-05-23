<?php

namespace ESN\CardDAV;

use Sabre\DAV;
use ESN\Utils\Utils as Utils;
use ESN\DAV\Sharing\Plugin as SPlugin;

class AddressBook extends \Sabre\CardDAV\AddressBook implements \ESN\DAV\ISortableCollection, Sharing\ISharedAddressBook {
    function getChildACL() {
        return $this->getACL();
    }

    function getChild($uri) {
        $obj = $this->carddavBackend->getCard($this->addressBookInfo['id'], $uri);
        if (!$obj) throw new \Sabre\DAV\Exception\NotFound('Card not found');
        $obj['acl'] = $this->getChildACL();
        return new \Sabre\CardDAV\Card($this->carddavBackend, $this->addressBookInfo, $obj);
    }

    function getACL() {
        if($properties = $this->getProperties(['{DAV:}acl'])) {
            if (!in_array('dav:write', $properties['{DAV:}acl'])) {
                $acl = [
                    [
                        'privilege' => '{DAV:}read',
                        'principal' => $this->getOwner(),
                        'protected' => true
                    ]
                ];

                $acl = $this->updateAclWithPublicRight($acl);
                $acl = $this->updateAclWithShareAccess($acl);
                return $acl;
            }
        }

        $acl = parent::getACL();
        $acl = $this->updateAclWithPublicRight($acl);
        $acl = $this->updateAclWithShareAccess($acl);

        $index = array_search('{DAV:}owner', array_column($acl, 'principal'));
        if ($index >= 0) {
            $acl[$index]['principal'] = $this->getOwner();
        }

        return $acl;
    }

    /**
     * Returns a list of properties for this nodes.
     *
     * The properties list is a list of propertynames the client requested,
     * encoded in clark-notation {xmlnamespace}tagname
     *
     * If the array is empty, it means 'all properties' were requested.
     *
     * @param array $properties
     * @return array
     */
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

        return $response;
    }

    function setACL(array $acl) {
        $authenticatedPrivileges = [];

        foreach ($acl as $ace) {
            if ($ace->principal !== '{DAV:}authenticated') {
                throw new DAV\Exception\BadRequest('The privilege you specified (' . $ace->principal . ') is not supported on this node');
            }

            $authenticatedPrivileges[] = $ace->privilege;
        }

        $this->savePublicRight($this->getHighestPublicRight($authenticatedPrivileges));
    }

    function getChildren($offset = 0, $limit = 0, $sort = null, $filters = null) {
        $objs = $this->carddavBackend->getCards($this->addressBookInfo['id'], $offset, $limit, $sort, $filters);
        $children = [];
        foreach($objs as $obj) {
            $obj['acl'] = $this->getChildACL();
            $children[] = new \Sabre\CardDAV\Card($this->carddavBackend,$this->addressBookInfo,$obj);
        }
        return $children;
    }

    function getChildCount() {
        return $this->carddavBackend->getCardCount($this->addressBookInfo['id']);
    }

    private function savePublicRight($privilege) {
        $addressBookInfo = [];
        $addressBookInfo['principaluri'] = $this->addressBookInfo['principaluri'];
        $addressBookInfo['uri'] = $this->addressBookInfo['uri'];

        $this->carddavBackend->saveAddressBookPublicRight($this->addressBookInfo['id'], $privilege, $addressBookInfo);
    }

    public function getSupportedPublicRights() {
        return $this->carddavBackend->PUBLIC_RIGHTS;
    }

    function isPublic() {
        $public = $this->getPublicRight();

        return in_array($public, $this->carddavBackend->PUBLIC_RIGHTS);
    }

    function getPublicRight() {

        return $this->carddavBackend->getAddressBookPublicRight($this->addressBookInfo['id']);

    }

    function getShareOwner() {
        return $this->getOwner();
    }

    function getInviteStatus() {
        return SPlugin::INVITE_ACCEPTED;
    }

    function updateInviteStatus($inviteStatus) {
        throw new DAV\Exception\MethodNotAllowed('This is not a shared address book');
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
        return SPlugin::ACCESS_SHAREDOWNER;
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
        return '/addressbooks/' . Utils::getUserIdFromPrincipalUri($this->getShareOwner()) . '/' . $this->addressBookInfo['uri'];
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
        $this->carddavBackend->updateInvites($this->addressBookInfo['id'], $sharees);
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
        $invites = $this->carddavBackend->getInvites($this->addressBookInfo['id']);

        $invites[] = new \Sabre\DAV\Xml\Element\Sharee([
            'href' => \Sabre\HTTP\encodePath($this->getOwner()),
            'access' => $this->getShareAccess(),
            'inviteStatus' => SPlugin::INVITE_ACCEPTED,
            'properties' => [],
            'principal' => $this->getOwner()
        ]);

        return $invites;
    }

    private function getHighestPublicRight($privileges) {
        $privilegeScores = [
            '{DAV:}read'  => 1,
            '{DAV:}write' => 2,
            '{DAV:}all'   => 3
        ];

        $highestScore = 0;
        $result = '';

        foreach ($privileges as $privilege) {
            if ($privilegeScores[$privilege] > $highestScore) {
                $highestScore = $privilegeScores[$privilege];
                $result = $privilege;
            }
        }

        return $result;
    }

    private function updateAclWithPublicRight($acl) {
        $public_right = $this->getPublicRight();

        if (isset($public_right) && strlen($public_right) > 0) {
            $acl[] = [
                'privilege' => $public_right,
                'principal' => '{DAV:}authenticated'
            ];

            if ($public_right === '{DAV:}write') {
                $acl[] = [
                    'privilege' => '{DAV:}read',
                    'principal' => '{DAV:}authenticated'
                ];
            }
        }

        return $acl;
    }

    private function updateAclWithShareAccess($acl) {
        $sharees = $this->getInvites();

        foreach ($sharees as $sharee) {
            if ($sharee->inviteStatus !== SPlugin::INVITE_ACCEPTED) {
                continue;
            }

            switch($sharee->access) {
                case SPlugin::ACCESS_ADMINISTRATION:
                    $acl[] = [
                        'privilege' => '{DAV:}share',
                        'principal' => $sharee->principal,
                        'protected' => true
                    ];
                case SPlugin::ACCESS_READWRITE:
                    $acl[] = [
                        'privilege' => '{DAV:}write-content',
                        'principal' => $sharee->principal,
                        'protected' => true
                    ];
                    $acl[] = [
                        'privilege' => '{DAV:}bind',
                        'principal' => $sharee->principal,
                        'protected' => true
                    ];
                    $acl[] = [
                        'privilege' => '{DAV:}unbind',
                        'principal' => $sharee->principal,
                        'protected' => true
                    ];
                case SPlugin::ACCESS_READ:
                    $acl[] = [
                        'privilege' => '{DAV:}read',
                        'principal' => $sharee->principal,
                        'protected' => true
                    ];
                    break;
            }
        }

        return $acl;
    }

}
