<?php

namespace ESN\CardDAV;


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
                return [
                    [
                        'privilege' => '{DAV:}read',
                        'principal' => $this->getOwner(),
                        'protected' => true,
                    ]
                ];
            }
        }

        return parent::getACL();
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


}
