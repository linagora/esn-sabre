<?php

namespace ESN\CardDAV\Backend;

#[\AllowDynamicProperties]
class Esn extends Mongo {

    const CONTACTS_URI = 'contacts';
    const COLLECTED_URI = 'collected';

    function getAddressBooksFor($principalUri) {
        return parent::getAddressBooksForUser($principalUri);
    }

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
