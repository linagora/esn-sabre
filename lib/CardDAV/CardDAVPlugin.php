<?php

namespace ESN\CardDAV;

use ESN\DAV\MultiGetMissingHrefs;

/**
 * Sabre's CardDAV plugin, with an addressbook-multiget that answers 404 for an
 * href naming no card (RFC 6352 section 8.7), see {@see MultiGetMissingHrefs}.
 */
class CardDAVPlugin extends \Sabre\CardDAV\Plugin {

    /**
     * @param \Sabre\CardDAV\Xml\Request\AddressBookMultiGetReport $report
     * @return void
     */
    function addressbookMultiGetReport($report) {
        MultiGetMissingHrefs::run($this->server, $report, function () use ($report) {
            parent::addressbookMultiGetReport($report);
        });
    }
}
