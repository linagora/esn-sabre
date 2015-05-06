<?php

namespace ESN\CardDAV\Backend;

class Esn extends Mongo {

    public $CONTACTS_URI = 'contacts';

    function getAddressBooksForUser($principalUri) {
        $books = parent::getAddressBooksForUser($principalUri);

        if (count($books) == 0) {
            // No addressbooks yet, inject our default addressbook
            parent::createAddressBook($principalUri, $this->CONTACTS_URI, []);

            $books = parent::getAddressBooksForUser($principalUri);
        }

        return $books;
    }
}
