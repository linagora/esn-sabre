<?php

namespace ESN\CardDAV\Backend;

use Sabre\CardDAV\Backend\BackendInterface;

/**
 * Adds support for sharing features to a CardDAV server.
 *
 * CardDAV backends that implement this interface, must make the following
 * modifications to getAddressBooksForUser:
 *
 * 1. Return shared address books for users.
 * 2. For every address book, return share-resource-uri. This strings is a URI or
 *    relative URI reference that must be unique for every address book, but
 *    identical for every instance of the same shared address book.
 * 3. For every address book, you must return a share-access element. This element
 *    should contain one of the Sabre\DAV\Sharing\Plugin:ACCESS_* constants and
 *    indicates the access level the user has.
 *
 * @copyright Copyright (C) fruux GmbH (https://fruux.com/)
 * @author Evert Pot (http://evertpot.com/)
 * @license http://sabre.io/license/ Modified BSD License
 */
interface SharingSupport extends BackendInterface {

    /**
     *
     * @param  string $principalUri
     * @return array
     */
    function getSharedAddressBooksForUser($principalUri);

    /**
     * Updates the list of shares.
     *
     * @param mixed $addressBookId
     * @param \Sabre\DAV\Xml\Element\Sharee[] $sharees
     * @return void
     */
    function updateInvites($addressBookId, array $sharees);

    /**
     * Returns the list of people whom this address book is shared with.
     *
     * Every item in the returned list must be a Sharee object with at
     * least the following properties set:
     *   $href
     *   $shareAccess
     *   $inviteStatus
     *
     * and optionally:
     *   $properties
     *
     * @param mixed $addressBookId
     * @return \Sabre\DAV\Xml\Element\Sharee[]
     */
    function getInvites($addressBookId);

    /**
     * Publishes an address book
     *
     * @param mixed $addressBookId
     * @param bool $value
     * @return void
     */
    function setPublishStatus($addressBookId, $value);

    function replyInvite($addressBookId, $inviteStatus, $options);

}
