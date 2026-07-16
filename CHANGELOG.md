# Changelog
All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/en/1.0.0/)

## [Unreleased]

### New Features

 - ISSUE-425 Auto-provision users upon a DAV request — when an LDAP or impersonated user authenticates successfully but has no entry in the `users` collection yet, the entry is created on the fly (following the twake-calendar-side-service document format) instead of returning a `401`. Gated by the `AUTO_PROVISION` env var (default `true`). Needed upon migrations (#425)

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
