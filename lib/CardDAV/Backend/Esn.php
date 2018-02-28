<?php

namespace ESN\CardDAV\Backend;

class Esn extends Mongo {

    const CONTACTS_URI = 'contacts';
    const COLLECTED_URI = 'collected';

    function getAddressBooksForUser($principalUri) {
        if (!parent::addressBookExists($principalUri, self::CONTACTS_URI)) {
            parent::createAddressBook($principalUri, self::CONTACTS_URI, []);
        }

        if (!parent::addressBookExists($principalUri, self::COLLECTED_URI)) {
            parent::createAddressBook($principalUri, self::COLLECTED_URI, []);
        }

        return parent::getAddressBooksForUser($principalUri);
    }
}
