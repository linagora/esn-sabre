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

Key fields: `calendarid`, `uri`, `uid`, `calendardata`, `componenttype`, `firstoccurence`, `lastoccurence`.

**Class:** `CalDAV\Backend\DAO\CalendarObjectDAO`  
**Indexes:**
- `{ calendarid, uri }` — also serves `{ calendarid }` alone
- `{ calendarid, componenttype, firstoccurence, lastoccurence }` — time-range queries
- `{ uid }`

---

### `calendarchanges`
Audit log of creates/updates/deletes on calendar objects, used for WebDAV sync (RFC 6578).

Key fields: `calendarid`, `uri`, `synctoken`, `operation`.

**Class:** `CalDAV\Backend\DAO\CalendarChangeDAO`  
**Indexes:** `{ calendarid, synctoken }`

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
**Indexes:** TTL index on `{ dateCreated }` if `schedulingObjectTTLInDays` > 0 (configurable at construction)

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

Key fields: `addressbookid`, `uri`, `carddata`.

**Class:** `CardDAV\Backend\Mongo`  
**Indexes:** `{ addressbookid, uri }` — also serves `{ addressbookid }` alone

---

### `addressbookchanges`
Audit log for CardDAV sync (same pattern as `calendarchanges`).

Key fields: `addressbookid`, `synctoken`.

**Class:** `CardDAV\Backend\Mongo`  
**Indexes:** `{ addressbookid, synctoken }`

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

## Index creation

Indexes are created once when the container starts: `scripts/start.sh` runs `scripts/create-indexes.php`, which calls
`CalDAV\Backend\Mongo::ensureIndexes()` and `CardDAV\Backend\Mongo::ensureIndexes()`. Creating an index that already
exists is a no-op, so this is safe on every restart. Index creation never runs on HTTP requests. Before this change it
ran on every request unless `SHOULD_CREATE_INDEX=false` was set; that setting no longer exists.

If index creation fails at startup, sabre still starts and logs a warning. You can rerun
`php scripts/create-indexes.php [config.json]` or use the `mongosh` script below, which also drops the indexes that
became redundant.

### One-shot script for existing deployments

Run against the sabre database (`mongosh "<sabre connection string>" create-indexes.js`). Index builds do not block
reads or writes on MongoDB 4.2+, but run it outside peak hours on large collections.

```js
// Indexes added (idempotent)
db.calendarinstances.createIndex({ calendarid: 1, share_href: 1 });
db.sharedaddressbooks.createIndex({ addressbookid: 1, share_href: 1 });
db.sharedaddressbooks.createIndex({ principaluri: 1 });
db.addressbooksubscriptions.createIndex({ principaluri: 1 });
db.addressbooksubscriptions.createIndex({ source: 1 });

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
```
