<?php

namespace ESN\CardDAV;

class CardDAVBackendMock extends \Sabre\CardDAV\Backend\AbstractBackend {
    function getAddressBooksForUser($principalUri) {

    }
    function updateAddressBook($addressBookId, \Sabre\DAV\PropPatch $propPatch) {

    }
    function createAddressBook($principalUri, $url, array $properties) {

    }
    function deleteAddressBook($addressBookId) {

    }
    function getCards($addressbookId) {

    }
    function getCard($addressBookId, $cardUri) {

    }
    function createCard($addressBookId, $cardUri, $cardData) {

    }
    function updateCard($addressBookId, $cardUri, $cardData) {

    }
    function deleteCard($addressBookId, $cardUri) {

    }
}
