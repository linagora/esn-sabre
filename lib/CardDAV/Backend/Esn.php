<?php

namespace ESN\CardDAV\Backend;

class Esn extends Mongo {

    public $CONTACTS_URI = 'contacts';

    public $COLLECTED_URI = 'collected';

    function getAddressBooksForUser($principalUri) {
        $books = parent::getAddressBooksForUser($principalUri);

        if (count($books) == 0) {
            // No addressbooks yet, inject our default addressbooks
            parent::createAddressBook($principalUri, $this->CONTACTS_URI, []);
            parent::createAddressBook($principalUri, $this->COLLECTED_URI, []);

            $books = parent::getAddressBooksForUser($principalUri);
        }

        return $books;
    }
}
