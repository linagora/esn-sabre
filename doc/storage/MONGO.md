# MongoDB

## CalDAV collections

### `calendars`
Stores calendar metadata (name, description, color, timezone, synctoken, etc.).  
One document per calendar.

**Class:** `CalDAV\Backend\DAO\CalendarDAO`  
**Indexes:** `_id` (default only)

---

### `calendarinstances`
Links a calendar to a principal (owner or sharee). Each share creates a separate instance document.

Key fields: `calendarid`, `principaluri`, `uri`, `access`, `share_href`, `share_invitestatus`, `public_right`.

**Class:** `CalDAV\Backend\DAO\CalendarInstanceDAO`  
**Indexes:**
- `{ principaluri, uri }` (unique)
- `{ principaluri, hidden }`
- `{ principaluri, calendarid }`
- `{ calendarid, share_href }` — sharees and public right of a calendar, also serves `{ calendarid }` alone

---

### `calendarobjects`
Stores the individual calendar events/tasks (raw iCalendar data + parsed metadata).

Key fields: `calendarid`, `uri`, `uid`, `calendardata`, `componenttype`, `firstoccurence`, `lastoccurence`, `recurring`.

`recurring` is `false` when the object holds a single VEVENT without `RRULE` nor `RDATE`: its `firstoccurence` /
`lastoccurence` are then the exact bounds of the event, and a VEVENT time-range query skips the vobject post-filter
for it. A missing value is treated as `true`.

**Class:** `CalDAV\Backend\DAO\CalendarObjectDAO`  
**Indexes:**
- `{ calendarid, uri }` — also serves `{ calendarid }` alone
- `{ calendarid, componenttype, firstoccurence, lastoccurence }` and `{ calendarid, componenttype, lastoccurence,
  firstoccurence }` — time-range queries. MongoDB bounds the scan by the first ranged field only: with
  `lastoccurence` first, a range with a start scans from it, instead of every past event of the calendar; with
  `firstoccurence` first, a range given an end only (a CalDAV `calendar-query` or `free-busy-query` may omit the start;
  the JSON REPORTs always send both) scans up to it. The query planner picks the one examining fewer keys. The second
  one is built by [migration 6](MIGRATIONS.md#version-6--index-the-time-ranges-by-the-end-of-the-events), not at startup.
- `{ uid }`

---

### `calendarchanges`
Audit log of creates/updates/deletes on calendar objects, used for WebDAV sync (RFC 6578).

Key fields: `calendarid`, `uri`, `synctoken`, `operation`, `date`.

**Class:** `CalDAV\Backend\DAO\CalendarChangeDAO`  
**Indexes:** `{ calendarid, synctoken }`, `{ date }`

The log keeps the changes of the last `calendarChangesRetentionInDays` days only (`database` section of `config.json`,
default `90`, `0` disables pruning), so that it grows with the data kept rather than with every write ever made.
`scripts/prune-change-logs.php` deletes the older changes. `scripts/start.sh` runs it in the background at startup,
unless `SABRE_PRUNE_CHANGES_ON_STARTUP=false`; it is safe to also run it periodically (cron, Kubernetes CronJob), even
under DAV traffic.

Changes are pruned by sync token, not by date: a writer reads the calendar token, logs its change, then increments, so
a stalled writer can date its change after a higher token. For each calendar having changes older than the retention,
the script computes `X = 1 + max(synctoken)` of those changes, raises the calendar's `prunedsynctoken` watermark to `X`
(`$max`, never lowered) and only then deletes the changes whose token is below `X`.

A `sync-collection` REPORT reads the changes first, then the watermark: a sync token below `prunedsynctoken` answers
`403` `DAV:valid-sync-token` (RFC 6578 section 3.2) and the client resyncs from scratch. As the watermark is raised
before any deletion, a token not below it proves that no change the client needed was deleted.

---

### `calendarsubscriptions`
External calendar subscriptions (iCal URL) attached to a principal.

Key fields: `principaluri`, `source` (URL).

**Class:** `CalDAV\Backend\DAO\CalendarSubscriptionDAO`  
**Indexes:** `{ principaluri }`, `{ source }`

---

### `schedulingobjects`
iTIP scheduling inbox objects (meeting invitations in transit).

Key fields: `principaluri`, `uri`, `dateCreated`.

**Class:** `CalDAV\Backend\DAO\SchedulingObjectDAO`  
**Indexes:** `{ principaluri, uri }`, TTL index on `{ dateCreated }` if `schedulingObjectTTLInDays` > 0 (`database.schedulingObjectTTLInDays` in `config.json`, default `7` days)

Nothing in Twake Calendar reads the scheduling inbox: the iTIP delivery already applied the change to the attendee
calendar, the items are only kept for the RFC 6638 clients (Thunderbird, DAVx5...) that process it, and those sync far
more often than weekly. When the configured TTL differs from the one of the existing index, startup updates the index in
place with `collMod`, and MongoDB then removes the items older than the new TTL.

---

## CardDAV collections

### `addressbooks`
Address book metadata per principal.

Key fields: `principaluri`, `uri`, `synctoken`.

**Class:** `CardDAV\Backend\Mongo`  
**Indexes:** `{ principaluri, uri }` (unique)

---

### `sharedaddressbooks`
Sharee-side view of a shared address book.

**Class:** `CardDAV\Backend\Mongo`  
**Indexes:** `{ addressbookid, share_href }` (also serves `{ addressbookid }` alone), `{ principaluri }`

---

### `cards`
Individual vCard objects.

Key fields: `addressbookid`, `uri`, `carddata`, `fn` (first letter of the full name), `fn_sort` (full name as is,
trimmed: accents and case are ignored by the collation of its index rather than stripped).

Email sort field: `email_sort` (trimmed preferred email or `""`).

**Class:** `CardDAV\Backend\Mongo`  
**Indexes:** `{ addressbookid, uri }` — also serves `{ addressbookid }` alone;
`{ addressbookid, fn, _id }` — supports paginated `sort=fn` listings with a stable tie-breaker;
`{ addressbookid, fn_sort, _id }` named `addressbookid_1_fn_sort_1__id_1_en_strength1`, with the collation
`{ locale: "en", strength: 1 }` (`CardDAV\Backend\Mongo::CONTACT_SORT_COLLATION`) — supports contact listings
aggregated over the address books of a user (`GET /contacts/{userId}.json`), merging one index scan per address book,
and of a single address book (`GET /addressbooks/{addressbookHomeId}/{addressbookId}.json?sort=fn`).
It only serves queries passing the very same collation: changing the language of the sort means changing the
collation and recreating this index under another name, stored values staying as they are.

`{ addressbookid, email_sort, _id }` uses the same collation for `sort=email` in both directions.
Empty emails sort before non-empty emails in ascending order, and after them in descending order.

---

### `addressbookchanges`
Audit log for CardDAV sync (same pattern as `calendarchanges`).

Key fields: `addressbookid`, `synctoken`, `date`.

**Class:** `CardDAV\Backend\Mongo`  
**Indexes:** `{ addressbookid, synctoken }`, `{ date }`

Pruned like `calendarchanges`, after `addressBookChangesRetentionInDays` days (default `90`), with the watermark in the
`prunedsynctoken` field of the address book.

---

### `addressbooksubscriptions`
External address book subscriptions attached to a principal.

Key fields: `principaluri`, `source`.

**Class:** `CardDAV\Backend\Mongo`  
**Indexes:** `{ principaluri }`, `{ source }`

---

## Principal collections (read-only from Sabre)

These collections are owned by the ESN application and only read by Sabre.

| Collection  | Content | Class |
|-------------|---------|-------|
| `users`     | User accounts; fields `preferredEmail`, `emails`, `domains` | `DAVACL\PrincipalBackend\Mongo` |
| `resources` | Room/equipment resources; field `administrators` | `DAVACL\PrincipalBackend\Mongo` |

## Database migrations

After index creation, `scripts/start.sh` launches the migration runner in the background,
alongside DAV. `SABRE_MIGRATE_ON_STARTUP` defaults to `true`; setting it to `false` skips automatic migration
and logs an INFO message without stopping DAV. Like other runtime settings, it can be set in the `environment`
section of `config.json` or as an environment variable.

Migrations can always be run manually with `php scripts/migrate-database.php [config-path]`, even when
`SABRE_MIGRATE_ON_STARTUP=false`

Migrations run in order. The current version is stored in the Sabre database's `db_version` collection,
in the document with `_id: "schema"` and an integer `version`.
No record means version `0`; the version advances only after a migration finishes. Invalid or unsupported versions
and migration failures stop the runner without stopping DAV. A failed runner is not automatically restarted.

Migration logs are written to stdout at INFO, WARNING and ERROR levels, with versions, batch progress and elapsed time.
ERROR logs identify the failed migration and, when available, the contact ID. Fix the cause, then rerun the command
or restart the application to retry. Run only one migration process at a time; wait for the background runner to finish
before running a manual command.

See the [database migration history](MIGRATIONS.md) for the changes introduced by each version.

## Index creation

Indexes are created once when the container starts: `scripts/start.sh` runs `scripts/create-indexes.php`, which calls
`CalDAV\Backend\Mongo::ensureIndexes()` and `CardDAV\Backend\Mongo::ensureIndexes()`. Creating an index that already
exists is a no-op, so this is safe on every restart. Index creation never runs on HTTP requests. Before this change it
ran on every request unless `SHOULD_CREATE_INDEX=false` was set; that setting no longer exists.

Without these indexes every query is a collection scan, which saturates MongoDB under load. Index creation is therefore
retried at startup, so that a MongoDB not reachable yet does not leave sabre without indexes, and the container refuses
to start when it still fails:

 - `MONGO_INDEX_CREATION_ATTEMPTS`: number of attempts (default `30`)
 - `MONGO_INDEX_CREATION_RETRY_DELAY`: seconds to wait between two attempts (default `2`)
 - `MONGO_INDEX_CREATION_REQUIRED`: `true` (default) stops the container when every attempt failed; `false` starts
   sabre anyway and logs an `ERROR`

The script prints the name of the database it created the indexes on. You can rerun
`php scripts/create-indexes.php [config.json]` or use the `mongosh` script below, which also drops the indexes that
became redundant.

### One-shot script for existing deployments

Run against the sabre database (`mongosh "<sabre connection string>" create-indexes.js`). Index builds do not block
reads or writes on MongoDB 4.2+, but run it outside peak hours on large collections.

```js
// Indexes added (idempotent)
db.calendarinstances.createIndex({ calendarid: 1, share_href: 1 });
db.cards.createIndex({ addressbookid: 1, fn: 1, _id: 1 });
db.cards.createIndex({ addressbookid: 1, fn_sort: 1, _id: 1 },
  { name: "addressbookid_1_fn_sort_1__id_1_en_strength1", collation: { locale: "en", strength: 1 } });
db.cards.createIndex({ addressbookid: 1, email_sort: 1, _id: 1 },
  { name: "addressbookid_1_email_sort_1__id_1_en_strength1", collation: { locale: "en", strength: 1 } });
db.sharedaddressbooks.createIndex({ addressbookid: 1, share_href: 1 });
db.sharedaddressbooks.createIndex({ principaluri: 1 });
db.addressbooksubscriptions.createIndex({ principaluri: 1 });
db.addressbooksubscriptions.createIndex({ source: 1 });
db.schedulingobjects.createIndex({ principaluri: 1, uri: 1 });
db.calendarchanges.createIndex({ date: 1 });
db.addressbookchanges.createIndex({ date: 1 });

// Scheduling inbox TTL lowered from 56 to 7 days (createIndex would fail with IndexOptionsConflict)
db.runCommand({ collMod: "schedulingobjects", index: { keyPattern: { dateCreated: 1 }, expireAfterSeconds: 7 * 86400 } });

// Indexes made redundant by a compound index with the same prefix: they only slow writes down
for (const [coll, key] of [["calendarobjects", { calendarid: 1 }], ["cards", { addressbookid: 1 }]]) {
  if (db[coll].getIndexes().some(i => JSON.stringify(i.key) === JSON.stringify(key))) {
    db[coll].dropIndex(key);
  }
}
```

To check that a query uses an index, look for `IXSCAN` (not `COLLSCAN`) and `totalDocsExamined` close to `nReturned`:

```js
db.calendarinstances.find({ calendarid: ObjectId("...") }).explain("executionStats")
db.cards.find({ addressbookid: ObjectId("...") }).sort({ fn: 1, _id: 1 }).limit(50).explain("executionStats")
```
