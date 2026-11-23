<?php

namespace ESN\DAV\ChangeLog;

/**
 * A WebDAV sync log (RFC 6578): the change rows, and the collections (calendars or address books) they belong to.
 *
 * Each collection carries its current `synctoken` and a `prunedsynctoken` watermark: the changes below it may have been
 * pruned, so a client holding an older sync token must resync from scratch.
 */
final class ChangeLog
{
    public const PRUNED_SYNC_TOKEN = 'prunedsynctoken';

    private function __construct(
        public readonly string $changesCollection,
        public readonly string $ownerField,
        public readonly string $ownersCollection
    ) {
    }

    public static function calendars(): self
    {
        return new self('calendarchanges', 'calendarid', 'calendars');
    }

    public static function addressBooks(): self
    {
        return new self('addressbookchanges', 'addressbookid', 'addressbooks');
    }

    /**
     * Whether the changes since $syncToken may have been pruned. Read it AFTER the changes: pruning raises the watermark
     * before deleting, so a watermark not above $syncToken proves that every change read was still there.
     *
     * @param array|\ArrayAccess|null $owner the calendar or address book, read with its prunedsynctoken
     */
    public static function wasPrunedSince($owner, int $syncToken): bool
    {
        return $syncToken < (int) ($owner[self::PRUNED_SYNC_TOKEN] ?? 0);
    }
}
