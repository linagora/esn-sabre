# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)

## [Unreleased]

### New Features

 - ISSUE-437 Expose video conference links through the standard RFC 7986 `CONFERENCE` property — events carrying `X-OPENPAAS-VIDEOCONFERENCE` are decorated upon `PUT` (and upon iTIP delivery) so that external clients (Apple Calendar, iOS, Outlook, ...) display a join button. `X-OPENPAAS-VIDEOCONFERENCE` is kept for Twake clients, and is conversely derived from the `CONFERENCE` property of events created by external clients (#437)
 - Every runtime setting can now be configured from `config.json` instead of the process environment. The `environment` section of `config.json` takes precedence, the process environment is still honoured as a fallback, and the built-in default applies last, so existing deployments keep working untouched. `scripts/generate_config.sh` materializes the whole section, `config.json.default` lists every key with its default value, and [doc/CONFIGURE.md](doc/CONFIGURE.md) documents all of them.
 - ISSUE-425 Auto-provision users upon a DAV request — when an LDAP or impersonated user authenticates successfully but has no entry in the `users` collection yet, the entry is created on the fly (following the twake-calendar-side-service document format) instead of returning a `401`. Gated by the `AUTO_PROVISION` env var (default `true`). Needed upon migrations (#425)

### Performance

 - Listing a calendar (PROPFIND `Depth: 1`, `sync-collection`, `calendar-multiget`) no longer reads the calendar sharing state from MongoDB once per event: the child ACL is computed once per listing. On a 3000 event calendar, a PROPFIND `Depth: 1` goes from 21014 MongoDB commands (~5 s) down to 21 (~50 ms).
 - Same for address books: listing the cards of an address book no longer reads its public right and sharees once per card, and checking that a card exists no longer computes its ACL. On a 3000 card address book, a PROPFIND `Depth: 1` goes from 6018 MongoDB commands down to 20, an `addressbook-multiget` of 500 cards from 2508 down to 510.

### Bug Fixes

 - ISSUE-404 Fix duplicated `DAV`/`X-Sabre-Version` headers in DAV responses — the nginx capability headers are now only emitted for the OPTIONS short-circuit, letting Sabre emit them once (with consistent casing) for proxied responses (#404)

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
