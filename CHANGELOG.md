# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)

## [Unreleased]

### New Features

 - ISSUE-437 Expose video conference links through the standard RFC 7986 `CONFERENCE` property — events carrying `X-OPENPAAS-VIDEOCONFERENCE` are decorated upon `PUT` (and upon iTIP delivery) so that external clients (Apple Calendar, iOS, Outlook, ...) display a join button. `X-OPENPAAS-VIDEOCONFERENCE` is kept for Twake clients, and is conversely derived from the `CONFERENCE` property of events created by external clients (#437)
 - Every runtime setting can now be configured from `config.json` instead of the process environment. The `environment` section of `config.json` takes precedence, the process environment is still honoured as a fallback, and the built-in default applies last, so existing deployments keep working untouched. `scripts/generate_config.sh` materializes the whole section, `config.json.default` lists every key with its default value, and [doc/CONFIGURE.md](doc/CONFIGURE.md) documents all of them.
 - Nginx rate limiting: `X-Forwarded-For` is trusted by default to key the limit on the client IP; `NGINX_TRUSTED_PROXIES` can restrict which peers may supply it, `NGINX_TRUSTED_CLIENTS_CIDR` exempts trusted clients such as the Twake Calendar side service, `NGINX_RATE_LIMIT=off` disables rate limiting, and `NGINX_RATE_LIMIT_STATUS` sets the rejection status. Invalid values stop the container at startup. See [doc/CONFIGURE.md](doc/CONFIGURE.md#nginx-rate-limiting)
 - ISSUE-536 The recurrence expansion limit is configurable through `SABRE_MAX_RECURRENCES` (default `3500`, negative disables it), so that long-running recurring series still return their occurrences in recent time ranges. See [doc/CONFIGURE.md](doc/CONFIGURE.md#recurrence-expansion) (#536)
 - ISSUE-425 Auto-provision users upon a DAV request — when an LDAP or impersonated user authenticates successfully but has no entry in the `users` collection yet, the entry is created on the fly (following the twake-calendar-side-service document format) instead of returning a `401`. Gated by the `AUTO_PROVISION` env var (default `true`). Needed upon migrations (#425)

### Performance

 - Listing a calendar (PROPFIND `Depth: 1`, `sync-collection`, `calendar-multiget`) no longer reads the calendar sharing state from MongoDB once per event: the child ACL is computed once per listing. On a 3000 event calendar, a PROPFIND `Depth: 1` goes from 21014 MongoDB commands (~5 s) down to 21 (~50 ms).
 - Same for address books: listing the cards of an address book no longer reads its public right and sharees once per card, and checking that a card exists no longer computes its ACL. On a 3000 card address book, a PROPFIND `Depth: 1` goes from 6018 MongoDB commands down to 20, an `addressbook-multiget` of 500 cards from 2508 down to 510.
 - The read access check of `calendar-multiget` and `addressbook-multiget` REPORTs looks up the requested hrefs in one query per collection instead of one query per href. A `calendar-multiget` of 500 events goes from 4004 MongoDB commands (~1 s) down to 20 (~60 ms), an `addressbook-multiget` of 500 cards down to 14.
 - Multi-get lookups of calendar objects and cards query at most 512 URIs at a time, so that a request carrying thousands of hrefs does not turn into a single query that could hit the database timeout.
 - MongoDB indexes are created once when the container starts (`scripts/create-indexes.php`) instead of on every HTTP request, which sent 14 `createIndexes` commands per request. The `SHOULD_CREATE_INDEX` setting is removed.
 - Add the missing MongoDB indexes: `calendarinstances { calendarid, share_href }` (the sharees and public right lookups of a calendar were collection scans), `sharedaddressbooks { addressbookid, share_href }` and `{ principaluri }`, `addressbooksubscriptions { principaluri }` and `{ source }`. Drop the redundant `calendarobjects { calendarid }` and `cards { addressbookid }`, covered by the `{ calendarid, uri }` and `{ addressbookid, uri }` indexes.

   Upgrade: the new indexes are created at startup. To drop the redundant ones, or if index creation fails at startup, run the `mongosh` script from [doc/storage/MONGO.md](doc/storage/MONGO.md#index-creation).

### Changes

 - **Behaviour change**: an LDAP infrastructure failure during Basic authentication (server down or unreachable, timeout, server busy/unavailable, service account bind failure, failing search) is now answered `503 Service Unavailable` instead of `401`, so that an outage is no longer mistaken for bad credentials. Only an answer of the directory about the credentials keeps returning `401`. LDAP operations now time out after `LDAP_TIMEOUT` seconds (default `5`) instead of hanging
 - **Behaviour change**: requests rejected by the Nginx rate limit are now answered `429 Too Many Requests` with `Retry-After: 1` and the CORS headers, instead of `503 Service Unavailable`. Clients and monitoring that treat 503 as a rate-limit signal must be updated, or set `NGINX_RATE_LIMIT_STATUS=503` to keep the previous status

### Bug Fixes

 - The bulk free/busy route (`POST /calendars/freebusy`) accepts an optional `timezone` (IANA identifier, `400` when unknown) in which floating dates and times are read. All-day events used to be reported busy from UTC midnight to UTC midnight, so that a booking page offered the first hours of a Paris owner's day off (linagora/twake-calendar-side-service#1200). Without `timezone`, UTC is used as before
 - Sharees (read, read-write, administration) can read the owner's calendar again (`calendarserver:delegatedsource`, e.g. `REPORT /calendars/{ownerId}/{calendarId}.json`): since #411 enforces `{DAV:}read` on JSON REPORTs, they got `403`. The owner's calendar and its events now grant `{DAV:}read`, and only read, to these sharees. PRIVATE / CONFIDENTIAL events stay anonymized for them. Free-busy sharees, declined and revoked shares and other users still get `403`
 - Deleting a calendar now deletes its events. They were left behind in `calendarobjects` because the deletion filtered on an `ObjectId` while the calendar id is stored as a string there. Existing orphans can be listed with `db.calendarobjects.aggregate([{ $lookup: { from: "calendars", let: { id: "$calendarid" }, pipeline: [{ $match: { $expr: { $eq: [{ $toString: "$_id" }, "$$id"] } } }], as: "c" } }, { $match: { c: [] } }, { $count: "orphans" }])`.
 - ISSUE-699 Expanding a single event over a time range (`REPORT` with `match.start` / `match.end` on an event) is answered `422` instead of `500` when the series generates more occurrences than `SABRE_MAX_RECURRENCES` before reaching the range, e.g. an open-ended daily event that started more than ten years before (#699)
 - ISSUE-404 Fix duplicated `DAV`/`X-Sabre-Version` headers in DAV responses — the nginx capability headers are now only emitted for the OPTIONS short-circuit, letting Sabre emit them once (with consistent casing) for proxied responses (#404)
 - ISSUE-800 ACL, MKCOL, MKCALENDAR and XML POST requests whose body holds a `<D:principal>` with an empty or blank `<D:href>` are answered `400` instead of `500` (#803)
 - ISSUE-801 Tighten the attendee-change guard on scheduling: an attendee can no longer move every occurrence of an event by rewriting only the `VTIMEZONE` definition (`X-LIC-LOCATION` or its observances) behind a `TZID`; the resolved start and end instants are now compared (#802)
 - ISSUE-799 A new answer of a participant whose stored e-mail address contains upper-case letters is now propagated to the future overrides of the series (#804)
 - ISSUE-807 Updating a calendar object without any `VEVENT` (e.g. a task) no longer fails with a `500` (#808)

## [2.5.0.1] - 2026-11-11

### New Features

 - ISSUE-380 Add an offload mode for binary attachments: inline attachments uploaded with an event PUT can be sent to TDrive
 - Configurable Nginx request body limit (default stays at 1 MiB), so deployments can raise it to accept Base64 calendar attachments

### Bug Fixes

 - Tighten the RFC 6638 attendee-change guard: an attendee can no longer change the master's recurrence rule (RRULE, RDATE, EXDATE) to give their copy occurrences the organizer never scheduled (#795)
 - Fix occurrences shifted to a different time for readers expanding in UTC when an attendee's override swapped a zoned DTSTART / DTEND for a floating one (#794)

## [2.5.0] - 2026-11-07

### Security

 - Fix private occurrences of a public recurring event being stored as public and served unsanitised to delegates, sharees, public readers and subscribers: an object is now classified private when any of its components is
 - Refuse writes and deletions by a delegate with write access on an object holding a private occurrence of a public series: every VEVENT is now checked, not only the first

### Fixed

 - Fix a VALARM time-range calendar-query hiding the later reminders of a recurring event when an override has no VALARM, as many clients write it when moving one occurrence
 - Fix the calendar ICS export answering 500 (or including bogus entries) when a stored object contains a property named VTIMEZONE, VEVENT, VTODO or VJOURNAL: only components are now merged
 - Fix the calendar ICS export giving the wrong time to events of an object whose TZID is defined with different rules than an earlier exported object: every distinct definition of a TZID is now kept

## [2.4.7.15] - 2026-11-06

### Bug Fixes

 - ISSUE-749 Fix `sabre:contact:*` notifications for writes made through an address book subscription, and clean up the deleted messages (#780)

## [2.4.7.14] - 2026-11-05

### New Features

 - ISSUE-732 Add the address book sync token and card etag to `sabre:contact:*` real-time notifications (#734)

### Bug Fixes

 - Publish the stored event paths in real-time notifications after iTIP delivery (#711, #746)
 - Answer 400 instead of 500 on an invalid `match.start` / `match.end` time range in the JSON calendar REPORT, event expansion and free-busy-query (#751)
 - Answer 400 instead of 500 on a subscription source href whose path is not absolute (#762)
 - Answer 400 instead of 500 on REPORT bodies with ill-typed content (#760)
 - Answer 400 instead of 500 on an empty WebDAV share-resource POST body (#764)
 - Fix a DECLINED sharee of a resource or team calendar still getting read and write access on the owner's calendar (#759)
 - Refuse in strict mode RRULEs that cannot be expanded (`COUNT=0`, `INTERVAL=0`, unknown part, out of range `BY*` value) with a 400 instead of storing events that fail on every expansion (#761)
 - ISSUE-755 Fix JSON expansion dropping `DURATION` events that start before the beginning of the range; an all-day event without `DTEND` nor `DURATION` now lasts one day (#755)
 - Fix calendar-query REPORTs missing recurring events whose `RDATE` is earlier than `DTSTART`
 - Fix untrusted `CONFERENCE` video links being stored and `ATTACH` properties answering 500 when the property name is malformed (`..CONFERENCE`, `..ATTACH`)
 - Refuse a PUT update that takes the UID of another object of a team calendar (#757)

### Security

 - Fix an attendee bypassing the change guard by sending duplicate overrides for the same `RECURRENCE-ID`, allowing renaming, moving or relocating an instance (GHSA-h96c-v97v-h9mc)
 - Never deserialise a malformed MKCALENDAR or ACL body before it is validated, which could hold a PHP worker forever (GHSA-jcjh)

## [2.4.7.13] - 2026-11-03

### Bug Fixes

 - POST /itip now answers 400 for an iTIP method the server does not handle (only REQUEST, REPLY, CANCEL and COUNTER are accepted) instead of delivering it and ending in a 500 (#714)
 - Do not publish real-time event, alarm and search-indexing messages after a failed local iTIP delivery (#712)
 - Do not fail the home PROPFIND with a 500 for Thunderbird, DAVx5 and JSON clients when a shared calendar or address book has a display name that is not a plain string (#710)
 - Tolerate an iTIP REPLY whose RECURRENCE-ID is not a valid date-time instead of failing with a 500; the delivery is refused with a 5.3 schedule status (#721)
 - Bulk free/busy requests no longer fail with a 500 for every requested user when one event exceeds the recurrence limit; that event is skipped (#703)
 - Answer 400 instead of 500 for an event whose DTSTART or DTEND has an invalid value type when the other is missing (#700)
 - JSON listings and REPORTs no longer answer 200 with an empty body when a contact or event holds non-UTF-8 bytes in a parameter value; invalid bytes are substituted (#701, #730)
 - Validate the sharee `dav:href` on the JSON calendar sharing API: it must be a valid `mailto:` URI (#635, #649)
 - Address book subscriptions are only listed while their source address book is still published
 - Address book subscription rights are bounded by the current publication of the source: a subscription no longer keeps write access after the owner republishes read-only, nor reads contacts after unpublishing
 - Ill-formed XML bodies on MKCOL, MKCALENDAR, ACL and POST requests are now rejected instead of making the request hang
 - Contacts without `fn_sort` / `email_sort` are no longer skipped or listed twice in paginated contact listings (#702)
 - Reject attendee changes of the RECURRENCE-ID timezone form, and handle ORGANIZER properties with VALUE=TEXT when comparing attendee changes
 - Unprocessable local iTIP deliveries are reported as scheduling failures (status 5.0) instead of 500 errors (#705, #725)
 - The delegated source of an address book is only exposed in JSON to authorized readers
 - Preserve property value types when sanitizing private events
 - Validate event UIDs and supported recurrence frequencies in strict validation mode
 - Preserve alternative conference links across repeated writes
 - Reject malformed photo names when filtering contacts
 - An iTIP REPLY keeps its RECURRENCE-ID when the master generates that occurrence, and is only remapped onto a single moved override (#722, #709, #740, #741)
 - Answer 422 instead of 500 when expanding an event exceeds the recurrence limit (#699, #731)
 - Index the instances of an override's own RRULE so the last occurrence covers its later instances (#697)
 - POST /itip no longer answers 500 for ATTENDEE or ORGANIZER properties that are not calendar addresses (#707, #723, #739, #742)

## [2.4.7.12] - 2026-11-03

### Bug Fixes

 - Publish iTIP and real-time messages as JSON even when parameters such as an ATTENDEE CN are not UTF-8, instead of publishing an empty message that lost the invitation or event notification (#598, #612, #616)
 - Do not fail on a vCard with an empty FN when saving a contact (#604)
 - Do not percent-encode hrefs twice in the Thunderbird / DAVx5 / JSON rewrite of PROPFIND responses (#610)
 - Keep the PROPFIND rewrite for mobile clients working when a display name holds XML-illegal characters, when one href cannot be resolved, or when the body holds a subscription source (#608, #611, #617, #618, #619)
 - ISSUE-603 Reject DTSTART/DTEND/RECURRENCE-ID/EXDATE that are not date-times with a 400 instead of a 500
 - Reject VEVENTs whose DTSTART, DTEND or DURATION cannot be read with a 400 instead of a 500 (#600, #627)
 - Make calendar-query results match Sabre's filter semantics exactly: an event ending at the start of the range is no longer returned, and component names are matched case-insensitively (#602)
 - Fix the stored occurrence window for events with a negative DURATION and for RDATE-only recurring events (#601, #626)
 - Answer 400 instead of 500 to malformed `POST /calendars/freebusy` requests (#622)
 - Answer 400 instead of 500 to malformed `POST /itip` payloads, to iTIP messages holding no VEVENT, and to attachments whose components carry another UID than the payload (#606, #620, #704, #706)
 - Read resource replies leniently: a missing or lower-case PARTSTAT, a sender differing only by case, or a reply without VEVENT no longer breaks `POST /itip` or is ignored (#713)
 - Do not notify invitees without an instance of the shared calendar on another calendar's path, nor fail the owner's write (#640, #643)
 - Keep one VEVENT per occurrence when merging several REPLYs for the same recurrence (#633, #650)
 - Leave disabled delegated group address books out of the contacts listing (#652, #659)
 - Resolve user, group, resource and team calendar addresses only on an exact match (resource addresses with another domain, and non-ASCII characters folded to ASCII letters, no longer match) (#639, #644)
 - Match team calendars by their custom emailAddress, so invitations sent to it are no longer handled as external (#613)
 - Compare e-mail domains case-insensitively when resolving the tenant at authentication (#628)
 - Answer 401 instead of 500 on malformed introspection, JWT or LDAP payloads, and no longer leak internal error messages (#634, #648)
 - Answer 400 instead of 500 to REPORT, PROPFIND, PROPPATCH, MKCOL, MKCALENDAR, ACL, LOCK and sharing POST requests whose XML body is malformed, has invalid filter attributes or element names that cannot be written (#597, #636, #646, #653, #654, #655, #656, #657, #658)
 - Answer 400 instead of 500 to JSON management requests with ill-typed bodies, calendar, subscription or sharee properties, an unknown sharedDelegationStatus, or subscription source hrefs without a valid server path (#637, #638, #641, #642, #645, #647, #676)
 - Parse subscription sources with one shared parser so the listing and the subscription read the same source calendar (#677, #680)
 - Hide subscriptions whose source is gone or in another domain instead of failing the whole listing with 403, and keep the listing working when a share owner's home is unreachable (#674, #675, #682)
 - Answer the JSON sync-token REPORT on subscriptions with the changes of the source calendar instead of a 500 (#663, #692)
 - Do not drop recurring events whose only occurrence in range was moved by an override from JSON REPORT results, and guard JSON expansion against events without VEVENT or DTSTART (#607, #621, #631, #632)
 - Only accept read-free-busy, read and write as calendar public rights; other values are refused with a 412 (#651, #660)
 - Refuse deleting or overwriting a calendar home (deleting one is reserved to technical users) (#662, #693)
 - Fix DELETE of a calendar by the technical user answering 500 (#665, #689)
 - Only rewrite the legacy `events` calendar URI when that calendar is missing (#666, #690)
 - Enforce team calendar UID and ORGANIZER rules on COPY, MOVE and PUT (#664, #691)
 - Run the card write guards (vCard validation, inline photo policy, domain-members technical-user guard) on COPY and MOVE (#667, #678)
 - Guard the domain-members address book whatever the spelling of its path, and refuse XML share-resource on it (#668, #669)
 - Refuse XML PROPPATCH renaming the default contacts and collected address books (#673, #679)
 - Answer 405 to `dav:group-addressbook` on non-group address books, and 403 to unsupported MKCOL in an address book home, instead of 500 (#671, #672, #685)
 - Security: do not disclose hidden fields of PRIVATE / CONFIDENTIAL events through the content length in PROPFIND, calendar-query filters, or COPY / MOVE out of the calendar (#670)
 - Security: with `withFreeBusy=true` or `sharedPublic=true`, only give the link of calendars the user may only read the free/busy of, instead of their name, description, color, sharees and ACL
 - Security: render a subscription source with only what the subscriber may still read of it

## [2.4.7.11] - 2026-10-29

### Upgrade instructions

No specific upgrade instructions.

### New Features

 - Contact search now enforces a minimum size for the query string (#591)
 - Explicitly reject LDAP binds with empty credentials (#595)

### Bug Fixes

 - An LDAP outage during Basic authentication now answers 503 instead of a misleading 401, so clients can tell an outage from bad credentials (#589)
 - Reject malformed XML bodies in REPORT, PROPFIND and PROPPATCH requests, which could make a PHP worker spin until `max_execution_time` (#592)
 - Account for repairs when validating PHOTO properties (#593)
 - Harden attendee change detection in the Schedule plugin (#594)

## [2.4.7.10] - 2026-10-27

### New Features

 - ISSUE-578 Find contacts of an address book by email (#580)

### Bug Fixes

 - ISSUE-579 Expose `numberOfContacts` on delegated address books, so listing address books with `contactsCount=true` no longer returns null for delegated copies (#581)

## [2.4.7.9] - 2026-10-21

### Bug Fixes

 - ISSUE-568 Reject all-day events whose DTEND precedes DTSTART instead of accepting them (#573)

## [2.4.7.8] - 2026-10-21

### Fixed

 - Create a missing default calendar after delegation, even when delegated or other calendars already exist (#570)
 - Preserve read and write-properties privileges on subscription metadata without granting event write privileges (#571)
 - ISSUE-567 Return a JSON response instead of an empty text/html one when the calendar list is empty (#572)

## [2.4.7.7] - 2026-10-20

### Bug Fixes

 - Fix the bulk free/busy route reading all-day events in UTC: it now accepts an optional `timezone` (IANA identifier) in which floating dates and times are read, so a day off no longer starts at the wrong hour on booking pages (#569)
 - ISSUE-554 Return 400 Bad Request instead of 500 on a malformed CalDAV REPORT XML body (#556)
 - ISSUE-550 Restrict the iTIP COUNTER recipient to the stored event organizer (#560)

## [2.4.7.6] - 2026-10-20

### Security

 - Enforce ACL checks on the source when creating or changing CalDAV subscriptions through XML requests (#562)
 - Enforce deletion rights when cleaning up duplicates during a CalDAV import (#563)
 - Enforce ACL before changing the status of a calendar invitation (#564)
 - Check ACL before changing the settings of a shared address book in CardDAV (#565)
 - Enforce ACL on the destination when creating CardDAV subscriptions (#566)

## [2.4.7.5] - 2026-10-17

### Bug Fixes

 - ISSUE-552 Mark DAV responses as private to forbid a browser from serving one user's cached calendar object to a request made with another user's credentials (#559)
 - ISSUE-551 Check the unbind privilege on the calendar home before deleting it: a DELETE on another user's calendar home by any authenticated user was answered 204 (#561)

## [2.4.7.4] - 2026-10-16

### Upgrade instructions

 - A new optional setting `SABRE_MAX_RECURRENCES` sets the maximum number of occurrences expanded for a recurring event (default `3500`, a negative value disables the limit)

### New Features

 - ISSUE-536 Recurrence expansion limit is now configurable with `SABRE_MAX_RECURRENCES`, so long-running recurring series can be reached in recent time ranges (#537)

### Bug Fixes

 - ISSUE-539 Creating an address book with an invalid payload (such as a malformed `openpaas:source` or a non-string address book id) now returns 400 Bad Request with an explicit message instead of a 500 error (#540)
 - ISSUE-530 Address book delegation notifications are now published (#541)
 - ISSUE-542 Listing contacts with an invalid `offset` or `limit` now returns 400 Bad Request (#542)
 - Enforce read ACL on address book listings (#548)
 - ISSUE-531 Renaming an address book is now propagated to its delegated copies (#546)
 - ISSUE-543 Sharing an address book with an invalid payload now returns 400 Bad Request (#545)

## [2.4.7.3] - 2026-10-15

### Bug Fixes

 - ISSUE-533 Counter: refuse proposed dates in the past (#534)

## [2.4.7.2] - 2026-10-13

### New Features

 - ISSUE-526 Support querying CalDAV events across selected calendar sources

### Bug Fixes

 - Preserve delegation rights when unsubscribing from a CardDAV address book (#529)

## [2.4.7] - 2026-10-08

### Upgrade instructions

 - ISSUE-522 Versioned database migrations now run in the background when the server starts; no manual step is required, but expect them to be in progress shortly after startup

### New Features

 - ISSUE-511 Contacts can be sorted by email, and vCard emails are returned in preference order in the contacts response
 - ISSUE-518 Contact listing of a single address book is faster and supports sorting by email (#521, #525)

## [2.4.6.10] - 2026-10-06

### New Features

 - ISSUE-511 CardDAV contacts can be sorted ascending or descending on their name (`FN`) (#519)

## [2.4.6.9] - 2026-10-02

### New Features

 - ISSUE-512 Add `GET /dav/addressbooks/{userId}.json` support for listing contacts with `contacts=true`, with `sort` and `limit` parameters (#514)

## [2.4.6.8] - 2026-10-01

### Security

 - ISSUES-502 Filter untrusted video, attachment, and photo URLs (#513)

## [2.4.6.7] - 2026-09-29

### Bug Fixes

 - ISSUE-509 Fix the order of contacts when paging through a sorted CardDAV address book: contacts with the same name no longer repeat or go missing between pages, and an index speeds up the sorting

## [2.4.6.6] - 2026-09-28

### New Features

 - Team members see the full PRIVATE / CONFIDENTIAL events of a team calendar over raw CalDAV (GET, PROPFIND, REPORT, export), through their delegated instance and through the team calendar itself; public readers and subscribers only see a reduced event
 - Calendar subscriptions now support `sync-collection`, reporting the sync token and the changes of the source calendar, like address book subscriptions do (previously answered 415)

### Bug Fixes

 - Fix PROPFIND `/addressbooks` exhausting the memory limit on domains with a few thousand users, by building the domain members once per request

## [2.4.6.5] - 2026-09-28

### Security

 - Delegates can no longer modify or delete the owner's PRIVATE / CONFIDENTIAL events through a delegated calendar, whether the body is ics or JSON (#504)

## [2.4.6.4] - 2026-09-25

### Upgrade instructions

 - nginx now trusts `X-Forwarded-For` from all peers by default (#498). If the server is directly reachable from untrusted clients, restrict the trusted proxies in your nginx configuration.

### New Features

 - Performance: add a cache of parsed VObject documents, and move the write plugins onto `calendarObjectChange`, to speed up calendar writes
 - Performance: AMQPSchedulePlugin limits its parsing, and iTIP processing is improved

### Bug Fixes

 - CALDAV: sharees can read the owner's calendar shared with them (REPORT on `/calendars/<ownerId>/<calendarId>.json` no longer returns 403)
 - Fix events in a custom timezone falling back to UTC when their VTIMEZONE was only available in a cloned document (sabre-vobject upgrade)

### Dependency upgrades

 - Upgrade sabre-vobject so that a cloned document is the root of its own nodes

## [2.4.6.3] - 2026-09-25

### Bug Fixes

 - ISSUE-1092 Fix missing AMQP message for the source calendar when a delegated user grants delegation via clone (#499)

## [2.4.6.2] - 2026-09-24

### Upgrade instructions

 - The `SHOULD_CREATE_INDEX` setting is removed: MongoDB indexes are now always created once at startup, including the ones that were missing (`calendarinstances`, `sharedaddressbooks`, `addressbooksubscriptions`), and the redundant `calendarobjects { calendarid }` and `cards { addressbookid }` indexes are no longer needed. See `doc/storage/MONGO.md` for the list of indexes.
 - Nginx rate limiting is now configured from environment variables read by `scripts/start.sh` at boot time, and it can be turned off. Rejected requests are now answered `429` instead of `503`, the client is identified through `X-Forwarded-For` (instead of the TCP peer), and trusted clients can be exempted from the limit. Review your rate limiting settings when upgrading.

### New Features

 - Nginx rate limiting: rejections are answered `429` instead of `503`, limits apply per client behind an ingress (`X-Forwarded-For`), trusted clients such as the Twake Calendar side service can be exempted, and rate limiting can be disabled

### Bug Fixes

 - Deleting a calendar now also deletes its events
 - Performance: calendar listing (PROPFIND Depth 1 and initial sync-collection) is much faster on large calendars, e.g. 3000 events from ~5 s to ~50 ms
 - Performance: address book listing (PROPFIND Depth 1) is much faster on large address books, e.g. 3000 cards from ~1.3 s to ~65 ms
 - Performance: calendar-multiget and addressbook-multiget REPORTs are much faster, e.g. 500 events from ~1 s to ~60 ms
 - Performance: multiget REPORTs carrying thousands of hrefs are split into batches of 512 URIs, avoiding database timeouts
 - Performance: MongoDB indexes are created once at startup and missing ones added, reducing query times

## [2.4.5.13] - 2026-09-22

### Changed

 - ISSUE-482 CardDAV: inlined base64 `PHOTO` values are no longer accepted in contacts (#491)

## [2.4.6] - 2026-09-22

### New Features

 - ISSUE-473 Allow invitations from non-member organizers to be moved to team calendars (#489)

### Bug Fixes

 - Fix `PUT ?import` deleting the event it updates and then failing with a 412 error
 - Upgrade sabre-vobject with recurrence fixes (#492)

## [2.4.5.12] - 2026-09-17

### Bug Fixes

 - ISSUE-486 Fix users with admin right being unable to administrate a copied address book (#487)

## [2.4.5.11] - 2026-09-16

### Bug Fixes

 - ISSUE-484 Fix JSON card (jCard) upload failing on a shared address book (clone) (#485)

## [2.4.5.10] - 2026-09-11

### Bug Fixes

 - ISSUE-473 Reject moving a personal event into a Team Calendar that already contains an event with the same UID, so the existing event is no longer overwritten
 - ISSUE-473 Deliver organizer updates and cancellations to an invitation copy that an attendee moved into a Team Calendar, instead of creating another event in their personal calendar; replies from that copy reach the organizer, and moving the copy no longer sends a needless update to the organizer

## [2.4.5.9] - 2026-09-09

### Bug Fixes

 - ISSUE-477 Fix CalDAV REPORT missing an occurrence when an attendee is invited only to selected instances of a recurring event (#478)
 - ISSUE-472 Enforce ORGANIZER validation and authorization at scheduling time (#479)

## [2.4.5.8] - 2026-09-09

### Bug Fixes

 - ISSUE-475 Preserve attendee responses on past occurrences of a recurring event (#476)

## [2.4.5.7] - 2026-09-07

### Bug Fixes

 - ISSUE-409 Fix VALARM scheduling merge logic (#471)

## [2.4.5.6] - 2026-09-03

### Dependency upgrades

 - Upgrade sabre/vobject to the 5.0 development branch (#469)

## [2.4.5.5] - 2026-08-29

### Bug Fixes

 - Bulk free/busy endpoint (`POST /calendars/freebusy`) now expands recurring events and reports the busy period of each occurrence in the requested window, instead of the first occurrence's period
 - Bulk free/busy endpoint no longer reports transparent (`TRANSP:TRANSPARENT`) events, cancelled events, or cancelled single occurrences of a recurring event as busy
 - Bulk free/busy endpoint: an event now occupies the calendar unless the user explicitly declined it. Events with no known email for the user, no matching attendee, or a pending (`NEEDS-ACTION`) participation status were wrongly treated as free

### Dependency upgrades

 - Upgrade sabre/dav to 4.7.1 (#468)

## [2.4.5.2] - 2026-08-24

### Bug Fixes

 - Fix members of a team calendar being unable to update the canonical events of that calendar

## [2.4.5.1] - 2026-08-20

### New Features

 - ISSUE-436 Perform scheduling only for the booker when an unaccepted booking event is created (PUT) or deleted (DELETE)

### Changed

 - Systematize the use of `config.json` (#461)

## [2.4.4.7] - 2026-08-19

### Bug Fixes

 - ISSUE-457 Validate the organizer before a MOVE to a team calendar, so a refused move no longer deletes the source event
 - ISSUE-457 Set the team calendar marker from the destination calendar owner after a MOVE, and send it to attendees' copies of the event

## [2.4.4.5] - 2026-08-14

### Security

 - ISSUE-453 Prevent disclosure of private event details

## [2.4.4.4] - 2026-07-31

### Security

 - Remove the legacy JSON query endpoint
 - ISSUE-448 Restrict resources by domain in DAV ACL (#449)

### Bug Fixes

 - ISSUE-445 Remove the unsupported `allEvents` calendar JSON shortcut

## [2.4.4.3] - 2026-07-29

### Bug Fixes

 - Fix admin delegates not being able to write to source calendars (#443)

## [2.4.4.2] - 2026-07-24

### New Features

 - ISSUE-437 Expose video conference links as RFC 7986 `CONFERENCE` properties (#440)
 - ISSUE-438 Propagate calendar renames to delegated instances (#439)

### Documentation

 - Better document team calendars

## [2.4.4.1] - 2026-07-22

### Bug Fixes

 - ISSUE-434 Fix a team calendar member being unable to REPORT the canonical team calendar URL (#435)

## [2.4.3.9] - 2026-07-21

### Bug Fixes

 - Skip scheduling for public agendas that have not been accepted (#431)

## [2.4.3.8] - 2026-07-18

### New Features

 - ISSUE-400 Allow an organizer transfer to write members of a calendar (#429)
 - Preserve public cancellation metadata (`X-PUBLICLY-CANCELLED-BY`) on events

## [2.4.3.7] - 2026-07-17

### New Features

 - ISSUE-394 Support team calendar scheduling (#424)

## [2.4.3.6] - 2026-07-16

### Bug Fixes

 - Fix public agenda metadata being lost when scheduling events (#427)

## [2.4.3.5] - 2026-07-16

### Upgrade instructions

 - Users who authenticate successfully through LDAP (or are impersonated) are now created automatically in the `users` collection on their first DAV request. This is controlled by the new `AUTO_PROVISION` environment variable, which defaults to `true`. Set `AUTO_PROVISION=false` to keep the previous behaviour (a 401 for users with no entry).

### New Features

 - ISSUE-425 Auto-provision users upon DAV request: an LDAP or impersonated user with no entry in the `users` collection is created on the fly instead of getting a 401, using the same document format as twake-calendar-side-service (#426)

## [2.4.3.4] - 2026-07-10

### New Features

 - ISSUE-417 Add a `connectedUser` field to every AMQP/real-time payload so consumers can audit who performed an action, including in delegation, admin impersonation or technical-token scenarios (#418)

## [2.4.3.3] - 2026-07-09

### Bug Fixes

 - Allow `PUT ?import` to bypass ORGANIZER restrictions, as no scheduling occurs during an import (migration)

## [2.4.3.2] - 2026-07-07

### Bug Fixes

 - TCALENDAR-925 Fix the event counter being broken on imap.linagora.com (#420)

## [2.4.3.1] - 2026-07-07

### Bug Fixes

 - Fix events of a private team calendar not being visible to its members

## [2.4.3] - 2026-07-03

### New Features

 - ISSUE-391 Team calendars: team-calendar principals, principal collection and calendar homes are now supported, including admin impersonation

### Bug Fixes

 - ISSUE-392 Allow the technical token to share team calendars
 - Fix calendar-query REPORT timing out on calendars with a very large number of events (e.g. the reindex request): data is now retrieved in batches, and a REPORT without filter is served from a single query (#403)

### Security

 - Enforce read ACL checks on REPORT requests for CalDAV and CardDAV

## [2.4.2.1] - 2026-07-02

### Bug Fixes

 - ISSUE-408 Fix 403 error when an attendee updates their PARTSTAT (#410)

## [2.4.2] - 2026-07-02

### Bug Fixes

 - ISSUE-404 Fix duplicated DAV/X-Sabre-Version headers in DAV responses (#405)
 - Enforce read ACL on PROPFIND and JSON reports: unreadable resources are no longer listed in PROPFIND responses, and JSON REPORT requests no longer return calendar data the user cannot read (#411)

### Security

 - Enforce read ACL on PROPFIND and JSON reports: unreadable resources are no longer listed in PROPFIND responses, and JSON REPORT requests no longer return calendar data the user cannot read (#411)

## [2.4.1] - 2026-06-30

### Bug Fixes

 - Register custom VObject property types in CalDAV so they are handled properly (#402)

## [2.3.4] - 2026-06-26

### New Features

 - Add an optional `OrganizerValidationPlugin` that restricts the ORGANIZER field on PUT: requests with ATTENDEE but no ORGANIZER, or with differing ORGANIZER across the VEVENTs, are rejected (#395)

### Bug Fixes

 - Reject a VEVENT carrying RRULE or EXDATE without DTSTART explicitly, instead of silently skipping it (#399)

## [2.3.3] - 2026-06-22

### New Features

 - Recipient-specific email VALARM scheduling (#390)

### Bug Fixes

 - Install legacy timezones (#388)

## [2.3.2] - 2026-06-16

### Upgrade instructions

 - ISSUE-377 Scheduling is now only asynchronous, through the AMQP schedule plugin: make sure RabbitMQ is configured before upgrading, as synchronous scheduling is no longer available

### Removed

 - ISSUE-377 Drop synchronous scheduling

## [2.3.1.1] - 2026-06-11

### New Features

 - Reject or sanitize binary attachments (#378)

### Bug Fixes

 - SABRE-381 ITIP: remove the concerns user guard (#382)

## [2.3.1] - 2026-06-08

### Bug Fixes

 - SABRE-374 Lenient date expansion (#379)

## [2.2.4] - 2026-06-05

### Bug Fixes

 - ISSUE-369 Fix private recurring events losing their recurrence rule, exception dates and overridden occurrences, so their timing is preserved

## [2.2.3] - 2026-06-02

### Bug Fixes

 - ISSUE-368 Reject attendee edits to organizer-owned fields in scheduling, as required by RFC-6638 (#370)

## [2.2.1] - 2026-05-28

### New Features

 - ISSUE-358 Restrict principal discovery to what the current user is allowed to see (#364)
 - ISSUE-344 Stricter validation of recurring events in strict mode, with a bad request response on iCalendar validation errors

### Bug Fixes

 - Return forbidden when the authenticated tenant is not set (#365)
 - Set the authenticated tenant on the calendar root before the other auth listeners run, so they get the full tenant context (#367)

## [2.2.0] - 2026-05-26

### New Features

 - ISSUE-437 Expose video conference links through the standard RFC 7986 `CONFERENCE` property — events carrying `X-OPENPAAS-VIDEOCONFERENCE` are decorated upon `PUT` (and upon iTIP delivery) so that external clients (Apple Calendar, iOS, Outlook, ...) display a join button. `X-OPENPAAS-VIDEOCONFERENCE` is kept for Twake clients, and is conversely derived from the `CONFERENCE` property of events created by external clients (#437)
 - Every runtime setting can now be configured from `config.json` instead of the process environment. The `environment` section of `config.json` takes precedence, the process environment is still honoured as a fallback, and the built-in default applies last, so existing deployments keep working untouched. `scripts/generate_config.sh` materializes the whole section, `config.json.default` lists every key with its default value, and [doc/CONFIGURE.md](doc/CONFIGURE.md) documents all of them.
 - Nginx rate limiting: `X-Forwarded-For` is trusted by default to key the limit on the client IP; `NGINX_TRUSTED_PROXIES` can restrict which peers may supply it, `NGINX_TRUSTED_CLIENTS_CIDR` exempts trusted clients such as the Twake Calendar side service, `NGINX_RATE_LIMIT=off` disables rate limiting, and `NGINX_RATE_LIMIT_STATUS` sets the rejection status. Invalid values stop the container at startup. See [doc/CONFIGURE.md](doc/CONFIGURE.md#nginx-rate-limiting)
 - ISSUE-536 The recurrence expansion limit is configurable through `SABRE_MAX_RECURRENCES` (default `3500`, negative disables it), so that long-running recurring series still return their occurrences in recent time ranges. See [doc/CONFIGURE.md](doc/CONFIGURE.md#recurrence-expansion) (#536)
 - ISSUE-425 Auto-provision users upon a DAV request — when an LDAP or impersonated user authenticates successfully but has no entry in the `users` collection yet, the entry is created on the fly (following the twake-calendar-side-service document format) instead of returning a `401`. Gated by the `AUTO_PROVISION` env var (default `true`). Needed upon migrations (#425)
 - ISSUE-343 Thunderbird compatibility in CalDAV discovery: subscribed calendars, subscribed resource calendars and delegated calendars are now discovered by Thunderbird (#355)

### Performance

 - Listing a calendar (PROPFIND `Depth: 1`, `sync-collection`, `calendar-multiget`) no longer reads the calendar sharing state from MongoDB once per event: the child ACL is computed once per listing. On a 3000 event calendar, a PROPFIND `Depth: 1` goes from 21014 MongoDB commands (~5 s) down to 21 (~50 ms).
 - Same for address books: listing the cards of an address book no longer reads its public right and sharees once per card, and checking that a card exists no longer computes its ACL. On a 3000 card address book, a PROPFIND `Depth: 1` goes from 6018 MongoDB commands down to 20, an `addressbook-multiget` of 500 cards from 2508 down to 510.
 - The read access check of `calendar-multiget` and `addressbook-multiget` REPORTs looks up the requested hrefs in one query per collection instead of one query per href. A `calendar-multiget` of 500 events goes from 4004 MongoDB commands (~1 s) down to 20 (~60 ms), an `addressbook-multiget` of 500 cards down to 14.
 - Multi-get lookups of calendar objects and cards query at most 512 URIs at a time, so that a request carrying thousands of hrefs does not turn into a single query that could hit the database timeout.
 - MongoDB indexes are created once when the container starts (`scripts/create-indexes.php`) instead of on every HTTP request, which sent 14 `createIndexes` commands per request. The `SHOULD_CREATE_INDEX` setting is removed.
 - Add the missing MongoDB indexes: `calendarinstances { calendarid, share_href }` (the sharees and public right lookups of a calendar were collection scans), `sharedaddressbooks { addressbookid, share_href }` and `{ principaluri }`, `addressbooksubscriptions { principaluri }` and `{ source }`. Drop the redundant `calendarobjects { calendarid }` and `cards { addressbookid }`, covered by the `{ calendarid, uri }` and `{ addressbookid, uri }` indexes.

   Upgrade: the new indexes are created at startup. To drop the redundant ones, or if index creation fails at startup, run the `mongosh` script from [doc/storage/MONGO.md](doc/storage/MONGO.md#index-creation).

### Changes

 - **Behaviour change**: an LDAP infrastructure failure during Basic authentication (server down or unreachable, timeout, server busy/unavailable, service account bind failure, failing search) is now answered `503 Service Unavailable` instead of `401`, so that an outage is no longer mistaken for bad credentials. Only an answer of the directory about the credentials keeps returning `401`. LDAP operations now time out after `LDAP_TIMEOUT` seconds (default `5`) instead of hanging
 - **Behaviour change**: requests rejected by the Nginx rate limit are now answered `429 Too Many Requests` with `Retry-After: 1` and the CORS headers, instead of `503 Service Unavailable`. Clients and monitoring that treat 503 as a rate-limit signal must be updated, or set `NGINX_RATE_LIMIT_STATUS=503` to keep the previous status
 - **Behaviour change**: multi-tenant checks — the domain of the authenticated user is now verified on authentication, for principals, resources and CalDAV access. JWT tokens must carry the user's `mail` and technical tokens must carry a `domainId`; the mail returned by LDAP is validated. Tokens issued without them are rejected
 - ISSUE-353 Delegating a calendar or an address book to its own owner is rejected (#354)

### Bug Fixes

 - The bulk free/busy route (`POST /calendars/freebusy`) accepts an optional `timezone` (IANA identifier, `400` when unknown) in which floating dates and times are read. All-day events used to be reported busy from UTC midnight to UTC midnight, so that a booking page offered the first hours of a Paris owner's day off (linagora/twake-calendar-side-service#1200). Without `timezone`, UTC is used as before
 - Sharees (read, read-write, administration) can read the owner's calendar again (`calendarserver:delegatedsource`, e.g. `REPORT /calendars/{ownerId}/{calendarId}.json`): since #411 enforces `{DAV:}read` on JSON REPORTs, they got `403`. The owner's calendar and its events now grant `{DAV:}read`, and only read, to these sharees. PRIVATE / CONFIDENTIAL events stay anonymized for them. Free-busy sharees, declined and revoked shares and other users still get `403`
 - Deleting a calendar now deletes its events. They were left behind in `calendarobjects` because the deletion filtered on an `ObjectId` while the calendar id is stored as a string there. Existing orphans can be listed with `db.calendarobjects.aggregate([{ $lookup: { from: "calendars", let: { id: "$calendarid" }, pipeline: [{ $match: { $expr: { $eq: [{ $toString: "$_id" }, "$$id"] } } }], as: "c" } }, { $match: { c: [] } }, { $count: "orphans" }])`.
 - ISSUE-699 Expanding a single event over a time range (`REPORT` with `match.start` / `match.end` on an event) is answered `422` instead of `500` when the series generates more occurrences than `SABRE_MAX_RECURRENCES` before reaching the range, e.g. an open-ended daily event that started more than ten years before (#699)
 - ISSUE-404 Fix duplicated `DAV`/`X-Sabre-Version` headers in DAV responses — the nginx capability headers are now only emitted for the OPTIONS short-circuit, letting Sabre emit them once (with consistent casing) for proxied responses (#404)
 - ISSUE-320 Attendees keep their own local event properties (`VALARM`, `TRANSP`, `CLASS`) when the organizer updates the event (#341)
 - ISSUE-347 Scheduling no longer makes events deleted by an attendee re-appear (#350)
 - ISSUE-304 Delegation is restored when subscribing to a calendar (#345)
 - ISSUE-348 Moving an event to a path that is not a calendar no longer triggers a real-time event error (#349)

### Security

 - ISSUE-347 Writes to a calendar subscription are rejected when the user has no access to the source calendar (#351)
 - Simplified JWT token handling, removing a ReDoS in token parsing

## [2.1.0] - 2026-05-07

This release focusses on hardening asynchronous scheduling and various bug fixes.

No specific upgrade instructions.

### New Features

 - ISSUE-310 Domain admin can update public right and delegate domain address book (#315)
 - ISSUE-339 Add nginx `.well-known` route for CalDAV/CardDAV autodiscovery
 - SABRE-328 Emit real-time alarm events when event UID changes
 - SABRE-328 Harden real-time alarm propagation for recurring events
 - ISSUE-665 Performance: skip reply PARTSTAT propagation on large events above a configurable threshold (#336)
 - Nginx rate limiting support — configurable per-IP and global request rate limits

### Bug Fixes

 - ISSUE-300 Fix attendee removal from an overridden recurring occurrence not propagating to the organizer's copy (#331)
 - ISSUE-1208 Tighten iTIP sender/recipient authorization — reject scheduling messages where the sender does not match the organizer or an attendee (#326)
 - Prevent data race upon concurrent calendar creation
 - Correct LDAP connection lifetime to prevent connection leaks (Correct ldapcon lifetime)
 - AMQPSchedulePlugin: only flush AMQP messages upon successful schedule processing, preventing message loss on failure

### Dependency upgrades

 - Upgrade MongoDB driver to 2.2.1

### Documentation

 - Improve LDAP and MongoDB configuration documentation
 - Document RabbitMQ message format used by the AMQP schedule plugin

### Build

 - Publish pull request Docker images automatically to ease integration testing
 - Remove outdated packaging folder
