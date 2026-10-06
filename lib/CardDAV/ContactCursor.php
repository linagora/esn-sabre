<?php

namespace ESN\CardDAV;

use \Sabre\DAV\Exception\BadRequest;
use ESN\DAV\SortOrder;

/**
 * Position in a contact listing, handed to clients as an opaque string. The fn_sort or email_sort key
 * binds the cursor to its sort field, while _id and order determine its position and direction.
 */
final class ContactCursor
{
    /**
     * @param array $card a card returned by Backend\Mongo::getCardsOfAddressBooks
     */
    public static function encode(array $card, string $order = SortOrder::ASC, string $sort = ContactSort::FN): string
    {
        $field = ContactSort::FIELDS[$sort];
        $json = json_encode([ $field => $card[$field], '_id' => $card['id'], SortOrder::PARAMETER => $order ]);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array|null the sort value and id Backend\Mongo::getCardsOfAddressBooks resumes after,
     *                    null for the first page
     * @throws BadRequest when the cursor was not built by encode
     */
    public static function decode(?string $cursor, string $order = SortOrder::ASC, string $sort = ContactSort::FN): ?array
    {
        if ($cursor === null) {
            return null;
        }

        $data = self::decodeJsonObject($cursor);
        $field = ContactSort::FIELDS[$sort];
        $sortValue = $data[$field] ?? null;
        $id = $data['_id'] ?? null;

        if (!is_string($sortValue) || !self::isObjectId($id)) {
            throw new BadRequest('Invalid after cursor');
        }

        // Cursors issued before order was supported always referred to ascending listings.
        $cursorOrder = array_key_exists(SortOrder::PARAMETER, $data) ? $data[SortOrder::PARAMETER] : SortOrder::ASC;
        if (!in_array($cursorOrder, [SortOrder::ASC, SortOrder::DESC], true) || $cursorOrder !== $order) {
            throw new BadRequest('Invalid after cursor order');
        }

        return [ $field => $sortValue, 'id' => $id ];
    }

    /**
     * @return array|null the JSON object encoded in base64url, null when the cursor is not one
     */
    private static function decodeJsonObject(string $cursor): ?array
    {
        $base64 = strtr($cursor, '-_', '+/');
        $json = base64_decode(str_pad($base64, strlen($base64) + (4 - strlen($base64) % 4) % 4, '='), true);
        $data = $json === false ? null : json_decode($json, true);

        return is_array($data) ? $data : null;
    }

    private static function isObjectId($value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{24}$/', $value) === 1;
    }
}
