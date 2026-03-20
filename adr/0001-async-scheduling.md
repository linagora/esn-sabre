# ARD-0001 — Async Scheduling via AMQP

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

```
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
  Consumer (Node/Go)
  ├─ resolves principals (parallel)
  ├─ REQUEST/CANCEL → writes calendar + inbox
  ├─ REPLY → updates PARTSTAT in organizer's calendar only
  └─ publishes email notification if hasChange
```

> **Local recipients only**: Sabre only calls `scheduleLocalDelivery` for recipients hosted on this server. External attendees (different domain) do not go through this path and are out of scope for this consumer.

> `MinimalIMipPlugin` becomes a residual plugin handling only cases not covered by the consumer (e.g. COUNTER messages received by email via `ITipPlugin`). It no longer plays any role in the standard PUT flow.

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
     * Replaces synchronous local delivery with a buffered AMQP publish.
     * Called once per iTip\Message (= once per recipient).
     */
    function scheduleLocalDelivery(ITip\Message $iTipMessage) {
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

        // Honest: delivery is queued, not yet performed
        $iTipMessage->scheduleStatus = '1.0;Message queued for delivery';
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
| `recipients` | `string[]`| List of attendee mailto URIs (local users only) |

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

## External Consumer — `calendar:itip:localDelivery`

### Overview

The consumer is a standalone service (Node.js, Go, or equivalent) listening on the RabbitMQ queue `calendar:itip:localDelivery`. It is the sole owner of attendee calendar propagation. It can process recipients **in parallel**, which PHP cannot do efficiently in a single request thread.

### Exact responsibilities

#### 0. Routing by `method`

- `REQUEST` → create or update the event in each recipient's calendar (attendees).
- `CANCEL` → delete or mark the event as CANCELLED in each recipient's calendar.
- `REPLY` → the recipient is **the organizer** (Bob); update only the PARTSTAT of the replying attendee in Bob's calendar. Do not write to any other attendee's calendar.

#### 1. Per recipient (in parallel)

**a. Principal resolution**
- Query MongoDB: find the principal matching the recipient email address.
- If not found → log and skip (equivalent to legacy `SCHEDULE-STATUS 3.7`).

**b. Calendar target resolution**
- Fetch `schedule-default-calendar-URL` and `schedule-inbox-URL` for the principal.
- Look up the existing event by UID in the principal's `calendar-home-set`.

**c. iTIP message processing**
- Instantiate `ITip\Broker` and call `processMessage(iTipMessage, currentObject)`.
- `currentObject` = the existing event if found, `null` for a new event.

**d. Calendar write**
- If new (event did not exist): `calendar.createFile(newFileName, newObject.serialize())`.
- Otherwise: `objectNode.put(newObject.serialize())`.

**e. Inbox write** (conditional)
- Create an inbox entry **only** if `hasChange === true` or the event is new for this recipient.
- Skip inbox for PARTSTAT-only updates (`hasChange === false`) — optimization already present in the legacy code.

**f. Email notification** (conditional)
- If `hasChange === true` and the recipient is not a resource:
  - Compute the diff (SUMMARY, LOCATION, DESCRIPTION, DTSTART, DTEND, attendees) between `oldMessage` and `message`.
  - Determine `isNewEvent` for this specific recipient (was they already an attendee in `oldMessage`?).
  - Publish to `calendar:event:notificationEmail:send` with the standard payload.

#### 2. Error handling

- Retry with exponential backoff on transient MongoDB errors.
- Dead-letter queue for permanently undeliverable messages.
- Log the final per-recipient delivery outcome for observability.

#### 3. What the consumer does NOT do

- Does **not** update `SCHEDULE-STATUS` in Bob's calendar — it stays at `1.0` (pending) permanently. See Trade-offs section.
- Does **not** process external recipients (different domain) — they are not present in `recipients[]`.
- Does **not** enforce CalDAV ACL privileges — trust is granted to AMQP messages originating from Sabre.

### Consumer flow diagram

```
Receives calendar:itip:localDelivery
        │
        ▼
Route by method (REQUEST / CANCEL / REPLY)
        │
        ▼
For each recipient (Promise.all / goroutines)
        │
        ├─ getPrincipalByEmail(recipient)
        │         └─ NotFound → skip + log
        │
        ├─ getCalendarProperties(principal)
        │         └─ inbox, defaultCalendar, homeSet
        │
        ├─ getExistingEvent(homeSet, uid)
        │         └─ null if new event
        │
        ├─ broker.processMessage(iTipMessage, existingEvent)
        │
        ├─ writeCalendar(defaultCalendar, newEvent)
        │
        ├─ [hasChange || isNew] → writeInbox(inbox, iTipMessage)
        │
        └─ [hasChange && !isResource] → publishNotificationEmail(...)
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

**The consumer is responsible for publishing real-time notifications** after writing each recipient's calendar. It should publish to the appropriate topics (`calendar:event:request`, `calendar:event:cancel`, etc.) once the write is confirmed. This responsibility must be added to the consumer implementation (see Agent prompt below).

### Performance summary

| Metric | Legacy (sync) | New (async, without fix) | New (async, with fix) |
|---|---|---|---|
| MongoDB reads / PUT (100 attendees) | ~900 | ~500 | **1** |
| AMQP publishes / PUT | 100 | 1 | **1** |
| PUT response time | O(n) | O(n) | **O(1)** |

---

## Agent prompt — consumer implementation

> **Context**: In the `esn-sabre` project (SabreDAV/PHP), we are replacing the synchronous CalDAV invitation propagation with an asynchronous AMQP consumer. The PHP plugin now publishes a single RabbitMQ message on the topic `calendar:itip:localDelivery` instead of writing directly into attendee calendars.
>
> **Your task**: Design and implement a consumer (Node.js with `amqplib`, or Go with `amqp091-go`) that:
> 1. Consumes messages from `calendar:itip:localDelivery` (see payload spec in `adr/0001-async-scheduling.md`).
> 2. For each recipient in `recipients[]`, in parallel:
>    - Resolves the principal via MongoDB (collection `principals`, lookup by email).
>    - Finds the existing event by UID in the principal's `calendar-home-set`.
>    - Applies the iTIP message (RFC 5546 logic: REQUEST creates/updates, CANCEL deletes, REPLY updates PARTSTAT).
>    - Writes the event to the recipient's default calendar (MongoDB, collection `calendarObjects`).
>    - If `hasChange === true` or new event: writes to inbox (collection `schedulingObjects`).
>    - If `hasChange === true` and recipient is not a resource: publishes to `calendar:event:notificationEmail:send` with the diff computed between `oldMessage` and `message`.
> 3. Handles errors with retry/backoff and dead-letter queue.
>
> **Constraints**:
> - MongoDB schema is the one used by `esn-sabre` — read `lib/CalDAV/Backend/Mongo.php` to understand collection structure.
> - iTIP processing logic (processMessage) can be ported from `vendor/sabre/vobject/lib/ITip/Broker.php` or implemented using an existing iCalendar library.
> - Tests must cover: creation, update, cancellation, PARTSTAT-only (no inbox write), new attendee on existing event.
