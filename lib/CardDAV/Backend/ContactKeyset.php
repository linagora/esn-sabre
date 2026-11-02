<?php

namespace ESN\CardDAV\Backend;

/**
 * Keyset pagination conditions of the contact listings, sorted by a sort field then _id.
 */
final class ContactKeyset
{
    /**
     * Condition matching the cards sorted after the given one. MongoDB sorts the cards without a sort value
     * (missing or null) before every string, while range operators on a string only match strings.
     */
    public static function cardsSortedAfter(string $field, ?string $value, \MongoDB\BSON\ObjectId $id, bool $descending): array
    {
        $noValue = [ $field => null ];
        if ($value === null) {
            $sameValueAfter = $noValue + [ '_id' => [ ($descending ? '$lt' : '$gt') => $id ] ];
            return $descending
                ? $sameValueAfter
                : [ '$or' => [ $sameValueAfter, [ $field => [ '$type' => 'string' ] ] ] ];
        }

        // Reverse both cursor comparisons along with the sort, including equal-value ties.
        $range = [ $field => [ ($descending ? '$lte' : '$gte') => $value ] ];
        $tieBreak = [ '$nor' => [[ $field => $value, '_id' => [ ($descending ? '$gte' : '$lte') => $id ] ]] ];
        return ($descending ? [ '$or' => [ $range, $noValue ] ] : $range) + $tieBreak;
    }
}
