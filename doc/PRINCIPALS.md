# DAV Principals

A *principal* is a DAV identity that can own resources and appear in ACLs and
scheduling. In `esn-sabre` all principals live under the `principals/`
collection and are backed either by a MongoDB collection (in the side-service
DB) or, for the technical user, by a virtual admin mount.

This document describes every principal type the system exposes: its URI shape,
where it is wired, the data that backs it, the properties it advertises, and its
ACL, email and scheduling behaviour.

## Overview

| Type | URI pattern | Mongo collection | Node class | Group? | Has email |
|------|-------------|------------------|------------|--------|-----------|
| Users | `principals/users/{id}` | `users` | `ESN\CalDAV\Principal\PrincipalUser` | member of domains | yes (real mailbox) |
| Resources | `principals/resources/{id}` | `resources` | `ESN\CalDAV\Principal\PrincipalResource` | no | yes (synthetic) |
| Team calendars | `principals/team-calendars/{id}` | `team_calendars` | `ESN\CalDAV\Principal\PrincipalTeamCalendar` | no | yes (synthetic or explicit) |
| Domains | `principals/domains/{id}` | `domains` | Sabre default `Sabre\DAVACL\Principal` | yes (group of users) | no |
| Technical user | `principals/technicalUser` | — (virtual) | — (collection mount only) | no | no |

`{id}` is always a MongoDB `ObjectId`.

The four database-backed types are enumerated by the `collectionMap` in
`lib/DAVACL/PrincipalBackend/Mongo.php`:

```php
'users'          => $this->db->users,
'resources'      => $this->db->resources,
'team-calendars' => $this->db->team_calendars,
'domains'        => $this->db->domains,
```

Any principal path whose type is not in this map is rejected by
`parsePrincipalPath` / `parsePrincipalPrefix`, so `technicalUser` never resolves
to a database principal.

### Wiring

Principal collections are mounted in `esn.php` under a single
`Sabre\DAV\SimpleCollection('principals', [...])`:

```php
define('PRINCIPALS_USERS',          'principals/users');
define('PRINCIPALS_RESOURCES',      'principals/resources');
define('PRINCIPALS_TEAM_CALENDARS', 'principals/team-calendars');
define('PRINCIPALS_TECHNICAL_USER', 'principals/technicalUser');
define('PRINCIPALS_DOMAINS',        'principals/domains');
```

Only four of them are registered in the `principalCollectionSet` used for
RFC 3744 principal search — `users`, `resources`, `team-calendars`, `domains`.
`technicalUser` is deliberately excluded from the search set and instead
registered in `adminPrincipals[]`, which grants it full ACL access everywhere.

## Users

- **URI:** `principals/users/{id}`
- **Collection:** `ESN\CalDAV\Principal\Collection` (extends `Sabre\CalDAV\Principal\Collection`)
- **Node:** `ESN\CalDAV\Principal\PrincipalUser` (extends `Sabre\CalDAV\Principal\User`)
- **Mongo:** `users` — fields `_id`, `firstname`, `lastname`, `domains[]`
  (`{domain_id, ...}`), `accounts[].emails[]`, `preferredEmail`, `emails`,
  `password`.

Users are the primary authenticating identity. The user principal is a
collection: it exposes the standard `calendar-proxy-read` /
`calendar-proxy-write` child principals inherited from Sabre.

**Properties** (`userToPrincipal`):

- `{DAV:}displayname` = `firstname + " " + lastname`
- `{http://sabredav.org/ns}email-address` = `Utils::firstEmailAddress($obj)`
- `adminForDomains` — the domain ids the user administers (when applicable)

**ACL** (`PrincipalUser::getACL`): the parent ACL (owner reads and writes
itself) plus a protected `{DAV:}read` granted to `{DAV:}authenticated`. Any
authenticated principal in the tenant can therefore read a user principal; write
stays self-only.

**Domain isolation:** `assertUserBelongsToDomain` rejects with `Forbidden` any
user that is not part of the authenticated tenant's domain.
`getPrincipalsByPrefix` filters on `domains.domain_id == authDomainId`.

**Group membership:** `getGroupMembership` returns
`principals/domains/{domain_id}` for each of the user's domains — users are
members of their domains.

**Auth:** resolved by email through `getAuthTenantByEmail` (tries
`accounts.emails`, then `preferredEmail`, then `emails`). When auto-provisioning
is enabled (`AUTO_PROVISION`, default on), a user missing from Mongo but present
in LDAP is created on the fly by `provisionUser`.

## Resources

- **URI:** `principals/resources/{id}`
- **Collection:** `ESN\CalDAV\Principal\ResourceCollection`
- **Node:** `ESN\CalDAV\Principal\PrincipalResource` (extends `Sabre\DAVACL\Principal`)
- **Mongo:** `resources` — fields `_id`, `name`, `domain` (an `ObjectId`,
  enriched into the full domain document), optional `members[]`,
  `administrators`, `email`, `title`.

Resources model bookable entities (rooms, equipment, …).

**Properties** (`resourceToPrincipal`):

- `{DAV:}displayname` = `name`
- `{http://sabredav.org/ns}email-address` = `{_id}@{domain.name}`
- `{urn:ietf:params:xml:ns:caldav}calendar-user-address-set` = `mailto:{email}`
  (synthesized explicitly by `PrincipalResource::getProperties`)

**ACL** (`PrincipalResource::getACL`): parent ACL plus a protected `{DAV:}read`
for `{DAV:}authenticated`.

**Domain isolation:** `CalendarRoot::getChild` enforces
`resource.domain === authDomainId` and throws `Forbidden` otherwise. Note that
resources are *not* listed by prefix — `principalsByPrefixQuery` returns an empty
query for resources — while `CalendarRoot::getChildren` lists them without a
domain filter.

**Email:** synthetic `{resourceId}@{domainName}`. `getAuthTenantByResourceEmail`
parses the local part as an `ObjectId`, looks it up in `resources`, and
validates the domain name.

**Scheduling:** resource calendars carry a public read privilege
(`RESOURCE_CALENDAR_PUBLIC_PRIVILEGE`), and booking emits realtime events
(`resource:calendar:event:created` / `:accepted` / `:declined`). Impersonation
uses `TenantType::Resources`.

## Team calendars

- **URI:** `principals/team-calendars/{id}`
- **Collection:** `ESN\CalDAV\Principal\TeamCalendarCollection`
- **Node:** `ESN\CalDAV\Principal\PrincipalTeamCalendar` (extends `Sabre\DAVACL\Principal`)
- **Mongo:** `team_calendars` — fields `_id`, `displayName` (legacy `name`),
  `domainId` (`ObjectId`), `domainName` (string), optional `emailAddress`.

A team calendar is a shared calendar owned by a dedicated principal rather than
by a user, with membership-based sharing and iTIP scheduling routed through the
`X-OPENPAAS-TEAM-CALENDAR-ID` property.

**Properties** (`teamCalendarToPrincipal`):

- `{DAV:}displayname` = `displayName ?? name`
- `{http://sabredav.org/ns}email-address` = `emailAddress`, else `{_id}@{domainName}`
- `{urn:ietf:params:xml:ns:caldav}calendar-user-address-set` = `mailto:{email}`
  (synthesized by `PrincipalTeamCalendar::getProperties`)

**ACL** (`PrincipalTeamCalendar::getACL`): parent ACL plus a protected
`{DAV:}read` for `{DAV:}authenticated`.

**Domain isolation:** `assertTeamCalendarBelongsToDomain` requires
`domainId == authDomainId`; both the prefix query and the search queries force
the domain filter. Search by `{DAV:}displayname` is intentionally ignored for
team calendars — only email search is supported.

**Email:** explicit `emailAddress` when present, otherwise synthetic
`{teamCalendarId}@{domainName}`. `getAuthTenantByTeamCalendarEmail` parses the
local part as an `ObjectId` and validates the domain. Impersonation uses
`TenantType::TeamCalendars`.

## Domains

- **URI:** `principals/domains/{id}`
- **Collection:** plain `Sabre\CalDAV\Principal\Collection` (no ESN subclass)
- **Node:** Sabre default `Sabre\DAVACL\Principal`
- **Mongo:** `domains` — fields `_id`, `name`, `administrators[].user_id`.
  Membership is derived from the `users` collection.

A domain is a **group principal**. It has no email and no calendar user address.

**Properties** (`domainToPrincipal`):

- `{DAV:}displayname` = `name`
- `administrators` = `principals/users/{user_id}` list
- `members` = the full member set

**Group semantics:** `getGroupMemberSet` for a domain returns every
`principals/users/{id}` whose `users.domains.domain_id == domainId`;
`getGroupMembership` for a domain is empty. Domains are the group backbone of the
principal tree — a user's `group-membership` points at its domains, and a
domain's `group-member-set` enumerates its users.

**Isolation:** `assertSameDomain` requires the requested domain id to equal the
authenticated domain id. Domains are searchable only by `{DAV:}displayname`.

There is no `TenantType` for domains: a domain is never an authenticating
identity, only a group.

## Technical user

- **URI:** `principals/technicalUser` (a collection mount, not `.../{id}`)
- **Mount:** plain `Sabre\CalDAV\Principal\Collection`, registered in
  `adminPrincipals[]`
- **Mongo:** none — it is virtual and absent from `collectionMap` and
  `principalCollectionSet`.

The technical user is the identity used for service-to-service calls (see
`TECHNICAL_TOKEN.md`). It is an ACL **admin principal**: membership in
`adminPrincipals` grants every privilege everywhere. In the privacy layer,
`isTechnicalPrincipal` bypasses all filtering so a technical caller sees every
principal, and at auth time technical users skip address-book and calendar
preloading.

Authentication uses `TenantType::Technical` via the `TwakeCalendarToken` header
and the `technicalToken/introspect` endpoint. At runtime the technical tenant's
principal prefix maps to `principals/users/` (see the mapping below), but the
login principal reported for ACL checks is the string `principals/technicalUser`.

## Cross-cutting mechanisms

### Tenant type and prefix mapping

`lib/Utils/TenantType.php` is a backed int enum:

```php
User          = 1
Technical     = 2
Resources     = 3
TeamCalendars = 4
```

`lib/Utils/AuthTenant.php` maps each tenant type to a principal URI prefix:

| TenantType | Prefix |
|------------|--------|
| `User` | `principals/users/` |
| `Technical` | `principals/users/` (same as User at runtime) |
| `Resources` | `principals/resources/` |
| `TeamCalendars` | `principals/team-calendars/` |

There is no tenant type for domains.

### Impersonation order

`doImpersonation` in `lib/DAV/Auth/Backend/Esn.php` tries each resolver in order
until one returns a tenant:

1. `getAuthTenantByEmail` — users
2. `getAuthTenantByResourceEmail` — resources
3. `getAuthTenantByTeamCalendarEmail` — team calendars
4. `autoProvisionUser` — create the user if an LDAP entry exists, else fail with
   `User not found`

Impersonation is gated by `SABRE_IMPERSONATION_ENABLED` and the admin credential
scheme `{SABRE_ADMIN_LOGIN}&{targetEmail}`.

### Privacy layer

`PrivatePrincipalBackend` wraps the Mongo backend unless `PRINCIPAL_PRIVACY` is
explicitly disabled. For non-technical callers it restricts
`getPrincipalsByPrefix` / `searchPrincipals` to the caller's own principal and,
for `principals/domains`, the caller's domain memberships. The technical user
bypasses the whole layer.

### `calendar-user-type`

There is **no per-type assignment** of `calendar-user-type`. None of the ESN
principal classes or the Mongo backend set it. It is supplied entirely by
Sabre's scheduling plugin, which hardcodes `INDIVIDUAL`:

```php
// vendor/sabre/dav/lib/CalDAV/Schedule/Plugin.php
$propFind->handle('{urn:ietf:params:xml:ns:caldav}calendar-user-type', function () {
    return 'INDIVIDUAL';
});
```

Consequently *every* principal — users, resources, team calendars, domains —
reports `calendar-user-type = INDIVIDUAL`. The values `RESOURCE`, `GROUP` and
`ROOM` are never assigned anywhere in the codebase.

### `group-member-set` / `group-membership`

These are surfaced as JSON on a principal `PROPFIND` by `ESN\DAVACL\Plugin`.
Only domains participate in group relationships: a domain's member set is its
users, and a user's membership is its domains.

## Reference files

- `esn.php` — principal collection mounts, `principalCollectionSet`, `adminPrincipals`
- `lib/DAVACL/PrincipalBackend/Mongo.php` — `collectionMap`, `*ToPrincipal`, prefix/search queries, group sets
- `lib/DAVACL/PrincipalBackend/PrivatePrincipalBackend.php` — privacy layer
- `lib/DAVACL/Plugin.php` — group-member-set / group-membership PROPFIND output
- `lib/CalDAV/Principal/` — `Collection`, `ResourceCollection`, `TeamCalendarCollection`, `PrincipalUser`, `PrincipalResource`, `PrincipalTeamCalendar`
- `lib/CalDAV/CalendarRoot.php` — calendar homes and domain isolation
- `lib/Utils/TenantType.php`, `lib/Utils/AuthTenant.php`, `lib/Utils/Principal.php`
- `lib/DAV/Auth/Backend/Esn.php` — authentication and impersonation
