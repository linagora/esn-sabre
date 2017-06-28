# ESN sabre/dav JSON API

The ESN Frontend for SabreDAV adds a JSON REST api for common operations from
the web frontend. All methods that return content will use the application/json
or application/hal+json format.

## Resources

### iTip processing request object

Allows Sabre to process iTip messages.

```json
{
  "method": "REQUEST",
  "sender": "a@b.com",
  "recipient": "c@d.com",
  "uid": "the iTip UID",
  "recurrence-id": "the iTip RECURRENCE-ID, if any",
  "sequence": "0",
  "dtstamp": "the iTip DTSTAMP",
  "ical": "raw UTF-8 ical string"
}
```

### time range query object

Make a time range query on one or more calendars. The calendar scope may be
specified either as the complete relative url, the relative url excluding the
sabre/dav base uri. The calendar scope may or may not have the .json suffix
appended.

```json
{
    "scope": { "calendars": [ "/path/to/calendar", ... ] },
    "match": {
        "start": "19700101T010101",
        "end": "20160101T010101"
    }
}
```

### UID query object

Used to query an event by its UID.

```json
{
    "uid": "the event UID"
}
```

### dav:root

A calendar root collection, which contains the calendar homes.

```json
{
    "_links": { "self": { "href": "/calendars.json" } },
    "_embedded": { "dav:home": [ <dav:home resources>... ] }
}
```

### dav:home

A collection containing calendars.

```json
{
    "_links": { "self": { "href": "/calendars/{calendarHomeId}.json" } },
    "_embedded": { "dav:calendar": [ <dav:calendar resources>... ] }
}
```

### dav:calendar

Describes the properties of a caldav calendar. The list of items is optional
and may be omitted on some requests.

```json
{
    "_links": { "self": { "href": "/path/to/calendar.json" } },
    "_embedded": { "dav:item": [ <dav:item resources>... ] },
    "dav:name": "events",
    "caldav:description": null,
    "calendarserver:ctag": "http://sabre.io/ns/sync/122",
    "apple:color": "#FD8208FF",
    "apple:order": "1"
}
```

### dav:addressbook

Describes the properties of a carddav addressbook.

```json
{
    "_links": { "self": { "href": "/path/to/book.json" } },
    "_embedded": { "dav:item": [ <dav:item resources>... ] },
    "dav:syncToken": "sync-token"
}
```

### dav:item

This may be a calendar item or an addressbook card. The format used is
[jCal](https://tools.ietf.org/html/rfc7265) or
[jCard](https://tools.ietf.org/html/rfc7095), respectively.

## POST /query.json

Queries one or more calendars for events. The dav:calendar resources will
include their items.

**Request JSON Object:**

A time range query object, which must define a scope of calendars to be
queried.

**Status Codes:**

- 200 OK: Query has succeeded and results are returned
- 400 Bad Request: Missing keys in the request object

**Response:**

```json
{
  "_links": { "self": { "href": "/query.json" } },
  "_embedded": { "dav:calendar": [ <dav:calendar resources>... ] }
}
```

## REPORT /calendars/{calendarHomeId}/{calendarId}.json

Query a specific calendar for events.

**Request JSON Object:**

A time range query object. The scope object within the query is not used for
this request, instead the specific calendar is queried.

```json
{
    "match": {
      "start": "20160606T000000",
      "end": "20160613T000000"
    }
}
```

**Status Codes:**

- 200 OK: Query has succeeded and results are returned
- 400 Bad Request: Missing keys in the request object

**Response:**

A dav:calendar resource, with the dav:item expanded.

## REPORT /calendars/{calendarHomeId}

Query all calendars of the given _home_ for an event with a given UID.

**Request JSON Object:**

A UID query object.

```json
{
    "uid": "the event UID"
}
```

**Status Codes:**

- 200 OK: Query has succeeded and results are returned
- 400 Bad Request: Missing keys in the request object
- 404 Not Found: No event with the given UID found

**Response:**

A dav:calendar resource, with the dav:item expanded.

## POST /calendars/{calendarHomeId}/{calendarId}.json

Define sharees (add or remove) on a particular calendar.

**Request JSON Object:**

```json
{
    "share": {
      "set": [
        {
          "dav:href": "mailto:joe@example.org",
          "common-name": "Joe Shmoe",
          "summary": "something",
          "dav:read-write": true
        }
      ],
      "remove": [
        {
          "dav:href": "mailto:jane@example.org"
        }
      ]
    }
}
```

**Status Codes:**

- 200 OK: Query has succeeded
- 400 Bad Request: Missing keys in the request object

## GET /calendars.json

List all calendar homes and calendars in the calendar root.

**Status Codes:**

- 200 OK: Query has succeeded and results are returned

**Response:**

A dav:root resource, expanded down to all dav:calendar resouces.


## GET /calendars/{calendarHomeId}.json

List all calendars in the calendar home.

**Query Parameters:**

- sharedPublic: List only personal calendar that have public_right
- personal: include only personal calendars in the result list (can be combined with `sharedPublicSubscription` and `sharedDelegationStatus`)
- sharedPublicSubscription: include only subscritions to public calendars from the result list (can be combined with `personal` and `sharedDelegationStatus`)
- sharedDelegationStatus: include only shared calendars with the given invite status (`accepted` or `noresponse`) (can be combined with `personal` and `sharedPublicSubscription`)

**Status Codes:**

- 200 OK: Query has succeeded and results are returned

**Response:**

A dav:home resource, containing all dav:calendar resources in it.


## GET /calendars/{calendarHomeId}/{calendarId}.json

Return information about a calendar

**Status Codes:**

- 200 OK: Query has succeeded and results are returned

**Response:**

A dav:calendar resources.


## POST /calendars/{calendarHomeId}.json

Create a calendar in the specified calendar home.

**Request JSON Object:**

A dav:calendar object, with an additional member "id" which specifies the id to
be used in the calendar url.

**Status Codes:**

- 201 Created: The calendar has been created
- 400 Bad Request: Missing keys in the request object

## GET /addressbooks/{addressbookHomeId}.json

List all addressbooks in the addressbookHome.

**Status Codes:**

- 200 OK: Query has succeeded and results are returned
- 404 NOT FOUND: addressbookHomeId is not correct

**Response:**

```json
{
    "_links":{"self":{"href":"/addressbooks/55f811e843f181db51af9a67.json"}},
    "_embedded":{
        "dav:calendar":
        [
            {"_links":{"self":{"href":"/addressbooks/55f811e843f181db51af9a67/readonly01.json"}},"dav:name":"","carddav:description":"","dav:acl":["dav:read"],"type":"social"}
        ]
    }
}
```

## POST /addressbooks/{addressbookHomeId}.json

Create a addressbook in the specified addressbook home.

**Request JSON Object:**

A dav:addressbook object, with an additional member "id" which specifies the id to
be used in the addressbook url and "privilege" which determines the addressbook's privilege

```json
{
    "id": "ID",
    "dav:name" :"NAME",
    "carddav:description": "DESCRIPTION",
    "dav:acl": ["dav:read", "dav:write"],
    "type": "social"
}
```

**Status Codes:**

- 201 Created: New addressbook has been created
- 400 Bad Request: Missing keys in the request object


## GET /addressbooks/{addressbookHomeId}/{addressbookId}.json

List all contacts in the addressbook.

**Status Codes:**

- 200 OK: Query has succeeded and results are returned

**Query Parameters:**

- offset: List offset for the contacts returned
- limit: The number of contacts to be returned
- sort: The column to sort by, e.g. "fn"
- modifiedBefore: Timestamp in seconds, to list contacts modified before a specificed time

**Response:**

A dav:addressbook resource, with items expanded. The resource may also contain
a next link, if the offset/limit query parameters are used.


## PROPFIND /addressbooks/{addressbookHomeId}/{addressbookId}.json

List all properties of the addressbook.

**Status Codes:**

- 200 OK: Query has succeeded and results are returned

**Request JSON Object:**

An object with an array member "property" which specifies list of property of the addressbook.

**Response:**

A json resource containing the values of all requested properties

```json
{
  "{urn:ietf:params:xml:ns:carddav}addressbook-description": "description",
  "{DAV:}acl": ['dav:read', 'dav:write'],
  "{http://open-paas.org/contacts}type": "social"
}
```

## PROPFIND /calendars/{calendarHomeId}/{calendarHomeId}.json

List asked properties about a calendar. For the moment only 'cs:invite' and 'acl' properties are supported
**Status Codes:**

- 200 OK: Query has succeeded and results are returned

**Request JSON Object:**

An object with an array member "prop" which specifies list of wanted property of the calendar.

```json
{
  "prop": [
    "cs:invite",
    "acl"
   ]
}
```

**Response:**

A json resource containing the values of all requested properties

```json
{
  "invite": [
    {
      "href": "principals/users/54b64eadf6d7d8e41d263e0f"
      ...
    }
  ]
  "acl": [
    {
      "privilege": "{DAV:}share"
      "principal": "principals/users/54b64eadf6d7d8e41d263e0f"
      "protected": 1
    },
    ...
  ]
}
```

## ACL /calendars/{calendarHomeId}/{calendarId}

Sets the public access privilege on a calendar.

**Request JSON Object:**

```json
{
  "public_right": "{DAV:}read" //any supported DAV acl privilege for a calendar
}
```

**Status Codes:**

- 200 Ok
- 400 Bad Request: Request payload is badly formatted
- 404 Not Found: Calendar has not been found
- 412 Precondition Failed: Privilege in the payload is not supported

**Response:**

```json
[
  {
    "privilege": "{DAV:}read",
    "principal": "principals/users/54b64eadf6d7d8e41d263e0f"
  },
  {
    "privilege": "{DAV:}read",
    "principal": "{DAV:}authenticated"
  },
  ...
]
```

## ITIP /calendars/{calendarHomeId}

Requests that Sabre processes a iTip message.

**Request JSON Object:**

An iTip processing request object.  
All keys in the request object except `method` (defaults to _REQUEST_) and `sequence` (defaults to _0_) are required.

```json
{
  "method": "REQUEST",
  "sender": "a@b.com",
  "recipient": "c@d.com",
  "uid": "the iTip UID",
  "recurrence-id": "the iTip RECURRENCE-ID, if any",
  "sequence": "0",
  "dtstamp": "the iTip DTSTAMP",
  "ical": "raw UTF-8 ical string"
}
```

**Status Codes:**

- 204 No Content: Query has succeeded
- 400 Bad Request: Missing keys in the request object

**Response:**

None.
