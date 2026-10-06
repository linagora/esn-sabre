<?php

namespace ESN\CardDAV;

final class ContactSort
{
    public const PARAMETER = 'sort';
    public const FN = 'fn';
    public const EMAIL = 'email';
    public const FIELDS = [self::FN => 'fn_sort', self::EMAIL => 'email_sort'];
}
