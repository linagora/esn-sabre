# Authorization and Data Isolation Review Guide

## Purpose

This document defines the authorization and data-isolation boundaries of
`esn-sabre`. It is intended for developers, human reviewers, and AI-assisted
reviews.

It is a living guide, not a claim that the service is free of vulnerabilities.
Update it when a new authentication mechanism, principal type, resource type,
protocol route, sharing mode, or security regression is introduced.

## Related documentation

This document defines cross-cutting authorization invariants.
Detailed behavior is owned by the following documents in this repository:

- [Technical Token Authentication](TECHNICAL_TOKEN.md) for technical-token
  authentication and domain scope;
- [DAV Principals](PRINCIPALS.md) for principal types, properties, and
  discovery;
- [Team Calendars](TEAM-CALENDAR.md) for team-calendar sharing and scheduling;
- [JSON API](JSON-API.md) for JSON routes, request payloads, and responses.

When behavior changes, update this guide and the owning document in the same
change.

## Authorization model

Authorization is defined across multiple dimensions:

| Dimension | Representative values |
|---|---|
| Actor | anonymous, owner, same-domain user, public subscriber, `dav:read`/`dav:read-write`/`dav:administration` delegate, resource administrator, team-calendar member, technical user |
| Resource | user principal, resource principal, team-calendar principal, calendar home, calendar, event, scheduling inbox/outbox, address book, contact |
| Action | discover, list, read, create, update, delete, move, copy, share, change ACL, schedule, export, sync |
| Interface | DAV XML, iCalendar, JSON API, free/busy, iTIP, technical-token endpoint, RabbitMQ output |
| Tenant relation | same tenant, foreign tenant, missing tenant context |
| State | private, public-read, public-write, delegated, delegation revoked, subscription removed, `PRIVATE`/`CONFIDENTIAL` event |

The same authorization invariants apply to every relevant combination of these
dimensions. A normal owner path does not define the behavior of public,
delegated, cross-tenant, or alternate-protocol paths.

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
   RS256 public key and its `sub` email is resolved to a user tenant. This
   legacy OpenPaaS frontend compatibility path is deprecated and should be
   removed when those frontends no longer depend on it.
2. `TwakeCalendarToken: <token>`: the token is introspected by the calendar side
   service and produces a technical or user tenant.
3. HTTP Basic authentication: credentials are checked against LDAP. When
   enabled, the same mechanism also supports administrator impersonation using
   the configured administrator credential and an explicit target identity.

Successful authentication produces an `AuthTenant` containing an identity,
domain ID, and tenant type. This context is propagated on `auth:success` to the
principal and calendar backends. Authorization code must not run with a missing
or stale tenant context.

### Administrator impersonation

Impersonation is a trusted authentication boundary, not a general ACL bypass:

- It is enabled only by `SABRE_IMPERSONATION_ENABLED` and is intended for
  internal, non-public deployments.
- Basic credentials use
  `{SABRE_ADMIN_LOGIN}&{targetEmail}:{SABRE_ADMIN_PASSWORD}`. Resolution tries
  user, resource, then team-calendar identities; a missing user is provisioned
  only after LDAP and domain validation.
- Success assumes the target principal, tenant type, and domain. The credential
  may select a target in another tenant, but each request has one target
  `AuthTenant`, never an administrator-wide data scope.
- Downstream authorization uses only this target context; it cannot retain an
  administrator identity or authorize data outside the target domain.
- Invalid credentials, a disabled feature, or an unresolved target fail without
  disclosing target data or creating side effects.

## Calendar authorization

### Ownership and direct paths

An owner may manage their own calendar and events. A user without an explicit
grant must not gain access by constructing the owner's canonical calendar or
event URL.

Authorization applies to all methods and representations, including:

- `PROPFIND`, `REPORT`, `HEAD`, `GET`, and export/sync paths;
- `MKCOL`/`MKCALENDAR`, `PUT`, `POST`, `PROPPATCH`, `ACL`, and `DELETE`;
- `COPY` and `MOVE`, checking both source and destination;
- scheduling inbox/outbox, free/busy, and custom iTIP operations;
- JSON equivalents of the operations above.

A rejected read must return no protected event UID, summary, description,
email, principal ID, or calendar ID in the response.

### Private and public calendars

In this section, a non-owner means an authenticated non-owner. Public calendar
ACLs are granted to `{DAV:}authenticated`; anonymous requests still require
authentication and must return `401`.

| Calendar state | Authenticated non-owner read | Authenticated non-owner write | Subscription | Security expectation |
|---|---:|---:|---:|---|
| Private | no | no | no | Direct, export, report, sync, and JSON paths expose no calendar data |
| Public `{DAV:}read` | yes | no | yes, read-only | Reads are allowed; create, update, delete, and scheduling side effects are rejected |
| Public `{DAV:}write` | yes | yes | yes | Only the documented write operations are allowed; public access still does not grant ACL administration |

The following invariants apply to calendar visibility transitions:

- Making a public calendar private revokes existing subscriptions and direct
  public access.
- Changing public-write to public-read removes write and delete ability.
- Deleting a local subscription does not mutate the source calendar.
- Re-subscribing does not preserve stronger rights from an old delegation.
- Advertised DAV privileges match the effective rights.

### Event classification

`CLASS:PRIVATE` and `CLASS:CONFIDENTIAL` are distinct from calendar visibility.
A non-owner who may read the calendar should receive an anonymized busy event,
not the original payload.

- Sanitized events preserve only fields allowlisted by product behavior. The
  current sanitizer keeps UID, organizer value, timing, and recurrence
  properties. Exposing the organizer must remain an explicit product decision
  for every surface.
- Sanitization replaces the summary with `Busy` and normalizes the resulting
  class to `PRIVATE`.
- Sensitive description, location, attendees, conference links, alarms,
  attachments, and non-whitelisted extensions must not be exposed.
- The same sanitization applies to recurring masters, `RDATE`/`EXDATE`, and
  recurrence overrides.
- Sanitization applies to DAV `GET`/`REPORT`, JSON reports, exports, public
  subscriptions, delegated calendars, team calendars, and asynchronous
  messages containing event data.
- The owner receives the complete event.

### Delegated calendars and ACLs

Calendar delegation uses the access levels and wire rights defined by
`ESN\DAV\Sharing\Plugin` and its SabreDAV parent:

| Wire right | Code access level | Read events | Create/update/delete | Change ACL or delegate further |
|---|---|---:|---:|---:|
| `dav:read` | `ACCESS_READ` | yes | no | no |
| `dav:read-write` | `ACCESS_READWRITE` | yes | yes | no |
| `dav:administration` | `ACCESS_ADMINISTRATION` | yes | yes | yes, through the supported delegation flow |

The following invariants apply independently to each access level:

- The owner's canonical URL and the delegate's copied calendar URL enforce the
  same effective delegation rights.
- A `dav:read` delegate cannot create, update, delete, change PARTSTAT, send
  iTIP, update ACLs, or grant another delegation.
- A `dav:read-write` delegate cannot change ACLs or grant another delegation.
- `dav:administration` delegation cannot be used for self-delegation or to
  silently retain rights that the owner revoked.
- Private and confidential event details remain anonymized for delegates unless
  product requirements explicitly grant an owner-equivalent view.
- Revocation immediately blocks DAV, JSON, direct source URLs,
  copied-calendar URLs, and iTIP.
- Removing and recreating a delegated calendar does not resurrect stale rights
  or metadata.

### Resource and team calendars

Resources and team calendars are principals with their own calendar homes, not
ordinary user calendars. Their authorization model covers an administrator or
member acting on behalf of the owning principal.

- A resource administrator can perform only the documented operations and
  loses access immediately after revocation.
- A normal user cannot accept or modify resource participation as the resource
  administrator.
- Team-calendar members with `dav:read` cannot write. Members with
  `dav:read-write` or `dav:administration` cannot act as an unrelated organizer.
- Non-members cannot discover or read a private team calendar.
- Public team calendars anonymize classified event details for non-members
  while members retain their intended view.
- Event moves validate membership and write access at both source and
  destination.
- Principal search and calendar-root discovery are tenant-filtered for both
  resources and team calendars.

## Scheduling and identity authorization

iTIP content contains identities and can cause writes in calendars other than
the HTTP caller's calendar. Authentication alone is therefore insufficient.

- For `REQUEST`, `CANCEL`, and `REPLY`, the authenticated identity must be
  authorized to act for the payload recipient.
- For `COUNTER`, the authenticated identity must be authorized to act for the
  payload sender. A delegate needs write access to act on the sender's behalf.
- `dav:read` delegation is not sufficient for scheduling writes.
- Revoked delegation cannot be reused through an event URI, copied calendar
  URI, inbox/outbox item, or previously generated payload.
- `ORGANIZER` must belong to the calendar owner or an identity explicitly
  allowed to organize there. An attendee cannot forge a third-party organizer.
- Recurring-event exceptions cannot be used to modify uninvited occurrences or
  unrelated attendees.
- A rejected scheduling request creates no clone, inbox item, notification,
  email, or AMQP message.
- Import-only bypasses are limited to the migration/import entry point and
  cannot be triggered by an ordinary request.

## Protocol and representation parity

The JSON API is a convenience layer over the same DAV tree. It must not become
an independent authorization model.

The following invariants apply to endpoints, optimized queries, bulk operations,
and alternate representations:

- Targets are resolved through the DAV tree and enforce the same ACL as the
  corresponding standard DAV operation.
- Every caller-supplied resource path is authorized independently. A calendar
  path embedded in JSON is not trusted merely because the top-level endpoint is
  accessible.
- `COPY` and `MOVE` authorize both source and destination.
- Fast paths, filter-less reports, sync-token reports, exports, and bulk reads
  enforce ACLs and private-event sanitization.
- DAV XML/iCalendar and JSON expose the same effective authorization behavior,
  including the protected content of denied responses.
- Content negotiation and suffix rewriting such as `.json` do not alter the
  effective authorization decision.
- Unused non-standard endpoints should be removed rather than retained as
  duplicate security-sensitive access paths.
- Removing an endpoint also removes its implementation, tests, and API
  documentation.

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

Tenant isolation obeys these invariants:

- Every database lookup includes the authenticated domain or validates domain
  membership immediately after lookup.
- Root enumeration applies domain filtering before constructing child nodes and
  hrefs.
- A foreign object ID does not reveal a principal href, display name, email,
  domain name, calendar home, address-book home, or distinguishable status.
- Resource and team-calendar domain fields are enforced as strictly as user
  domain membership.
- A missing `AuthTenant` fails closed and never disables domain filtering.
- Cache keys and deduplication keys are tenant-qualified.
- Explicit cross-tenant flows are narrow, documented exceptions and do not
  provide a general tenant-isolation bypass.

### Explicit cross-tenant features

| Flow | Boundary |
|---|---|
| Administrator impersonation | May select a target in another tenant, but the request assumes only that target `AuthTenant`; it creates no global tenant view. |
| Cross-domain scheduling | iTIP/iMIP may carry the scheduling payload needed for an invitation, update, cancellation, or reply. It grants no direct access to the foreign principal or calendar. |
| Direct CalDAV/CardDAV, JSON, free/busy, and public resources | Foreign-tenant access is not supported. "Public" means visible within the authenticated tenant. |
| Sharing, delegation, and subscriptions | Tenant-local; cross-domain sharees are rejected and copied collections do not bypass tenant checks. |
| Technical, resource, and team-calendar principals | Tenant-local even when technically privileged; the domain in `AuthTenant` remains mandatory. |
| Multi-domain users | Each request selects one domain; memberships do not merge tenant views. |

Any new cross-tenant flow must define the allowed data, identity checks,
revocation, protocol surfaces, and side effects. Reviews also cover discovery,
cached identifiers, denial responses, and asynchronous messages.

## CardDAV and address books

Calendar reviews must not obscure the CardDAV surface exposed by the same DAV
server.

- Private, public-read, and public-write address books enforce their respective
  visibility and write boundaries.
- `dav:read`, `dav:read-write`, and `dav:administration` delegation remain
  distinct across revocation and third-party access.
- Native CardDAV and JSON paths enforce the same authorization for contacts,
  reports, exports, subscriptions, `COPY`, and `MOVE`.
- Ordinary users, domain administrators, and technical users have distinct
  rights on domain address books and the domain-members address book.
- Tenant isolation applies to root enumeration, contacts, public rights,
  delegation, and technical-token management.
- Contact names, email addresses, phone numbers, avatars, and vCard properties
  are protected data in denied responses and logs.

## Side effects and data egress

Authorization applies to synchronous responses and every resulting side effect:

- A forbidden request does not change MongoDB data or sync tokens.
- A forbidden request does not create copied calendar/address-book objects,
  scheduling items, or attendee clones.
- A forbidden request does not emit email, alarm, notification, or RabbitMQ
  messages.
- AMQP messages identify the connected user separately from the owner or
  acted-on principal.
- Private-event sanitization applies when event data is published.
- Logs do not contain credentials, technical tokens, raw JWTs, private event
  bodies, or foreign-tenant personal data.
