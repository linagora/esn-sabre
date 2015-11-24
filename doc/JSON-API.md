# ESN sabre/dav JSON API

The ESN Frontend for SabreDAV adds a JSON REST api for common operations from
the web frontend. All methods that return content will use the application/json
or application/hal+json format.

## Resources

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

## POST /calendars/{calendarHomeId}/{calendarId}.json

Query a specific calendar for events.
    
**Request JSON Object:**

A time range query object. The scope object within the query is not used for
this request, instead the specific calendar is queried.

**Status Codes:**

- 200 OK: Query has succeeded and results are returned
- 400 Bad Request: Missing keys in the request object

**Response:**

A dav:calendar resource, with the dav:item expanded.


## GET /calendars.json

List all calendar homes and calendars in the calendar root.

**Status Codes:**

- 200 OK: Query has succeeded and results are returned

**Response:**

A dav:root resource, expanded down to all dav:calendar resouces.


## GET /calendars/{calendarHomeId}.json

List all calendars in the calendar home.

**Status Codes:**

- 200 OK: Query has succeeded and results are returned

**Response:**

A dav:home resource, containing all dav:calendar resources in it.


## POST /calendars/{calendarHomeId}.json

Create a calendar in the specified calendar home.

**Request JSON Object:**

A dav:calendar object, with an additional member "id" which specifies the id to
be used in the calendar url.

**Status Codes:**

- 201 Created: The calendar has been created
- 400 Bad Request: Missing keys in the request object

## POST /addressbooks/{addressbookHomeId}.json

Create a addressbook in the specified addressbook home.

**Request JSON Object:**

A dav:addressbook object, with an additional member "id" which specifies the id to
be used in the addressbook url.

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

**Response:**

A dav:addressbook resource, with items expanded. The resource may also contain
a next link, if the offset/limit query parameters are used.
