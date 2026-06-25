# Alarm Scheduling Specification

## Scope and references

This document defines Twake Calendar behavior for scheduling `ACTION:EMAIL`
`VALARM` components between an organizer and event attendees.

This design follows the iCalendar model from
[RFC 5545 Section 3.6.1](https://www.rfc-editor.org/rfc/rfc5545#section-3.6.1)
and
[RFC 5545 Section 3.6.6](https://www.rfc-editor.org/rfc/rfc5545#section-3.6.6):
a `VEVENT` can contain multiple `VALARM` components, and an `ACTION:EMAIL`
`VALARM` can contain multiple `ATTENDEE` properties. RFC 9074 Section 4 extends
`VALARM` with an optional `UID`.

The key words **MUST**, **MUST NOT**, **SHOULD**, and **MAY** describe product
requirements. They do not redefine iCalendar RFC requirements.

## Feature flag

The complete behavior described in this document is guarded by:

```text
SABRE_EMAIL_VALARM_RECIPIENT_SCHEDULING=true
```

When the flag is enabled, esn-sabre MUST apply all rules in this document.

## Concepts

- **Source event**: the event stored in the organizer's calendar.
- **Attendee copy**: the scheduled event copy stored in one attendee's calendar.
- **Organizer-managed alarm**: an alarm coming from the organizer source event.
- **Personal alarm**: an alarm created only in an attendee copy by that calendar
  owner.
- **Current recipient**: the attendee receiving one iTIP scheduling message.

## Recipient projection

When the organizer schedules an event, each organizer-managed `ACTION:EMAIL`
`VALARM` MUST be copied only to event attendees explicitly listed in that
alarm's own `ATTENDEE` properties.

For each attendee copy, esn-sabre MUST:

1. Select organizer-managed email alarms listing the current recipient.
2. Keep each selected alarm as a separate `VALARM` component.
3. Remove every alarm `ATTENDEE` except the current recipient.
4. Omit alarms that do not list the current recipient.

The organizer source event MUST remain unchanged. Its `VALARM` components keep
their complete recipient lists.

The organizer MAY target himself in an organizer-managed email alarm by listing
his own mail address in the alarm `ATTENDEE` properties. The organizer MAY also
use a dedicated `VALARM` component for himself when his reminder trigger differs
from other attendees.

This prevents:

- an alarm addressed to Alice from being cloned to all event attendees;
- an attendee receiving an alarm only because they are listed at event level;
- organizer aliases or unrelated recipient addresses leaking into attendee
  copies.

Assume Bob is the organizer. The source event contains:

```ics
BEGIN:VALARM
UID:alarm-11111111-1111-4111-8111-111111111111
ACTION:EMAIL
DESCRIPTION:This is an event reminder
SUMMARY:Alarm notification
ATTENDEE:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
ATTENDEE:mailto:cedric@example.org
TRIGGER:-PT10M
END:VALARM
```

Alice's copy contains only Alice as alarm recipient:

```ics
BEGIN:VALARM
UID:alarm-11111111-1111-4111-8111-111111111111
ACTION:EMAIL
DESCRIPTION:This is an event reminder
SUMMARY:Alarm notification
ATTENDEE:mailto:alice@example.org
TRIGGER:-PT10M
END:VALARM
```

Cedric's copy contains the same alarm with only
`ATTENDEE:mailto:cedric@example.org`. Bob's source keeps all three recipients.

## Multiple alarms

A `VEVENT` MAY contain multiple `VALARM` components. Each `VALARM` is
independent and MUST be projected independently.

This supports different trigger times for different recipients:

```ics
BEGIN:VALARM
UID:alarm-22222222-2222-4222-8222-222222222222
ACTION:EMAIL
DESCRIPTION:This is an event reminder
SUMMARY:Alarm notification
ATTENDEE:mailto:alice@example.org
ATTENDEE:mailto:cedric@example.org
TRIGGER:-PT5M
END:VALARM
BEGIN:VALARM
UID:alarm-33333333-3333-4333-8333-333333333333
ACTION:EMAIL
DESCRIPTION:This is an event reminder
SUMMARY:Alarm notification
ATTENDEE:mailto:bob@example.org
TRIGGER:-PT10M
END:VALARM
```

Alice and Cedric receive the five-minute alarm projected to themselves. Bob
keeps both components in the source event, but only the alarm addressed to Bob is
effective for Bob as calendar owner.

If two different `VALARM` components both list Alice, Alice's copy MUST contain
both alarms. Two alarms at five and ten minutes are two intentional
notifications, not conflicting versions of one alarm.

## Alarm execution

Scheduling and execution are separate concerns:

- esn-sabre projects organizer-managed alarms into addressed attendee calendars;
- the alarm service evaluates a calendar object for its calendar owner;
- while evaluating the organizer source event, email addresses belonging to
  other event attendees MUST NOT be executed from the organizer's calendar;
- a non-attendee address, such as the organizer's personal alias, MAY be
  executed from the organizer's calendar when explicitly listed in the alarm.

Therefore, an alarm addressed to Alice is executed from Alice's attendee copy,
not once from Alice's copy and once again from Bob's source event.

## Organizer updates and merge

The organizer is authoritative for organizer-managed alarms. On organizer
updates, esn-sabre MUST replace the previous organizer-managed projection in
each attendee copy with the latest projection from the source event.

For each attendee and each event occurrence, the resulting alarm set is:

```text
latest organizer-managed alarms projected to this attendee
+ existing personal alarms created in this attendee copy
```

Update rules:

- adding an attendee to both the event and an alarm adds the projected alarm to
  that attendee copy;
- adding an attendee only to the event does not give that attendee an email
  alarm;
- adding a recipient to an existing alarm propagates that alarm to the new
  recipient;
- removing a recipient from an alarm removes the corresponding
  organizer-managed alarm from that attendee copy;
- changing an organizer-managed alarm, including its trigger, propagates the new
  value to every recipient still listed in the alarm;
- deleting an organizer-managed alarm removes its projections from attendee
  copies;
- personal alarms created in attendee copies MUST be preserved.

The merge MUST NOT keep an outdated organizer trigger instead of the new
organizer value. It also MUST NOT delete a separate personal alarm merely
because an organizer alarm has a similar action or trigger.

## Personal alarms

An attendee MAY create, update, or remove personal alarms on their own event
copy. A personal alarm:

- MUST remain local to that attendee's calendar;
- MUST NOT propagate to the organizer or other attendees;
- MAY target the attendee's primary address or another personal address;
- MUST be preserved when organizer-managed alarms are updated;
- when created by a Twake client, MUST use a UID different from every
  organizer-managed alarm on the event.

Editing an organizer-managed alarm and creating a personal alarm are different
operations. Twake clients SHOULD create a new `VALARM` with a new UID when a
user chooses a personal reminder instead of mutating the organizer-managed
component into a personal one.

## UID rules

`VALARM` UID is optional according to RFC 9074 Section 4. Twake Calendar accepts
alarms without UID for compatibility.

When `SABRE_EMAIL_VALARM_RECIPIENT_SCHEDULING` is enabled, esn-sabre MUST
generate a UID for every `VALARM` missing one before persisting and scheduling
the event. Generated UIDs MUST use the `alarm-{uuid}` format with a UUID v4
value. If the client already provides a UID, esn-sabre MUST preserve it.

UID provides stable identity for matching alarm updates. It does not express
ownership by itself:

- alarms projected from the organizer source are organizer-managed;
- alarms created only in an attendee copy are personal.

UID SHOULD be used to match an old organizer-managed alarm with its updated
version. Legacy UID-less alarms MAY be compared against previous and current
organizer projections, but identical UID-less organizer and personal alarms are
inherently ambiguous. Clients avoid that ambiguity by assigning a new UID to
personal alarms.

## Recurring events

Projection and merge rules apply independently to the master event and each
recurrence override. Matching MUST use the event occurrence identity so that an
alarm from one occurrence is not attached to another occurrence.
