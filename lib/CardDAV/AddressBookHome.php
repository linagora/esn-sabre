<?php

namespace ESN\CardDAV;

class AddressBookHome extends \Sabre\CardDAV\AddressBookHome {

    /**
     * Returns a list of addressbooks. In contrast to the sabre version of this
     * method, the returned addressbook instance has extra methods.
     *
     * @return array
     */
    function getChildren() {
        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->principalUri);
        $objs = [];
        foreach($addressbooks as $addressbook) {
            $objs[] = new \ESN\CardDAV\AddressBook($this->carddavBackend, $addressbook);
        }
        return $objs;
    }
}
