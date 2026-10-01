<?php

namespace ESN\CardDAV;

use \Sabre\DAV\Exception\BadRequest;

/**
 * Position in a contact listing sorted by full name, handed to clients as an opaque string:
 * base64url({"fn_sort": ..., "_id": ...}), the sort keys of the last card of a page.
 */
final class ContactCursor
{
    /**
     * @param array $card a card returned by Backend\Mongo::getCardsOfAddressBooks
     */
    public static function encode(array $card): string
    {
        $json = json_encode([ 'fn_sort' => $card['fn_sort'], '_id' => $card['id'] ]);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array|null the ['fn_sort' => ..., 'id' => ...] Backend\Mongo::getCardsOfAddressBooks resumes after,
     *                    null for the first page
     * @throws BadRequest when the cursor was not built by encode
     */
    public static function decode($cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }

        $json = false;
        if (is_string($cursor)) {
            $base64 = strtr($cursor, '-_', '+/');
            $json = base64_decode(str_pad($base64, strlen($base64) + (4 - strlen($base64) % 4) % 4, '='), true);
        }
        $data = $json === false ? null : json_decode($json, true);

        if (!is_array($data)
            || !is_string($data['fn_sort'] ?? null)
            || !is_string($data['_id'] ?? null)
            || !preg_match('/^[0-9a-f]{24}$/', $data['_id'])) {
            throw new BadRequest('Invalid after cursor');
        }

        return [ 'fn_sort' => $data['fn_sort'], 'id' => $data['_id'] ];
    }
}
