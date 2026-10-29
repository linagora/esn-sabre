<?php

namespace ESN\CardDAV;

use ESN\Utils\Env;

/**
 * Filters of a contact listing of one address book (search, modifiedBefore, email), as a MongoDB query on its cards.
 */
final class ContactFilters
{
    /**
     * Minimum length, in characters, of a search: a shorter one matches no contact rather than scanning the whole
     * address book. Zero or a negative value removes the minimum.
     */
    const SEARCH_MIN_LENGTH_SETTING = 'SABRE_CONTACT_SEARCH_MIN_LENGTH';
    const DEFAULT_SEARCH_MIN_LENGTH = 3;

    public static function query(string $addressBookId, ?array $filters): array
    {
        $query = [ 'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId) ];

        if (isset($filters['search'])) {
            // A case insensitive literal substring match: the search is quoted so that it is never read as a pattern
            $query['carddata'] = new \MongoDB\BSON\Regex(preg_quote((string)$filters['search']), 'i');
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

    /**
     * Whether a search is long enough to be run, see SEARCH_MIN_LENGTH_SETTING. Leading and trailing blanks do not
     * count.
     */
    public static function isSearchLongEnough(string $search): bool
    {
        return mb_strlen(trim($search), 'UTF-8') >= self::searchMinLength();
    }

    public static function searchMinLength(): int
    {
        return max(0, Env::getInteger(self::SEARCH_MIN_LENGTH_SETTING, self::DEFAULT_SEARCH_MIN_LENGTH));
    }
}
