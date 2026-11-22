# Database migration history

## Version 1 — Backfill contact sort fields

Upgrades database version `0` to `1`. Existing documents in the `cards` collection are updated with missing
sort fields extracted from their `carddata`, matching contact creation and update:

- `fn_sort`: trimmed full `FN`, preserving case and accents.
- `email_sort`: trimmed email selected by Sabre's `preferred('EMAIL')`; an absent email becomes an empty string.

Contact payloads, ETags, modification times, sync tokens and change history remain unchanged.
Each update checks that the vCard payload is unchanged and the target fields are still missing, so concurrent DAV
updates keep their current sort values.

Contacts are read in `_id` order and updated in batches of at most 500. Missing or invalid vCard data is skipped
with a WARNING containing the contact ID. Progress logs include the number of skipped contacts. Version `1` is saved
when processing finishes, even if some contacts were skipped or the database is empty. Database read/write failures
stop the migration without advancing the version.
Skipped contacts are not automatically retried once version `1` is saved.

This backfill runs in the background. FN/email sorting and cursor pagination can return incomplete or duplicate
results while contacts are still being migrated.

## Version 3 — Backfill the recurring marker of calendar objects

Upgrades database version `2` to `3`. Existing `calendarobjects` of type `VEVENT` stored without a `recurring` field
get the value computed from their `calendardata`, matching event creation and update: `false` for a single VEVENT
without `RRULE` nor `RDATE`, `true` otherwise. Their `firstoccurence` / `lastoccurence` are recomputed at the same
time: single events skip the vobject post-filter and rely on them only, so bounds wrongly stored by an older backend
(e.g. custom `VTIMEZONE` events) would otherwise make time-range queries return them outside the requested range.

Until an object is migrated it is treated as recurring: time-range queries stay correct, they only keep running the
vobject post-filter on it. Each update checks that the payload is unchanged and the field still missing, so
concurrent DAV writes keep their value. Objects with invalid data are skipped with a WARNING containing their ID.

## Version 4 — Date the change logs

Upgrades database version `3` to `4`. The `calendarchanges` and `addressbookchanges` rows written before they carried
a `date` get one, taken from the creation time of their `_id`, so that `scripts/prune-change-logs.php`
(`calendarChangesRetentionInDays` and `addressBookChangesRetentionInDays`, see [MONGO.md](MONGO.md#calendarchanges))
also prunes them. Without it they would be kept forever. Rows older than the retention are deleted by the next pruning
run; clients holding a sync token below the pruned one get a `403` `DAV:valid-sync-token` and resync from scratch.

Each collection is updated by a single server-side `updateMany`, only on rows without a `date`.

## Version 5 — Recompute the classification of private overrides again

Upgrades database version `4` to `5`. It runs the repair of version `2` again: objects holding a component whose
`CLASS` is `PRIVATE` or `CONFIDENTIAL` (a private override of a public series), but not stored as private, get their
`classification` recomputed from their `calendardata`, so that they are sanitised for sharees, delegates, public
readers and subscribers. Older versions stored the `CLASS` of the first component only. Version `2` runs on the first
upgraded replica while the replicas still on the older code keep writing such objects; this rerun repairs the ones
they wrote after it. Each update checks that the payload is unchanged. Objects with invalid data are skipped with a
WARNING containing their ID.

When upgrading from a version without version `2`, a single startup runs versions `2` to `5` back to back, during
the rollout: set `SABRE_MIGRATE_ON_STARTUP=false` until every replica runs the new code, then run
`php scripts/migrate-database.php`. The same holds for any migration that repairs a field the access checks rely on.

## Version 6 — Index the time-ranges by the end of the events

Upgrades database version `5` to `6`. Builds the `{ calendarid, componenttype, lastoccurence, firstoccurence }` index
of `calendarobjects` (see [MONGO.md](MONGO.md#calendarobjects)), so that a time-range query scans from the start of
the range rather than every past event of the calendar. The former `{ …, firstoccurence, lastoccurence }` index is
kept: it still serves the ranges given an end only, and the older replicas of a rolling upgrade keep creating it.

It is built by this migration, in the background, and not by the index creation of `scripts/start.sh`: on a large
collection the build can last longer than the socket timeout of the MongoDB driver (`socketTimeoutMS`, 5 minutes by
default), and a failing index creation keeps the container from starting. Until it is built, time-range queries use
the former index, as before. A socket timeout leaves the build running on the server: the migration then asks again,
which waits for that same build, up to 288 times (a day at the default timeout).

To build it ahead of the upgrade, at a quiet time, run in `mongosh` on the Sabre database:

```js
db.calendarobjects.createIndex({ calendarid: 1, componenttype: 1, lastoccurence: 1, firstoccurence: 1 })
```

The migration then finds it built and only saves version `6`.
