# RabbitMQ Messages

All messages are published as JSON. Each topic is used directly as the **exchange name**; the routing key is empty.

---

## CalDAV — Calendar events

### `calendar:event:created`
Published when a calendar event is created.

```json
{
  "eventPath": "/calendars/{userId}/{calendarUri}/{eventId}.ics",
  "event": "<iCalendar object>",
  "rawEvent": "<iCalendar string>",
  "import": false
}
```

### `calendar:event:updated`
Published when a calendar event is updated.

```json
{
  "eventPath": "/calendars/{userId}/{calendarUri}/{eventId}.ics",
  "event": "<iCalendar object>",
  "rawEvent": "<iCalendar string>",
  "import": false,
  "old_event": "<previous iCalendar object>"
}
```

### `calendar:event:deleted`
Published when a calendar event is deleted.

```json
{
  "eventPath": "/calendars/{userId}/{calendarUri}/{eventId}.ics",
  "event": "<iCalendar object>",
  "rawEvent": "<iCalendar string>"
}
```

### `calendar:event:request` / `calendar:event:reply` / `calendar:event:cancel`
Published during iTIP scheduling (local delivery).

```json
{
  "eventPath": "/calendars/{userId}/{calendarUri}/{eventId}.ics",
  "event": "<iCalendar object>",
  "rawEvent": "<iCalendar string>"
}
```

---

## CalDAV — Alarm events

Same message format as the corresponding event topics above.

| Exchange | Trigger |
|---|---|
| `calendar:event:alarm:created` | Event with alarm created |
| `calendar:event:alarm:updated` | Event with alarm updated |
| `calendar:event:alarm:deleted` | Event with alarm deleted |
| `calendar:event:alarm:request` | iTIP REQUEST with significant change |
| `calendar:event:alarm:cancel`  | iTIP CANCEL |

---

## CalDAV — Notification email

### `calendar:event:notificationEmail:send`
Published by `IMipPlugin` to request an invitation email be sent.

```json
{
  "senderEmail": "organizer@example.com",
  "recipientEmail": "attendee@example.com",
  "method": "REQUEST|REPLY|CANCEL|COUNTER",
  "event": "<iCalendar string>",
  "notify": true,
  "calendarURI": "events",
  "eventPath": "/calendars/{userId}/{calendarUri}/{eventId}.ics",
  "oldEvent": "<iCalendar string>",
  "isNewEvent": true,
  "changes": {
    "SUMMARY": ["old value", "new value"]
  }
}
```

`oldEvent`, `isNewEvent`, and `changes` are only present when applicable.

---

## CalDAV — Async iTIP local delivery

### `calendar:itip:localDelivery`
Published by `AMQPSchedulePlugin` so Twake Calendar Side Service can fan out iTIP messages to attendees.

```json
{
  "sender": "mailto:organizer@example.com",
  "method": "REQUEST|REPLY|CANCEL|COUNTER",
  "uid": "event-uid",
  "message": "<iCalendar string>",
  "hasChange": true,
  "recipients": ["mailto:attendee@example.com"],
  "calendarId": "calendar-id",
  "oldMessage": "<iCalendar string>"
}
```

`calendarId` and `oldMessage` are only present when applicable (COUNTER).

---

## CalDAV — Calendars

### `calendar:calendar:created` / `calendar:calendar:updated` / `calendar:calendar:deleted`

```json
{
  "calendarPath": "/calendars/{userId}/{calendarUri}",
  "calendarProps": {
    "{DAV:}displayname": "My Calendar",
    "{urn:ietf:params:xml:ns:caldav}calendar-description": "...",
    "{http://apple.com/ns/ical/}calendar-color": "#rrggbb",
    "{http://apple.com/ns/ical/}apple-order": "1"
  }
}
```

`calendarProps` is `null` for `deleted`. For sharing updates it contains `{"access": "..."}` or `{"delegation_updated": true}` or `{"public_right": "..."}`.

---

## CalDAV — Subscriptions

### `calendar:subscription:created` / `calendar:subscription:updated` / `calendar:subscription:deleted`

```json
{
  "calendarPath": "/calendars/{userId}/{subscriptionUri}",
  "calendarSourcePath": "/calendars/{sourceUserId}/{sourceCalendarUri}"
}
```

`calendarSourcePath` is only present on `deleted`.

---

## CalDAV — Room/resource events

| Exchange | Trigger |
|---|---|
| `resource:calendar:event:created` | iTIP REQUEST to a resource (significant change) |
| `resource:calendar:event:accepted` | Resource ATTENDEE replied ACCEPTED |
| `resource:calendar:event:declined` | Resource ATTENDEE replied DECLINED |

```json
{
  "resourceId": "{resourceId}",
  "eventId": "{uid or filename}",
  "eventPath": "/calendars/{resourceId}/{calendarUri}/{eventId}.ics",
  "ics": "<iCalendar string>"
}
```

---

## CardDAV — Address books

### `sabre:addressbook:created` / `sabre:addressbook:deleted`

```json
{
  "path": "addressbooks/{userId}/{addressbookUri}",
  "owner": "principals/users/{userId}"
}
```

### `sabre:addressbook:updated`

```json
{
  "path": "addressbooks/{userId}/{addressbookUri}"
}
```

---

## CardDAV — Contacts

### `sabre:contact:created` / `sabre:contact:updated`

```json
{
  "path": "addressbooks/{userId}/{addressbookUri}/{contactId}.vcf",
  "owner": "principals/users/{userId}",
  "carddata": "<contact-as-json>"
}
```

`carddata` is a contact object following [JSContact (RFC 9553)](https://www.rfc-editor.org/rfc/rfc9553).

### `sabre:contact:deleted`

```json
{
  "path": "addressbooks/{userId}/{addressbookUri}/{contactId}.vcf",
  "owner": "principals/users/{userId}",
  "carddata": "<contact-as-json>"
}
```

For subscribed address books, a second message is published with `sourcePath` added:

```json
{
  "path": "addressbooks/{subscriberUserId}/{addressbookUri}/{contactId}.vcf",
  "sourcePath": "addressbooks/{sourceUserId}/{sourceAddressbookUri}/{contactId}.vcf",
  "owner": "principals/users/{sourceUserId}",
  "carddata": "<contact-as-json>"
}
```

---

## CardDAV — Address book subscriptions

### `sabre:addressbook:subscription:created` / `sabre:addressbook:subscription:deleted`

```json
{
  "path": "addressbooks/{userId}/{subscriptionUri}",
  "owner": "principals/users/{userId}"
}
```

### `sabre:addressbook:subscription:updated`

```json
{
  "path": "addressbooks/{userId}/{subscriptionUri}"
}
```
