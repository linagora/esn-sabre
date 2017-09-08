<?php

namespace ESN\CardDAV\Backend;

class Esn extends Mongo {

    public $CONTACTS_URI = 'contacts';

    public $COLLECTED_URI = 'collected';

    function getAddressBooksForUser($principalUri) {
        if (!parent::addressBookExists($principalUri, $this->CONTACTS_URI)) {
            parent::createAddressBook($principalUri, $this->CONTACTS_URI, []);
        }

        if (!parent::addressBookExists($principalUri, $this->COLLECTED_URI)) {
            parent::createAddressBook($principalUri, $this->COLLECTED_URI, []);
        }

        return parent::getAddressBooksForUser($principalUri);
    }
}
