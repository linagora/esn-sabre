<?php

namespace ESN\CardDAV\Backend;

#[\AllowDynamicProperties]
class Esn extends Mongo {

    const CONTACTS_URI = 'contacts';
    const COLLECTED_URI = 'collected';
    const DOMAIN_MEMBERS_URI = 'domain-members';
    const DOMAIN_ADDRESS_BOOK_URI = 'dab';

    const DEFAULT_ADDRESS_BOOK_URIS = [self::CONTACTS_URI, self::COLLECTED_URI];

    function getAddressBooksFor($principalUri) {
        return parent::getAddressBooksForUser($principalUri);
    }

    function getAddressBooksForUser($principalUri) {
        $this->ensureDefaultAddressBooks($principalUri);

        return parent::getAddressBooksForUser($principalUri);
    }

    /**
     * Creates the default address books the principal does not have yet,
     * checking them all with a single read. The creation never overwrites a
     * default address book a concurrent request created in the meantime.
     *
     * @param string $principalUri
     * @return void
     */
    function ensureDefaultAddressBooks($principalUri) {
        $missingUris = array_diff(self::DEFAULT_ADDRESS_BOOK_URIS, $this->existingDefaultAddressBookUris($principalUri));

        array_walk($missingUris, fn($uri) => $this->createAddressBookIfAbsent($principalUri, $uri));
    }

    private function existingDefaultAddressBookUris($principalUri) {
        $query = [ 'principaluri' => $principalUri, 'uri' => [ '$in' => self::DEFAULT_ADDRESS_BOOK_URIS ] ];
        $options = [ 'projection' => [ '_id' => 0, 'uri' => 1 ] ];
        $rows = $this->db->selectCollection($this->addressBooksTableName)->find($query, $options);

        return array_map(fn($row) => $row['uri'], iterator_to_array($rows, false));
    }
}
