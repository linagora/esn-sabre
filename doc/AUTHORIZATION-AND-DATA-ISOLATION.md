# Authorization and Data Isolation Review Guide

## Purpose

This document records the authorization and data-isolation boundaries and
review scenarios that must be considered when changing `esn-sabre`. It is
intended for developers, human reviewers, and AI-assisted reviews.

It is a living guide, not a claim that the service is free of vulnerabilities.
Update it when a new authentication mechanism, principal type, resource type,
protocol route, sharing mode, or security regression is introduced.

## Related documentation

This guide defines cross-cutting authorization invariants and review questions.
Detailed behavior is owned by the following documents in this repository:

- [Technical Token Authentication](TECHNICAL_TOKEN.md) for technical-token
  authentication and domain scope;
- [DAV Principals](PRINCIPALS.md) for principal types, properties, and
  discovery;
- [Team Calendars](TEAM-CALENDAR.md) for team-calendar sharing and scheduling;
- [JSON API](JSON-API.md) for JSON routes, request payloads, and responses.

When behavior changes, update this guide and the owning document in the same
change.

## Review model

Authorization must be reviewed as a matrix, not as a check on a single
endpoint:

| Dimension | Representative values |
|---|---|
| Actor | anonymous, owner, same-domain user, public subscriber, `dav:read`/`dav:read-write`/`dav:administration` delegate, resource administrator, team-calendar member, technical user |
| Resource | user principal, resource principal, team-calendar principal, calendar home, calendar, event, scheduling inbox/outbox, address book, contact |
| Action | discover, list, read, create, update, delete, move, copy, share, change ACL, schedule, export, sync |
| Interface | DAV XML, iCalendar, JSON API, free/busy, iTIP, technical-token endpoint, RabbitMQ output |
| Tenant relation | same tenant, foreign tenant, missing tenant context |
| State | private, public-read, public-write, delegated, delegation revoked, subscription removed, `PRIVATE`/`CONFIDENTIAL` event |

A change is not adequately reviewed by testing only its normal owner path. For
each affected operation, select the relevant values from every dimension and
cover both permitted and forbidden combinations.

### Core invariants

- Authentication failures must fail closed with no server-side side effect.
- Authorization must be enforced on every representation and protocol path to
  the same resource.
- A caller must not read, modify, enumerate, or infer another tenant's data
  unless an explicitly supported cross-tenant feature grants access.
- Public calendar access must not expose `PRIVATE` or `CONFIDENTIAL` event
  details to a non-owner.
- `dav:read` access must never imply write, delete, scheduling, or
  ACL-management access.
- Revoked access must stop working through canonical URLs, copied resources,
  cached discovery results, and scheduling endpoints.
- A denied response must not leak protected identifiers or content in its body,
  headers, properties, logs, or asynchronous messages.

## Authentication boundary

The DAV authentication backend currently supports the following mechanisms, in
this order:

1. `Authorization: Bearer <JWT>`: the token is verified with the configured
   RS256 public key and its `sub` email is resolved to a user tenant.
2. `TwakeCalendarToken: <token>`: the token is introspected by the calendar side
   service and produces a technical or user tenant.
3. HTTP Basic authentication: credentials are checked against LDAP. When
   enabled, the same mechanism also supports administrator impersonation using
   the configured administrator credential and an explicit target identity.

Successful authentication produces an `AuthTenant` containing an identity,
domain ID, and tenant type. This context is propagated on `auth:success` to the
principal and calendar backends. Authorization code must not run with a missing
or stale tenant context.

## Calendar authorization

### Ownership and direct paths

An owner may manage their own calendar and events. A user without an explicit
grant must not gain access by constructing the owner's canonical calendar or
event URL.

Review all applicable methods, not just `GET` and `PUT`:

- `PROPFIND`, `REPORT`, `HEAD`, `GET`, and export/sync paths;
- `MKCOL`/`MKCALENDAR`, `PUT`, `POST`, `PROPPATCH`, `ACL`, and `DELETE`;
- `COPY` and `MOVE`, checking both source and destination;
- scheduling inbox/outbox, free/busy, and custom iTIP operations;
- JSON equivalents of the operations above.

For every rejected read, assert both the status and the absence of event UID,
summary, description, email, principal ID, and calendar ID in the response.

### Private and public calendars

In this section, a non-owner means an authenticated non-owner. Public calendar
ACLs are granted to `{DAV:}authenticated`; anonymous requests still require
authentication and must return `401`.

| Calendar state | Authenticated non-owner read | Authenticated non-owner write | Subscription | Security expectation |
|---|---:|---:|---:|---|
| Private | no | no | no | Direct, export, report, sync, and JSON paths expose no calendar data |
| Public `{DAV:}read` | yes | no | yes, read-only | Reads are allowed; create, update, delete, and scheduling side effects are rejected |
| Public `{DAV:}write` | yes | yes | yes | Only the documented write operations are allowed; public access still does not grant ACL administration |

Transitions are security-sensitive:

- [ ] Making a public calendar private revokes existing subscriptions and
      direct public access.
- [ ] Changing public-write to public-read removes write/delete ability.
- [ ] Deleting a local subscription does not mutate the source calendar.
- [ ] Re-subscribing does not preserve stronger rights from an old delegation.
- [ ] Advertised DAV privileges match the effective rights.

### Event classification

`CLASS:PRIVATE` and `CLASS:CONFIDENTIAL` are distinct from calendar visibility.
A non-owner who may read the calendar should receive an anonymized busy event,
not the original payload.

- [ ] Preserve only the allowlisted fields required by product behavior. The
      current sanitizer keeps UID, organizer value, timing, and recurrence
      properties; verify that exposing the organizer is intended on every
      surface.
- [ ] Replace the summary with `Busy` and normalize the resulting class to
      `PRIVATE`.
- [ ] Remove sensitive description, location, attendees, conference links,
      alarms, attachments, and non-whitelisted extensions.
- [ ] Apply the same sanitization to recurring masters, `RDATE`/`EXDATE`, and
      recurrence overrides.
- [ ] Apply it to DAV `GET`/`REPORT`, JSON reports, exports, public
      subscriptions, delegated calendars, team calendars, and asynchronous
      messages containing event data.
- [ ] Confirm that the owner still receives the complete event.


### Delegated calendars and ACLs

Calendar delegation uses the access levels and wire rights defined by
`ESN\DAV\Sharing\Plugin` and its SabreDAV parent:

| Wire right | Code access level | Read events | Create/update/delete | Change ACL or delegate further |
|---|---|---:|---:|---:|
| `dav:read` | `ACCESS_READ` | yes | no | no |
| `dav:read-write` | `ACCESS_READWRITE` | yes | yes | no |
| `dav:administration` | `ACCESS_ADMINISTRATION` | yes | yes | yes, through the supported delegation flow |

Review requirements:

- [ ] Test `dav:read`, `dav:read-write`, and `dav:administration`
      independently; do not use one "delegate" scenario as a substitute for
      the matrix.
- [ ] Protect the owner's canonical URL as well as the delegate's copied
      calendar URL.
- [ ] A `dav:read` delegate cannot create, update, delete, change PARTSTAT, send
      iTIP, update ACLs, or grant another delegation.
- [ ] A `dav:read-write` delegate cannot change ACLs or grant another
      delegation.
- [ ] `dav:administration` delegation cannot be used for self-delegation or to
      silently retain rights that the owner revoked.
- [ ] Private/confidential event details remain anonymized for delegates unless
      product requirements explicitly grant the owner-equivalent view.
- [ ] Revocation blocks DAV, JSON, direct source URLs, copied-calendar URLs, and
      iTIP immediately.
- [ ] Removing and recreating a delegated calendar does not resurrect stale
      rights or metadata.

### Resource and team calendars

Resources and team calendars are principals with their own calendar homes, not
ordinary user calendars. Review them separately because their administrator or
member may act on behalf of the owning principal.

- [ ] A resource administrator can perform only the documented operations and
      loses access immediately after revocation.
- [ ] A normal user cannot accept or modify resource participation as the
      resource administrator.
- [ ] Team-calendar members with `dav:read` cannot write; members with
      `dav:read-write` or `dav:administration` cannot act as an unrelated
      organizer.
- [ ] Non-members cannot discover or read a private team calendar.
- [ ] Public team calendars anonymize classified event details for non-members
      while members retain their intended view.
- [ ] Event moves validate membership and write access at both source and
      destination.
- [ ] Principal search and calendar-root discovery are tenant-filtered for both
      resources and team calendars.

## Scheduling and identity authorization

iTIP content contains identities and can cause writes in calendars other than
the HTTP caller's calendar. Authentication alone is therefore insufficient.

- [ ] For `REQUEST`, `CANCEL`, and `REPLY`, the authenticated identity is
      authorized to act for the payload recipient.
- [ ] For `COUNTER`, the authenticated identity is authorized to act for the
      payload sender; a delegate needs write access to act on the sender's
      behalf.
- [ ] Delegation is checked with the required right; `dav:read` delegation is
      not enough for scheduling writes.
- [ ] Revoked delegation cannot be reused through an event URI, copied calendar
      URI, inbox/outbox item, or previously generated payload.
- [ ] `ORGANIZER` belongs to the calendar owner or an identity explicitly
      allowed to organize there; an attendee cannot forge a third-party
      organizer.
- [ ] Recurring-event exceptions do not let a caller modify uninvited
      occurrences or unrelated attendees.
- [ ] A rejected scheduling request creates no clone, inbox item, notification,
      email, or AMQP message.
- [ ] Import-only bypasses remain limited to the migration/import entry point
      and cannot be triggered by an ordinary request.

## Protocol and representation parity

The JSON API is a convenience layer over the same DAV tree. It must not become
an independent authorization model.

When adding an endpoint, optimized query, bulk operation, or alternate
representation:

- [ ] Resolve the target through the DAV tree and enforce the same ACL as the
      corresponding standard DAV operation.
- [ ] Check every caller-supplied resource path independently; never trust a
      calendar path embedded in JSON merely because the top-level endpoint is
      accessible.
- [ ] Check both source and destination for `COPY` and `MOVE`.
- [ ] Ensure fast paths, filter-less reports, sync-token reports, exports, and
      bulk reads do not skip ACL or private-event sanitization.
- [ ] Compare DAV XML/iCalendar and JSON results for the same actor and
      resource, including forbidden response bodies.
- [ ] Include content negotiation and suffix rewriting (`.json`) in the review.
- [ ] Prefer removing an unused non-standard endpoint over maintaining another
      security-sensitive access path.
- [ ] Remove obsolete endpoints from implementation, unit/integration tests,
      and API documentation together.

Security-sensitive route inventory:

| Operation | DAV/native path | JSON/custom path |
|---|---|---|
| Calendar discovery | `PROPFIND /calendars/...` | `GET /calendars/{home}.json` |
| Event read | `GET`, `REPORT`, sync REPORT, export | event `.json`, JSON `REPORT`, JSON export |
| Event write | `PUT`/`DELETE`, `COPY`/`MOVE` | JSON `PUT`/`DELETE`, method overrides |
| Calendar metadata/ACL | `PROPPATCH`, `ACL`, DAV sharing `POST` | JSON `PROPPATCH`, `ACL`, subscription/delegation requests |
| Scheduling/free-busy | inbox/outbox `POST`, CalDAV reports | custom `ITIP`, `/calendars/freebusy` |
| Principal discovery | `PROPFIND`, principal `REPORT` | JSON principal properties where supported |

## Tenant isolation

The authenticated domain is a mandatory query and authorization boundary.
Filtering only the final data read is insufficient: discovery, search, error
messages, and identifiers can disclose that foreign objects exist.

Apply tenant isolation to:

- user, resource, team-calendar, domain, and technical principals;
- calendar and address-book roots, homes, collections, and individual objects;
- direct canonical IDs and URLs;
- `PROPFIND` depth traversal, principal-property search, expand-property,
  principal-match, and home-set discovery;
- DAV and JSON create/read/update/delete operations;
- public sharing, delegation, scheduling, and free/busy;
- technical-token operations;
- database queries, caches, background jobs, notifications, logs, and AMQP
  payloads.

Review questions:

- [ ] Does every database lookup include the authenticated domain or validate
      domain membership immediately after lookup?
- [ ] Does root enumeration filter before constructing child nodes and hrefs?
- [ ] Can a foreign object ID reveal a principal href, display name, email,
      domain name, calendar home, address-book home, or a different status code?
- [ ] Are resource and team-calendar domain fields handled as carefully as user
      domain membership?
- [ ] Does a missing `AuthTenant` fail closed rather than remove the domain
      filter?
- [ ] Are cache keys and deduplication keys tenant-qualified?
- [ ] Are explicit cross-domain sharing features documented and tested as
      narrow exceptions rather than general bypasses?

## CardDAV and address books

Calendar reviews must not obscure the CardDAV surface exposed by the same DAV
server.

- [ ] Review private, public-read, and public-write address books.
- [ ] Review `dav:read`, `dav:read-write`, and `dav:administration` delegation,
      revocation, and third-party access.
- [ ] Check native CardDAV and JSON paths for contacts, reports, exports,
      subscriptions, `COPY`, and `MOVE`.
- [ ] Separate ordinary users, domain administrators, and technical users for
      domain address books and the domain-members address book.
- [ ] Apply tenant isolation to root enumeration, contacts, public rights,
      delegation, and technical-token management.
- [ ] Treat contact names, email addresses, phone numbers, avatars, and vCard
      properties as protected data in denied responses and logs.

## Side effects and data egress

Authorization tests should verify more than the synchronous HTTP status.

- [ ] A forbidden request does not change MongoDB data or sync tokens.
- [ ] It does not create copied calendar/address-book objects, scheduling items,
      or attendee clones.
- [ ] It does not emit email, alarm, notification, or RabbitMQ messages.
- [ ] AMQP messages identify the connected user separately from the owner or
      acted-on principal.
- [ ] Private-event sanitization also applies when event data is published.
- [ ] Logs do not contain credentials, technical tokens, raw JWTs, private event
      bodies, or foreign-tenant personal data.
