# Team Calendars

A *team calendar* is a shared calendar owned by a dedicated principal rather than
by a user. It lets a group of members read and write a common agenda, with
per-member sharing rights and iTIP scheduling that acts on behalf of the member
who makes the change instead of the calendar owner.

Team calendars are modelled as a first-class principal type. For the wider
principal model see [PRINCIPALS.md](PRINCIPALS.md).

## Data model

Team calendars are backed by the `team_calendars` MongoDB collection in the
side-service DB:

| Field | Meaning |
|-------|---------|
| `_id` | `ObjectId`; also the principal id and the default calendar URI |
| `displayName` (legacy `name`) | human-readable name |
| `domainId` | owning domain `ObjectId` |
| `domainName` | owning domain name (used to build the address) |
| `emailAddress` | optional explicit address; otherwise synthesized |

### URIs

- **Principal:** `principals/team-calendars/{teamCalendarId}`
- **Calendar home:** `calendars/{teamCalendarId}`
- **Default calendar:** `calendars/{teamCalendarId}/{teamCalendarId}` — the
  default calendar URI is equal to the team calendar id.
- **Address:** `emailAddress` if set, else `{teamCalendarId}@{domainName}`,
  advertised as `calendar-user-address-set = mailto:{address}`.

The principal collection is mounted in `esn.php` as
`ESN\CalDAV\Principal\TeamCalendarCollection` under
`principals/team-calendars`, and registered in the `principalCollectionSet`.

## Principal

- **Collection:** `ESN\CalDAV\Principal\TeamCalendarCollection`
  (`getChildForPrincipal` returns a `PrincipalTeamCalendar`)
- **Node:** `ESN\CalDAV\Principal\PrincipalTeamCalendar` (extends
  `Sabre\DAVACL\Principal`)
- **Backend:** `teamCalendarToPrincipal` in
  `lib/DAVACL/PrincipalBackend/Mongo.php`

**Properties:**

- `{DAV:}displayname` = `displayName ?? name`
- `{http://sabredav.org/ns}email-address` = `emailAddress` or `{_id}@{domainName}`
- `{urn:ietf:params:xml:ns:caldav}calendar-user-address-set` = `mailto:{email}`
  (synthesized by `PrincipalTeamCalendar::getProperties`)

**ACL** (`PrincipalTeamCalendar::getACL`): the parent ACL plus a protected
`{DAV:}read` granted to `{DAV:}authenticated`, so any authenticated principal in
the tenant can read the team-calendar principal.

## Calendar provisioning

The default calendar is created lazily. `CalendarRoot::getChildren` queries
`team_calendars` by `domainId` and builds a `CalendarHome` per team calendar
(owner `principals/team-calendars/{id}`). On first access,
`ESN\CalDAV\Backend\Esn::createDefaultCalendar` detects a team-calendar
principal (`Utils::isTeamCalendarFromPrincipal`), sets `{DAV:}displayname` from
the principal, and creates the default calendar whose URI equals the team
calendar id.

## Domain isolation

Team calendars are strictly scoped to their domain. Cross-domain access is
rejected with `Sabre\DAV\Exception\Forbidden` at several layers:

- `CalendarRoot::getChild` throws `Cross-domain team calendar access is not
  allowed` when `domainId` does not match the authenticated domain.
- `assertTeamCalendarBelongsToDomain` in the Mongo backend enforces
  `domainId == authDomainId`.
- Prefix and search queries force the `domainId` filter. Search by
  `{DAV:}displayname` is intentionally ignored for team calendars — only email
  search is supported.

## Sharing

Sharing is handled by `ESN\DAV\Sharing\Plugin` (extends
`Sabre\DAV\Sharing\Plugin`), which adds two access levels on top of Sabre's:

| Constant | Value | RSE right |
|----------|-------|-----------|
| `ACCESS_SHAREDOWNER` | 1 | `dav:shareer` |
| `ACCESS_READ` | 2 | `dav:read` |
| `ACCESS_READWRITE` | 3 | `dav:read-write` |
| `ACCESS_ADMINISTRATION` | 5 | `dav:administration` |
| `ACCESS_FREEBUSY` | 6 | `dav:freebusy` |

`accessToRightRse` / `rightRseToAccess` map between these access levels and the
`dav:*` right strings used on the wire.

### Sharing via a technical token

A team calendar has no human owner able to satisfy its owner ACL, so sharing is
typically performed by a service using a technical token. `shareResource`
detects this case with `isTechnicalTeamCalendarSharingTarget`:

1. The caller must authenticate as `TenantType::Technical`.
2. The target node must be owned by a `principals/team-calendars/` principal.
3. The team calendar's `domainId` must match the token's domain — otherwise
   `Cross-domain team calendar access is not allowed`.

When these hold, sharing bypasses the owner ACL check via
`shareNodeWithoutAclCheck`, which still resolves each sharee principal in the
token domain (`Delegated principal must resolve in the token domain` when a
sharee cannot be resolved) before calling `updateInvites`. All other sharing
goes through the standard `parent::shareResource`. Cross-domain delegation to a
sharee outside the token domain is rejected with `Cross-domain delegation is not
allowed`.

## Scheduling and iTIP

Scheduling for team calendars behaves differently from user calendars because
the acting identity is the connected **member**, not the team-calendar owner.
The logic lives in `ESN\CalDAV\Schedule\Plugin` (and its AMQP variant
`AMQPSchedulePlugin`).

### Acting as the member

`isTeamCalendarPath` inspects the calendar node owner
(`Utils::isTeamCalendarFromPrincipal`). When a change targets a team calendar:

- `fetchSchedulingAddresses` returns the **current principal's** addresses
  (the connected member) rather than the calendar owner's.
- If the event has a single ORGANIZER, that organizer address is used as the
  scheduling address (`extractSingleOrganizerAddress`).
- `shouldValidateAttendeeSchedulingObjectChange` relaxes the RFC 6638
  attendee-change validation for members who can write the object.

### Organizer must be a write-enabled member

`ESN\CalDAV\OrganizerValidationPlugin` enforces that on a team calendar the
ORGANIZER is a write-enabled sharee (`isWriteEnabledCalendarSharee`, i.e. access
`ACCESS_READWRITE` or `ACCESS_ADMINISTRATION`). Otherwise it throws
`Forbidden('The ORGANIZER must be a write-enabled team calendar member.')`.

### Routing iTIP replies — `X-OPENPAAS-TEAM-CALENDAR-ID`

Because a team-calendar event is not owned by the replying attendee, a custom
iCalendar property carries the routing information:

```
X-OPENPAAS-TEAM-CALENDAR-ID:{teamCalendarId}
```

- `setTeamCalendarIdProperty` stamps the id onto every `VEVENT` of a
  team-calendar object.
- `extractTeamCalendarIdProperty` reads it back.
- `resolveTeamCalendarIdForReplyMessage` uses it, together with the iTIP
  message `uid` and `recipient`, to locate the writable team-calendar object
  (`findTeamCalendarObjectPath` / `loadWritableTeamCalendarObject`) so an
  incoming reply is applied to the correct team calendar rather than the
  attendee's own calendar.

## Authentication and impersonation

A team-calendar address can authenticate/impersonate through the DAV auth
backend. In `lib/DAV/Auth/Backend/Esn.php`, `doImpersonation` tries user then
resource lookups, and finally `getAuthTenantByTeamCalendarEmail`, which parses
the local part of `{id}@{domain}` as an `ObjectId` and validates the domain.
The resulting tenant is `TenantType::TeamCalendars` (enum value `4`), mapped by
`AuthTenant` to the `principals/team-calendars/` prefix.

## Reference files

- `lib/CalDAV/Principal/TeamCalendarCollection.php`, `lib/CalDAV/Principal/PrincipalTeamCalendar.php`
- `lib/CalDAV/CalendarRoot.php` — calendar homes and domain isolation
- `lib/CalDAV/Backend/Esn.php` — default calendar provisioning
- `lib/DAVACL/PrincipalBackend/Mongo.php` — `teamCalendarToPrincipal`, `getAuthTenantByTeamCalendarEmail`, domain-scoped queries
- `lib/DAV/Sharing/Plugin.php` — access levels and technical-token sharing
- `lib/CalDAV/OrganizerValidationPlugin.php` — write-enabled organizer enforcement
- `lib/CalDAV/Schedule/Plugin.php`, `lib/CalDAV/Schedule/AMQPSchedulePlugin.php` — member-scoped scheduling and `X-OPENPAAS-TEAM-CALENDAR-ID` routing
- `lib/DAV/Auth/Backend/Esn.php`, `lib/Utils/TenantType.php`, `lib/Utils/AuthTenant.php` — authentication and impersonation

## Tests

Expected behaviour is specified by the test suite, notably:

- `tests/CalDAV/Principal/TeamCalendarCollectionTest.php`
- `tests/CalDAV/CalendarRootTest.php` (provisioning and cross-domain rejection)
- `tests/CalDAV/Backend/EsnTest.php` (default calendar creation)
- `tests/DAV/Sharing/PluginTest.php` (technical-token sharing and its guards)
- `tests/CalDAV/Schedule/SchedulePluginTest.php` (iTIP reply routing)
- `tests/CalDAV/OrganizerValidationPluginTest.php`
- `tests/DAVACL/PrincipalBackend/PrivatePrincipalBackendTest.php`, `tests/DAV/Auth/Backend/EsnTest.php`
