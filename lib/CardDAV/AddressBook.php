<?php

namespace ESN\CardDAV;

use Sabre\DAV;

class AddressBook extends \Sabre\CardDAV\AddressBook implements \ESN\DAV\ISortableCollection {
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
                return $acl;
            }
        }

        $acl = parent::getACL();
        $acl = $this->updateAclWithPublicRight($acl);

        $index = array_search('{DAV:}owner', array_column($acl, 'principal'));
        if ($index >= 0) {
            $acl[$index]['principal'] = $this->getOwner();
        }

        return $acl;
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
}
