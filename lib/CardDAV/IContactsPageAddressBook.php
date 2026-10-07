<?php

namespace ESN\CardDAV;

use ESN\DAV\SortOrder;

/**
 * Address book whose contacts can be listed sorted by full name or email, page after page (see ContactsPage).
 */
interface IContactsPageAddressBook
{
    /**
     * @param string $path DAV path of this address book, prefix of the paths of the items
     * @param array|null $after cursor of the previous page decoded by ContactCursor::decode, null for the first page
     * @param string $order SortOrder::ASC or SortOrder::DESC, the order the cursor was decoded for
     * @return array [ 'items' => [ [ 'path', 'etag', 'carddata' ], ... ], 'next' => cursor or null ]
     */
    function getContactsPage(string $path, int $limit, ?array $after, string $order = SortOrder::ASC, string $sort = ContactSort::FN): array;
}
