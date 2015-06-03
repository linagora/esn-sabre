<?php

namespace ESN\CardDAV;


class AddressBook extends \Sabre\CardDAV\AddressBook implements \ESN\DAV\ISortableCollection {


    function getChildren($offset = 0, $limit = 0, $sort = null) {
        $objs = $this->carddavBackend->getCards($this->addressBookInfo['id'], $offset, $limit, $sort);
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
