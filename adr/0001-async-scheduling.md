# ADR-0001 — Async Scheduling via AMQP

## Current State

When an organizer (Bob) creates or updates an event with attendees (Alice, Cédric), the `ESN\CalDAV\Schedule\Plugin` intercepts the `calendarObjectChange` hook and **synchronously** propagates the event into each attendee's calendar via `scheduleLocalDelivery`. For each attendee, the plugin performs 4–5 MongoDB reads (principal resolution, home lookup, event search by UID, default calendar fetch, ACL check). In parallel, `ESN\CalDAV\Schedule\IMipPlugin` listens to the same `schedule` hook and performs the **same MongoDB reads** to publish email notifications on AMQP (`calendar:event:notificationEmail:send`), one message per attendee. For 100 attendees, this amounts to ~800–1000 sequential MongoDB queries in a single PHP thread with no parallelism possible. Bob's PUT response is not returned until all of this work is done. This architecture, inherited from SabreDAV and incrementally augmented, conflates identity resolution, persistence, local delivery, email notification, and business logic (Public Agenda, recurrence handling) into a progressively overloaded God Object.

---

## Trade-offs and Spec Divergences

| Topic | RFC / Legacy behaviour | New behaviour | Decision |
|---|---|---|---|
| `SCHEDULE-STATUS` in Bob's calendar | `1.2` (delivered) set synchronously after writing to Alice/Cédric's calendars | Stays at `1.0` (pending) permanently — the consumer does not report back | **Accepted** — OpenPaas clients do not read this field to drive their UI |
| PUT ↔ propagation atomicity | Bob's PUT only returns once all attendee calendars are written | PUT returns immediately; propagation is async — brief inconsistency window possible | **Accepted** — latency vs consistency trade-off; controlled clients only |
| RFC 6638 §7.1 | Server SHOULD propagate before returning the response | Propagation happens post-response | **Intentional divergence** — not applicable in this controlled deployment context |
| Delivery guarantee | Synchronous best-effort | At-least-once via RabbitMQ (retry + DLQ) | **Net improvement** — more reliable than the previous approach |

---

## Feature Flag — `AMQP_SCHEDULING_ENABLED`

Both the legacy behaviour and the new async behaviour are **retained simultaneously** in the codebase. The active path is selected at boot time via the environment variable `AMQP_SCHEDULING_ENABLED`.

| Value | Active plugins | Behaviour |
|---|---|---|
| `false` (default) | `ESN\CalDAV\Schedule\Plugin` + `ESN\CalDAV\Schedule\IMipPlugin` | Fully synchronous — current production behaviour, unchanged |
| `true` | `AMQPSchedulePlugin` + `MinimalIMipPlugin` | Async via AMQP — new behaviour described in this document |

Plugin registration in `esn.php`:

```php
// Calendar scheduling support
// NOTE: both branches require $AMQPPublisher, so this block moves inside
// the existing `if ($AMQPPublisher)` guard (currently around line 210).
// The legacy Plugin was registered outside that guard — this is intentional
// relocation, not an oversight.
if (getenv('AMQP_SCHEDULING_ENABLED') === 'true') {
    $server->addPlugin(new ESN\CalDAV\Schedule\AMQPSchedulePlugin($AMQPPublisher, $principalBackend));
} else {
    $server->addPlugin(new ESN\CalDAV\Schedule\Plugin($principalBackend));
}

// ... further down, still inside the $AMQPPublisher block ...

if (getenv('AMQP_SCHEDULING_ENABLED') === 'true') {
    $server->addPlugin(new ESN\CalDAV\Schedule\MinimalIMipPlugin($AMQPPublisher));
} else {
    $server->addPlugin(new ESN\CalDAV\Schedule\IMipPlugin($AMQPPublisher));
}
```

> **Placement note**: in the current `esn.php`, `ESN\CalDAV\Schedule\Plugin` is registered **outside** the `if ($AMQPPublisher)` block (line ~184) while `IMipPlugin` is **inside** it (line ~224). `AMQPSchedulePlugin` requires `$AMQPPublisher`, so both registrations must move inside the guard. Verify that `$AMQPPublisher` is always available when `AMQP_SCHEDULING_ENABLED=true` — a missing publisher should fail fast at boot.

The legacy `ESN\CalDAV\Schedule\Plugin` and `IMipPlugin` are **not modified** except for changing `fetchCalendarOwnerAddresses` visibility from `private` to `protected` in `Plugin`. This flag is the sole switching mechanism — no logic is shared or entangled between the two paths.

---

## New Architecture

### Overview

```text
PUT /calendars/bob/events/event.ics       DELETE /calendars/bob/events/event.ics
        │                                          │
        ▼                                          ▼
AMQPSchedulePlugin                        AMQPSchedulePlugin
.calendarObjectChange()                   .beforeUnbind()
  ├─ reads oldObject (1 MongoDB read)       ├─ reads current event
  ├─ computes iTIP messages via broker      ├─ generates CANCEL messages via broker
  └─ buffers recipients                     └─ flushDeliveries() → CANCEL on AMQP

AMQPSchedulePlugin.flushDeliveries()
  └─ publishes ONE AMQP message → calendar:itip:localDelivery
        │
        ▼
  Twake Calendar Side Service (Java)
  ├─ resolves principals (parallel)
  ├─ REQUEST/CANCEL → writes calendar + inbox
  ├─ REPLY → updates PARTSTAT in organizer's calendar only
  └─ publishes email notification if hasChange
```

> **All recipients included**: Sabre's `deliver()` emits the `schedule` hook for every recipient, local or external. `AMQPSchedulePlugin.scheduleLocalDelivery()` therefore buffers all of them. For local recipients the consumer delivers via ITIP (calendar write). For external recipients the ITIP call finds no local principal and skips the calendar write, but the consumer still publishes `calendar:event:notificationEmail:send` so the external attendee receives an email.

> `MinimalIMipPlugin` becomes a residual plugin handling only iTIP cases that arrive outside the standard PUT flow — specifically COUNTER messages received by email via the `ITipPlugin` `schedule` hook. It plays no role in the standard REQUEST/CANCEL/REPLY flow. Note: `ITipPlugin` also contains an intentional organizer-less REPLY fallback (`!$isConcerned && method === 'REPLY' && !$hasOrganizer && $senderIsAttendee`) for attendees replying to events without an ORGANIZER field; this is handled directly in `ITipPlugin` and is unrelated to `MinimalIMipPlugin`.

---

## AMQPSchedulePlugin

### Inheritance

`AMQPSchedulePlugin` extends **`ESN\CalDAV\Schedule\Plugin`** (not Sabre's directly) to inherit `fetchCalendarOwnerAddresses`, `processICalendarChange`, `shouldSkipUnchangedOccurrence`, and the Public Agenda logic. As a prerequisite, `fetchCalendarOwnerAddresses` must be changed from `private` to `protected` in `ESN\CalDAV\Schedule\Plugin`.

### Responsibilities

- Replaces `ESN\CalDAV\Schedule\Plugin` for the propagation path.
- Listens to `calendarObjectChange`: reads the previous state, computes the iTIP diff via `ITip\Broker`, **buffers** recipients instead of writing directly to calendars.
- Overrides `beforeUnbind` to buffer CANCEL recipients on DELETE and flush via AMQP — **without this override, the parent's synchronous `beforeUnbind` would fire and bypass the buffering**.
- At the end of each hook, publishes **a single AMQP message** containing all recipients.
- Sets `SCHEDULE-STATUS = 1.0` (pending) on each attendee property in Bob's vCal — semantically correct since delivery is asynchronous.
- Performs **no additional MongoDB reads** beyond the single `$oldObj` read already needed for the iTIP diff.

### Sample code

```php
<?php
namespace ESN\CalDAV\Schedule;

use ESN\Utils\Utils;
use Sabre\CalDAV\ICalendarObject;
use Sabre\CalDAV\Schedule\ISchedulingObject;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ITip;

/**
 * Extends ESN\CalDAV\Schedule\Plugin (not Sabre's directly) to inherit:
 *   - fetchCalendarOwnerAddresses()      [must be changed to protected]
 *   - processICalendarChange()
 *   - shouldSkipUnchangedOccurrence()
 *   - Public Agenda logic
 */
class AMQPSchedulePlugin extends Plugin {

    private $amqpPublisher;
    private $pendingDeliveries = [];  // keyed by "method|uid"
    private $currentOldMessage = null;

    public function __construct($amqpPublisher, $principalBackend = null) {
        parent::__construct($principalBackend);
        $this->amqpPublisher = $amqpPublisher;
    }

    /**
     * Buffers recipients for AMQP publish on PUT/POST.
     * Falls back to synchronous parent delivery on ITIP requests (from consumer)
     * to avoid an infinite loop: consumer → ITIP → scheduleLocalDelivery → AMQP → consumer → ...
     *
     * ITipPlugin calls this method directly (line 73):
     *   $this->server->getPlugin('caldav-schedule')->scheduleLocalDelivery($message)
     * so the loop prevention must live here, not in calendarObjectChange.
     */
    function scheduleLocalDelivery(ITip\Message $iTipMessage) {
        if ($this->server->httpRequest->getMethod() === 'ITIP') {
            // Consumer's ITIP call — delegate to parent (sync write to calendar/inbox)
            // EventRealTimePlugin will fire via the 'iTip' hook and publish real-time notifications
            parent::scheduleLocalDelivery($iTipMessage);
            return;
        }

        $key = $iTipMessage->method . '|' . $iTipMessage->uid;

        if (!isset($this->pendingDeliveries[$key])) {
            $this->pendingDeliveries[$key] = [
                'sender'     => $iTipMessage->sender,
                'method'     => $iTipMessage->method,
                'uid'        => $iTipMessage->uid,
                'message'    => $iTipMessage->message->serialize(),
                'hasChange'  => $iTipMessage->hasChange,
                'recipients' => [],
            ];
        }

        $this->pendingDeliveries[$key]['recipients'][] = $iTipMessage->recipient;

        // Exact '1.0' — short-circuits EventRealTimePlugin.schedule() (see Related Fix section)
        $iTipMessage->scheduleStatus = '1.0';
    }

    /**
     * calendarObjectChange hook — overridden to capture oldMessage and flush.
     */
    function calendarObjectChange(
        RequestInterface $request,
        ResponseInterface $response,
        VCalendar $vCal,
        $calendarPath,
        &$modified,
        $isNew
    ) {
        if ($request->getMethod() === 'ITIP' || !$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        if (PublicAgendaScheduleUtils::isPubliclyCreatedAndChairOrganizerNotAccepted($vCal)) {
            return;
        }

        $addresses = $this->fetchCalendarOwnerAddresses($calendarPath);

        $oldObj = null;
        $this->currentOldMessage = null;
        if (!$isNew) {
            $node = $this->server->tree->getNodeForPath($request->getPath());
            $this->currentOldMessage = $node->get();           // raw iCal string
            $oldObj = \Sabre\VObject\Reader::read($this->currentOldMessage);
        }

        $this->processICalendarChange($oldObj, $vCal, $addresses, [], $modified);

        $this->flushDeliveries();
    }

    /**
     * beforeUnbind hook — overridden to buffer CANCEL recipients on DELETE.
     *
     * IMPORTANT: without this override, the parent's synchronous beforeUnbind
     * would fire and write directly into attendee calendars, bypassing the AMQP
     * buffering entirely. The DELETE flow must go through flushDeliveries() too.
     */
    function beforeUnbind($path) {
        if ($this->server->httpRequest->getMethod() === 'MOVE') return;

        $node = $this->server->tree->getNodeForPath($path);

        if (!$node instanceof ICalendarObject || $node instanceof ISchedulingObject) {
            return;
        }

        if (!$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        list($calendarPath,) = Utils::splitEventPath('/' . $path);
        if (!$calendarPath) return;

        $addresses = $this->fetchCalendarOwnerAddresses($calendarPath);
        if (empty($addresses)) return;

        $broker = new ITip\Broker();
        $messages = $broker->parseEvent(null, $addresses, $node->get());

        foreach ($messages as $message) {
            $this->deliver($message);  // routes through scheduleLocalDelivery → buffer
        }

        $this->flushDeliveries();
    }

    /**
     * Publishes one AMQP message per group (method+uid) and resets the buffer.
     */
    private function flushDeliveries() {
        foreach ($this->pendingDeliveries as $delivery) {
            if (!empty($this->currentOldMessage)) {
                $delivery['oldMessage'] = $this->currentOldMessage;
            }
            $this->amqpPublisher->publish(
                'calendar:itip:localDelivery',
                json_encode($delivery)
            );
        }
        $this->pendingDeliveries  = [];
        $this->currentOldMessage  = null;
    }
}
```

### AMQP Payload — `calendar:itip:localDelivery`

```json
{
  "sender":      "mailto:bob@example.com",
  "method":      "REQUEST",
  "uid":         "abc-123-def-456",
  "message":     "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n...END:VCALENDAR",
  "oldMessage":  "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n...END:VCALENDAR",
  "hasChange":   true,
  "recipients": [
    "mailto:alice@example.com",
    "mailto:cedric@example.com"
  ]
}
```

| Field        | Type      | Description |
|--------------|-----------|-------------|
| `sender`     | `string`  | Organizer mailto URI |
| `method`     | `string`  | `REQUEST`, `CANCEL`, or `REPLY` |
| `uid`        | `string`  | Event UID |
| `message`    | `string`  | Serialized iCal — new state |
| `oldMessage` | `string?` | Serialized iCal — previous state (absent on creation) |
| `hasChange`  | `bool`    | `true` if the iTIP broker detected a significant change |
| `recipients` | `string[]`| List of attendee mailto URIs — local and external |

---

## MinimalIMipPlugin

### What is removed

The entire `schedule()` method and its private helpers:
- `checkPreconditions()` — principal resolution, mailto validation
- `getEventFullPath()` / `getEventObjectFromAnotherPrincipalHome()` — per-attendee MongoDB reads
- `splitItipMessageEvents()` / `computeModifiedEventMessages()` — diff logic delegated to the consumer
- `testIfEventIsExpired()` — delegated to the consumer
- `hasOwnSignificantChanges()` / `hasPropertyChanges()` / `hasAttendeesChanged()` — delegated to the consumer

### What remains

```php
<?php
namespace ESN\CalDAV\Schedule;

use Sabre\DAV;

/**
 * Residual plugin handling only cases outside the standard PUT flow:
 *  - COUNTER messages received by email via ITipPlugin (HTTP ITIP method)
 *
 * In the standard PUT/POST flow, AMQPSchedulePlugin handles all propagation
 * and notification. This plugin plays no role in that path.
 */
class MinimalIMipPlugin extends \Sabre\CalDAV\Schedule\IMipPlugin {

    protected $amqpPublisher;

    function __construct($amqpPublisher) {
        $this->amqpPublisher = $amqpPublisher;
    }

    function initialize(DAV\Server $server) {
        // Do not call parent::initialize() — we do not want to register
        // the Sabre parent's 'schedule' listener.
        $this->server = $server;
        // No listeners registered: this plugin is inactive in the standard flow.
        // Extend here to handle COUNTER if needed.
    }
}
```

---

## Twake Calendar Side Service — `calendar:itip:localDelivery`

### Design principle

**Twake Calendar Side Service** does **not** reimplement Sabre's scheduling logic. It delegates all iTIP processing back to Sabre via the existing `ITIP` HTTP method, which `ITipPlugin` already handles. The service acts as an AMQP-to-HTTP bridge with no MongoDB access and no iTIP broker (no REQUEST/CANCEL/REPLY routing, no PARTSTAT merging, no inbox writes — all of that is handled by Sabre). It does parse the iCalendar payloads in `message` and `oldMessage` to compute per-property diffs for the email notification step (see Phase 2b below).

Sabre's full stack fires for each ITIP call: principal resolution, calendar write, inbox write, ACL check, `EventRealTimePlugin` real-time notification — all handled as if the request came from an email gateway. The loop is prevented in `AMQPSchedulePlugin::scheduleLocalDelivery()` by detecting the `ITIP` method and delegating to the parent's synchronous delivery (see sample code above).

### Fan-out pattern

Messages arriving with N recipients are **not processed directly**. Twake Calendar Side Service first fans them out: it splits the message into N single-recipient messages and re-publishes each to the **same** `calendar:itip:localDelivery` exchange. Only messages with exactly **one recipient** are processed (ITIP call + email).

```text
calendar:itip:localDelivery { recipients: [alice, cedric, ... ] }   ← N recipients
        │
        ▼  Fan-out pass (re-publish, do not process)
        ├─ calendar:itip:localDelivery { recipients: [alice]  }     ← 1 recipient
        ├─ calendar:itip:localDelivery { recipients: [cedric] }     ← 1 recipient
        └─ ...
                │
                ▼  Processing pass (recipients.length === 1)
                ├─ POST /itip (impersonate recipient)
                └─ [hasChange] → publishNotificationEmail(...)
```

**Benefits of this pattern:**
- Retry and DLQ are **per recipient**, not per batch — one failing attendee does not block the others.
- Natural parallelism via queue consumers, no explicit Promise.all needed.
- Fan-out and processing can be scaled independently.

### Exact responsibilities

#### Phase 1 — Fan-out (recipients.length > 1)

For each recipient in `recipients[]`, re-publish to `calendar:itip:localDelivery` with the same payload but `recipients` reduced to that single entry. Ack the original message immediately after all re-publishes succeed.

```json
{
  "sender":     "mailto:bob@example.com",
  "method":     "REQUEST",
  "uid":        "abc-123",
  "message":    "BEGIN:VCALENDAR...",
  "oldMessage": "BEGIN:VCALENDAR...",
  "hasChange":  true,
  "recipients": ["mailto:alice@example.com"]
}
```

#### Phase 2 — Processing (recipients.length === 1)

**a. Local recipient — submit ITIP to Sabre** (impersonating the recipient)

```text
POST /itip
Authorization: Basic <recipient-credentials>
Content-Type: application/json

{
  "uid":       "<uid>",
  "sender":    "<sender email, without mailto:>",
  "recipient": "<recipient email, without mailto:>",
  "ical":      "<message iCal string>",
  "method":    "<REQUEST|CANCEL|REPLY>"
}
```

- `204` → success → proceed to email step.
- `400` → recipient not locally known (external attendee) → skip ITIP, proceed to email step.
- `5xx` → DLQ immediately.

**b. Email notification** (conditional)
- If `hasChange === true`:
  - Compute the diff between `oldMessage` and `message` (SUMMARY, LOCATION, DESCRIPTION, DTSTART, DTEND, attendees).
  - Determine `isNewEvent` for this recipient (absent from `oldMessage` attendees?).
  - Publish to `calendar:event:notificationEmail:send` (see payload spec section).
- Applies to **both local and external recipients** — external recipients skip ITIP but still receive email.

#### What Twake Calendar Side Service does NOT do

- Does **not** touch MongoDB directly.
- Does **not** reimplement iTIP logic (REQUEST/CANCEL/REPLY routing, inbox writes, PARTSTAT merging).
- Does **not** publish real-time WebSocket notifications — Sabre's `EventRealTimePlugin` fires on the ITIP call.
- Does **not** update `SCHEDULE-STATUS` in Bob's calendar. See Trade-offs section.

### Flow diagram

```text
Receives calendar:itip:localDelivery
        │
        ▼
recipients.length > 1 ?
        │ YES                              NO (single recipient)
        ▼                                  ▼
Re-publish N single-recipient      Is recipient local?
messages to same exchange           │ YES              NO (external)
Ack original.                       ▼                  ▼
                               POST /itip          skip ITIP
                                204 → ok            │
                                400 → skip          │
                                5xx → DLQ           │
                                    │               │
                                    └───────┬───────┘
                                            ▼
                                    [hasChange] → publishNotificationEmail(...)
                                    Ack message.
```

---

## Related Fix — `EventRealTimePlugin.schedule()` short-circuit

### Bug description

`EventRealTimePlugin` listens to the `schedule` hook at priority 101 (after `scheduleLocalDelivery`). It contains an early-return guard designed to skip real-time notification when the iTIP message has not yet been locally delivered:

```php
// EventRealTimePlugin.php:281
switch($iTipMessage->scheduleStatus) {
    case IMipPlugin::SCHEDSTAT_SUCCESS_PENDING:  // '1.0'
    case IMipPlugin::SCHEDSTAT_FAIL_TEMPORARY:   // '5.1'
    case IMipPlugin::SCHEDSTAT_FAIL_PERMANENT:   // '5.2'
        return false;
}
```

In `AMQPSchedulePlugin::scheduleLocalDelivery()`, if the status is set as:

```php
$iTipMessage->scheduleStatus = '1.0;Message queued for delivery';
```

the switch **does not match** (`'1.0;Message queued for delivery' !== '1.0'`), so `EventRealTimePlugin.schedule()` proceeds and performs **4–5 additional MongoDB reads per recipient** (principal resolution, calendar home fetch, event lookup by UID, calendar node fetch). For 100 attendees this means ~400–500 extra synchronous MongoDB reads — entirely negating the performance gain of the new architecture.

### Fix

Set the status to the bare code without description text:

```php
// AMQPSchedulePlugin::scheduleLocalDelivery()
$iTipMessage->scheduleStatus = '1.0';  // exact match — short-circuits EventRealTimePlugin
```

This causes `EventRealTimePlugin.schedule()` to return immediately for every recipient, reducing the MongoDB read count in the PUT path from **O(n)** to **O(1)**.

### Real-time notifications in the async world

With the short-circuit in place, `EventRealTimePlugin` no longer publishes WebSocket notifications for iTIP messages in the async path. This is correct — the event has not yet been written to the recipient's calendar when the PUT response is returned, so notifying the recipient's UI at that point would be premature.

Real-time notifications are **owned by the core** (`EventRealTimePlugin`), not by Twake Calendar Side Service. After `scheduleLocalDelivery` writes the recipient's calendar, `ITipPlugin::iTip()` emits the `'iTip'` server event (line 85), which `EventRealTimePlugin::itip()` catches. It reads the up-to-date event from the recipient's calendar and publishes the appropriate AMQP topics (`calendar:event:request`, `calendar:event:cancel`, etc.). The Side Service does not need to publish any real-time notification — Sabre handles it natively as part of the ITIP call.

### Performance summary

| Metric | Legacy (sync) | New (async, without fix) | New (async, with fix) |
|---|---|---|---|
| MongoDB reads / PUT (100 attendees) | ~900 | ~500 | **1** |
| AMQP publishes / PUT | 100 | 1 | **1** |
| PUT response time | O(n) | O(n) | **O(1)** |

---

## Agent prompt — Twake Calendar Side Service implementation

> **Context**: In the `esn-sabre` project (SabreDAV/PHP), we are replacing the synchronous CalDAV invitation propagation with an asynchronous consumer implemented in **Java** called **Twake Calendar Side Service**. The PHP plugin now publishes a single RabbitMQ message on the topic `calendar:itip:localDelivery` instead of writing directly into attendee calendars.
>
> **AMQP message payload** (`calendar:itip:localDelivery`):
> ```json
> {
>   "sender":     "mailto:bob@example.com",
>   "method":     "REQUEST",
>   "uid":        "abc-123-def-456",
>   "message":    "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n...END:VCALENDAR",
>   "oldMessage": "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n...END:VCALENDAR",
>   "hasChange":  true,
>   "recipients": [
>     "mailto:alice@example.com",
>     "mailto:cedric@example.com"
>   ]
> }
> ```
> - `oldMessage` is absent on event creation.
> - `recipients[]` contains **all attendees** — local (same Sabre instance) and external (different domain). The consumer distinguishes them: ITIP call for local (204 → calendar written), no ITIP for external; both receive a `calendar:event:notificationEmail:send` notification if `hasChange === true`.
> - `hasChange` is `true` if the iTIP broker detected a significant change (SUMMARY, LOCATION, DESCRIPTION, DTSTART, DTEND, attendees list).
>
> **Your task**: In the **Twake Calendar Side Service** (Java), implement a RabbitMQ listener that processes messages from `calendar:itip:localDelivery` using a **fan-out then process** pattern:
>
> **Phase 1 — Fan-out** (`recipients.length > 1`):
> Re-publish one message per recipient to the **same** `calendar:itip:localDelivery` exchange, each with `recipients` reduced to that single entry (all other fields identical). Ack the original message once all re-publishes succeed.
>
> **Phase 2 — Process** (`recipients.length === 1`):
> 1. If the recipient is local: submit an HTTP `ITIP` request to Sabre **impersonating the recipient**:
>    ```text
>    POST /itip
>    Authorization: Basic <recipient-credentials>
>    Content-Type: application/json
>    { "uid": "<uid>", "sender": "<sender, strip mailto:>", "recipient": "<recipient, strip mailto:>",
>      "ical": "<message verbatim>", "method": "<method>" }
>    ```
>    - `204` → success. `400` → recipient not locally known (external), skip ITIP. `5xx` → DLQ.
>    - Sabre handles calendar write, inbox, principal resolution, real-time notification natively.
> 2. If `hasChange === true`: publish to `calendar:event:notificationEmail:send` (see payload spec section below) for **both local and external recipients**.
>
> **Constraints**:
> - No direct MongoDB access — all persistence goes through Sabre's ITIP endpoint.
> - Tests must cover: fan-out of N-recipient message, REQUEST (new event, local), REQUEST (update, local), REQUEST (external — no ITIP, email only), CANCEL, REPLY, `hasChange=false` (no email), HTTP 400 (skip ITIP, email still sent), HTTP 5xx (DLQ).

---

## `calendar:event:notificationEmail:send` payload spec

This topic is consumed by the email notification service. It is published by **Twake Calendar Side Service** for **all recipients** (local and external) whenever `hasChange === true`. One message per recipient per VEVENT occurrence — recurring events must be split into individual per-occurrence messages before publishing.

```json
{
  "senderEmail":    "bob@example.com",
  "recipientEmail": "alice@example.com",
  "method":         "REQUEST",
  "event":          "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n...END:VCALENDAR",
  "notify":         true,
  "calendarURI":    "bob-calendar-uri",
  "eventPath":      "/calendars/alice-id/events/abc-123.ics",
  "isNewEvent":     true,
  "changes": {
    "summary":     { "previous": "Old title", "current": "New title" },
    "location":    { "previous": "Old room",  "current": "New room" },
    "description": { "previous": "Old desc",  "current": "New desc" },
    "dtstart": {
      "previous": { "isAllDay": false, "date": "2024-06-01 10:00:00.000000", "timezone_type": 3, "timezone": "Europe/Paris" },
      "current":  { "isAllDay": false, "date": "2024-06-01 11:00:00.000000", "timezone_type": 3, "timezone": "Europe/Paris" }
    },
    "dtend": {
      "previous": { "isAllDay": false, "date": "2024-06-01 11:00:00.000000", "timezone_type": 3, "timezone": "Europe/Paris" },
      "current":  { "isAllDay": false, "date": "2024-06-01 12:00:00.000000", "timezone_type": 3, "timezone": "Europe/Paris" }
    }
  }
}
```

| Field           | Type     | Present when  | Description |
|-----------------|----------|---------------|-------------|
| `senderEmail`   | `string` | always        | Organizer email, `mailto:` stripped |
| `recipientEmail`| `string` | always        | Attendee email, `mailto:` stripped — local or external |
| `method`        | `string` | always        | `REQUEST`, `CANCEL`, `REPLY`, `COUNTER` |
| `event`         | `string` | always        | Serialized iCal — **single VEVENT** (one occurrence per message) |
| `notify`        | `bool`   | always        | Always `true` |
| `calendarURI`   | `string` | always        | URI of the organizer's calendar (e.g. `events`) |
| `eventPath`     | `string` | local only    | Path of the event in the recipient's calendar — `/calendars/<recipientUserId>/<calendarUri>/<uid>.ics`. Omit for external recipients (no local calendar). |
| `isNewEvent`    | `bool`   | new attendee  | `true` if the recipient was not an attendee in `oldMessage` |
| `changes`       | `object` | update only   | Per-property diff — only changed properties included |
| `oldEvent`      | `string` | COUNTER only  | Serialized iCal of current state before the COUNTER proposal |

### `changes` field detail

Computed by diffing the `oldMessage` vs `message` fields from the `calendar:itip:localDelivery` payload.

- **String properties** (`summary`, `location`, `description`, `duration`):
  ```json
  { "previous": "<old value>", "current": "<new value>" }
  ```
- **Date properties** (`dtstart`, `dtend`):
  ```json
  {
    "previous": { "isAllDay": false, "date": "YYYY-MM-DD HH:mm:ss.000000", "timezone_type": 3, "timezone": "Europe/Paris" },
    "current":  { "isAllDay": false, "date": "YYYY-MM-DD HH:mm:ss.000000", "timezone_type": 3, "timezone": "Europe/Paris" }
  }
  ```
  `isAllDay` is `true` when the iCal property has no time component (DATE value type).

Only properties that actually changed are present in the `changes` object. An empty `changes` object means no tracked property changed (the change was on a non-tracked field such as attendee list).
