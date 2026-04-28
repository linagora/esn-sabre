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
**Indexes:** `{ principaluri, uri }` (unique)

---

### `calendarobjects`
Stores the individual calendar events/tasks (raw iCalendar data + parsed metadata).

Key fields: `calendarid`, `uri`, `uid`, `calendardata`, `componenttype`, `firstoccurence`, `lastoccurence`.

**Class:** `CalDAV\Backend\DAO\CalendarObjectDAO`  
**Indexes:**
- `{ calendarid }`
- `{ calendarid, uri }`
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
**Indexes:** none beyond `_id`

---

### `cards`
Individual vCard objects.

Key fields: `addressbookid`, `uri`, `carddata`.

**Class:** `CardDAV\Backend\Mongo`  
**Indexes:** `{ addressbookid }`, `{ addressbookid, uri }`

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
**Indexes:** none declared

---

## Principal collections (read-only from Sabre)

These collections are owned by the ESN application and only read by Sabre.

| Collection  | Content | Class |
|-------------|---------|-------|
| `users`     | User accounts; fields `preferredEmail`, `emails`, `domains` | `DAVACL\PrincipalBackend\Mongo` |
| `resources` | Room/equipment resources; field `administrators` | `DAVACL\PrincipalBackend\Mongo` |

## Index creation

CalDAV indexes are created at startup by `CalDAV\Backend\Mongo::ensureIndex()`, guarded by the `SHOULD_CREATE_INDEX` env var (defaults to enabled).  
CardDAV indexes follow the same guard in `CardDAV\Backend\Mongo::ensureIndex()`.
