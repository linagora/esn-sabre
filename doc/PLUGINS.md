# Plugin inventory

This page aims at documenting plugins that are poart of esn-sabre as well as their motivations.

## Real time plugins

Those plugin publishes RabbitMQ messages upon occurence of specific actions performed on top of the DAV server.

Those plugins simply publishes events onto pre-existing exchanges. The side service provisions the exchanges upon start.

### ESN\Publisher\CalDAV\CalendarRealTimePlugin

This publishes events upon `calendar` updates.

Exhchanges:

 - `calendar:calendar:created` is notified upon calendar creation
 - `calendar:calendar:updated` is notify on calendar property updates, public right update or upon sharee updates
 - `calendar:calendar:deleted` is notified upon calendar deletion

### ESN\Publisher\CalDAV\EventRealTimePlugin

This publishes event relates changes to RabbitMQ.

The following exchanges are used:

 - Event CRUD, used by the side service to maintain event search indexes:
   - `calendar:event:created` upon event creation
   - `calendar:event:updated` upon event changes
   - `calendar:event:deleted` upon event deletion

 - Scheduling related changes, used by the side service to maintain attendee indexation
   - `calendar:event:request` upon invitation of a sharee to an event
   - `calendar:event:reply` if the sharee reacts to the event
   - `calendar:event:cancel` owner cancels the event

 - Resource relates changes, used by the side service to trigger resource moderation flow
   - `resource:calendar:event:created` an event ask for a resource to join
   - `resource:calendar:event:accepted` the resource administrator accepted
   - `resource:calendar:event:declined` the resource administrator declined

 - Alarm related changes, used by the side service to schedule alarm
   - `calendar:event:alarm:created`
   - `calendar:event:alarm:updated`
   - `calendar:event:alarm:deleted`
   - `calendar:event:alarm:request`
   - `calendar:event:alarm:cancel`

### ESN\Publisher\CalDAV\SubscriptionRealTimePlugin

This publishes subscription relates changes to RabbitMQ. (currently unused)

Exhchanges:
 - `calendar:subscription:created`
 - `calendar:subscription:updated`
 - `calendar:subscription:deleted`

### ESN\Publisher\CardDAV\AddressBookRealTimePlugin

This publishes events upon `addressBook` updates.

Exhchanges:

 - `sabre:addressbook:created`
 - `sabre:addressbook:deleted`
 - `sabre:addressbook:updated`

### ESN\Publisher\CardDAV\ContactRealTimePlugin

This exchange publishes changes related to contact, used by Twake mail in order to maintain the auto-complete database.

Exchanges:
 - `sabre:contact:created`
 - `sabre:contact:updated`
 - `sabre:contact:deleted`

### ESN\Publisher\CardDAV\SubscriptionRealTimePlugin

This publishes subscription relates changes to RabbitMQ. (currently unused)

 - `sabre:addressbook:subscription:created`
 - `sabre:addressbook:subscription:updated`
 - `sabre:addressbook:subscription:deleted`

## Logging

### ESN\Log\RequestLoggerPlugin

Logs incoming request and outgoing responses.

Set `SABRE_ENV=dev` environment variable on order to enable this plugin.

### ESN\ExceptionLoggerPlugin

Log exception via monolog. Always enabled.

## JSON

### ESN\JSON\Plugin

Provide a convenience JSON API plugin.

JSON API is [described here](JSON-API.md)

### ESN\JSON\FreeBusyPlugin

Provides free-busy feature allowing to check availability of other users.

Free-busy API is  currently undocumented.

## DAV

### ESN\DAV\Auth\Backend\Esn

This plugin handle:

 - Basic auth via impersonation using `SABRE_ADMIN_LOGIN` and `SABRE_ADMIN_PASSWORD` variables following the 
 `{SABRE_ADMIN_LOGIN}&{username}:{SABRE_ADMIN_PASSWORD}` pattern.
  Please note that the Side service leverage this impersonation to expose an OpenID connect authenticated endpoint that proxies requests to Sabre.
 - Basic auth against a LDAP. The authentication result (mail address) is then consolidated agsainst the side service in order
 to retieve the corresponding principalId.
 - Authentication via JWT token, which is still relied upon by legacy OpenPaaS SPAs (deprecated). This token can be generated on the side
 service by calling `/token` endpoint.
 - Authentication via OpenPaaS `ESNToken`. Used by OpenPaaS to perform automated actions like consolidating `domain member address book`. (deprecated)
 - Authentication via `TwakeCalendarToken`. Used by the side service to perform automated actions like consolidating `domain member address book`. 


### ESN\DAV\CorsPlugin

Set up CORS in order to enable being called by third party.

Deprecated: to be replaced by NGINX configuration.

### ESN\DAV\XHttpMethodOverridePlugin

Allow using `X-HTTP-OVERRIDE` header as a substiture for HTTP method.

This allows working around browser limitations.

### ESN\DAV\Sharing\Plugin

Simply holds method but plugs on no server-events.

## CalDAV

### ESN\CalDAV\Backend\Esn

Triggers calendar auto-provisionning of the default calendar upon first connection.

### ESN\CalDAV\Backend\Mongo

MongoDB backend for CalDAV

### ESN\CalDAV\Schedule\ImipPlugin

Send order to send IMIP related emails to the side service.

Exchange: `calendar:event:notificationEmail:send`

### ESN\CalDAV\Schedule\ItipPlugin

Called by the mail service to notify Sabre of incoming events / scheduling changes.

ITIP follows the JSON API [described here](JSON-API.md)

Deserializes JSON and triggers the similar ITIP event compared to regular sabre/dav flow.

### ESN\CalDAV\Schedule\Plugin

Relevant comment: class is copied in order to work around a limitation: using currently
authenticated user is not necessarily the owner when sharing.

### ESN\CalDAV\ImportPlugin

Adds a `?import` query parameter to overwrite pre-existing event if the URI is the same.

### ESN\CalDAV\MobileRequestPlugin

DEPRECATED

Modify shared calendar name in order to match owner.

Buggy: it always set to the current user.

### ESN\CalDAV\ParticipationPlugin

Work around https://ci.linagora.com/linagora/lgs/openpaas/linagora.esn.calendar/-/issues/1175

When a user accepts a recurring event with exception, exceptions are also accepted. (Similar to google calendar / exchange)

### ESN\CalDAV\TextPlugin

CF https://ci.linagora.com/linagora/lgs/openpaas/linagora.esn.calendar/-/issues/1175

Attempt to fix shared calendar with no event bug.

Implements a simpler method to get calendar en events for IOS.

## DAVACL

### ESN\DAVACL\PrincipalBackend\Mongo

Allows resolving principals against MongoDB by reading the side service DB.

### ESN\DAVACL\Plugin

CF https://ci.linagora.com/linagora/lgs/openpaas/esn-sabre/-/issues/54

This is a bugfix to prevent displaying global addressbook twice.

## CardDAV

### ESN\CardDAV\Backend\Esn

Preprovisionning for user default addressbook if missing.

### ESN\CardDAV\Backend\Mongo

MongoDB storage backend for CardDAV.

### ESN\CardDAV\Sharing\ListenerPlugin

If an adress book is shared, this plugin ensures that upon removal of the owner address book, that sharee subscriptions are deleted accordingly.

### ESN\CardDAV\Sharing\Plugin

Similar to `Sabre\CalDAV\SharingPlugin` but for CardDAV as this plugin do not exist (yet) in Sabre.

This plugin implements HTTP requests and properties related to: draft-pot-webdav-resource-sharing

### ESN\CardDAV\Subscriptions\Plugin

Similar to `Sabre\CalDAV\Subscriptions\Plugin` but for CardDAV as this plugin do not exist (yet) in Sabre.

This plugin adds addressbook-subscription support to your CardDAV server.

Some clients support 'managed subscriptions' server-side. This is basically
a list of subscription urls a user is using.

Currently limited to the JSON API support.

### ESN\CardDAV\MobileRequestPlugin

DEPRECATED

Modify shared calendar name in order to match owner.

Buggy: it always set to the current user.

### ESN\CardDAV\Plugin

Alternative implementation to `Sabre\CardDAV\Plugin` which it replaces, supposedly adding subscription support.

Likely buggy.