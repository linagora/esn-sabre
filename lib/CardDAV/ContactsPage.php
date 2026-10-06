<?php

namespace ESN\CardDAV;

use ESN\DAV\SortOrder;

/**
 * One page of the contacts of some address books, sorted by full name or email, read in one query and paginated with an
 * opaque cursor (see ContactCursor).
 */
final class ContactsPage
{
    /**
     * @param \Sabre\CardDAV\Backend\BackendInterface $backend backend providing getCardsOfAddressBooks
     * @param array $paths DAV paths through which the address books are read, keyed by address book id
     * @param array|null $after cursor of the previous page decoded by ContactCursor::decode, null for the first page
     * @param string $order SortOrder::ASC or SortOrder::DESC, the order the cursor was decoded for
     * @param string $sort ContactSort::FN or ContactSort::EMAIL, the sort the cursor was decoded for
     * @return array [ 'items' => [ [ 'path', 'etag', 'carddata' ], ... ], 'next' => cursor or null ]
     */
    public static function read(\Sabre\CardDAV\Backend\BackendInterface $backend, array $paths, int $limit, ?array $after,
                                string $order = SortOrder::ASC, string $sort = ContactSort::FN): array
    {
        // One more card than asked tells whether there is a next page
        $cards = $backend->getCardsOfAddressBooks(array_keys($paths), $limit + 1, $after, $order, $sort);

        $next = count($cards) > $limit ? ContactCursor::encode($cards[$limit - 1], $order, $sort) : null;

        $items = [];
        foreach (array_slice($cards, 0, $limit) as $card) {
            $items[] = [
                'path' => $paths[$card['addressbookid']] . '/' . $card['uri'],
                'etag' => $card['etag'],
                'carddata' => $card['carddata']
            ];
        }

        return [ 'items' => $items, 'next' => $next ];
    }
}
