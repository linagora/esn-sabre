<?php

namespace ESN\CardDAV;

final class ContactSort
{
    public const PARAMETER = 'sort';
    public const FN = 'fn';
    public const EMAIL = 'email';
    public const FIELDS = [self::FN => 'fn_sort', self::EMAIL => 'email_sort'];
    // The offset listing also accepts the insertion order, the unique and indexed _id.
    public const ID = '_id';
    public const OFFSET_LISTING_SORTS = [self::FN, self::EMAIL, self::ID];
}
