<?php

namespace ESN\CardDAV;

/**
 * Filters of a contact listing of one address book (search, modifiedBefore, email), as a MongoDB query on its cards.
 */
final class ContactFilters
{
    public static function query(string $addressBookId, ?array $filters): array
    {
        $query = [ 'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId) ];

        if (isset($filters['search'])) {
            $query['carddata'] = new \MongoDB\BSON\Regex('.*' . $filters['search'] . '.*', 'i');
        }
        if (isset($filters['modifiedBefore'])) {
            $query['lastmodified'] = [
                '$lt' => (int)$filters['modifiedBefore']
            ];
        }
        if (isset($filters['email'])) {
            // Exact match on the preferred email, case insensitive through the collation of the email sort index
            $query['email_sort'] = $filters['email'];
        }

        return $query;
    }
}
