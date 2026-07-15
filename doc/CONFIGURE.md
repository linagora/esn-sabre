## ENV variables

The Docker image relies on ENV variables to generate its configuration through [this script](../scripts/generate_config.sh).

The following ENV variables needs to be set:

 - SABRE_MONGO_HOST
 - SABRE_MONGO_PORT
 - SABRE_MONGO_DBNAME
 - ESN_MONGO_HOST
 - ESN_MONGO_PORT
 - ESN_MONGO_DBNAME
 - MONGO_TIMEOUT
 - ESN_HOST
 - ESN_PORT
 - AMQP_HOST
 - AMQP_PORT
 - AMQP_LOGIN
 - AMQP_PASSWORD
 - SABRE_ENV

 Additionally LDAP setup is done solely by ENV variables:

 - LDAP_ADMIN_DN
 - LDAP_ADMIN_PASSWORD
 - LDAP_BASE
 - LDAP_BASE_WITH_MAIL
 - LDAP_SERVER
 - LDAP_USERNAME_MODE
 - LDAP_FILTER (optional)

Credentials for impersonation are also set by ENV variables:

 - SABRE_ADMIN_LOGIN
 - SABRE_ADMIN_PASSWORD

Feature flag to enable or disable admin impersonation.
 - SABRE_IMPERSONATION_ENABLED

   - `true`  : enable impersonation (internal / non-public Sabre only)
   - `false` : disable impersonation (default, recommended for public Sabre)
   This flag allows disabling admin impersonation entirely on public Sabre deployments
   to prevent impersonation over the internet.

Feature flag to auto-provision users upon a DAV request.
 - AUTO_PROVISION

   - unset or `true`: when an LDAP or impersonated user authenticates successfully but has no entry in the `users` collection yet, the entry is created on the fly instead of returning a `401` (default)
   - `false`: keep the legacy behaviour and return `401` when the user does not exist

   The domain part of the user's email must match an existing domain, otherwise the user cannot be provisioned. Needed upon migrations.

Feature flag to restrict DAV principal discovery.
 - PRINCIPAL_PRIVACY

   - unset or `true`: restrict DAV principal discovery to the current principal and its domain principals (default)
   - `false`: disable the restriction as a fast rollback path

   This prevents DAV clients from enumerating other users or resources and leaking internal principal ids.

Sabre being written in PHP, it supports per-request MongoDB indexes provisioning (defaults to `true`), which can be disabled by setting the SHOULD_CREATE_INDEX environment variable to `false`. This is recommended in production once indexes are provisioned.

Feature flag to control how inline binary attachments (`ATTACH;ENCODING=BASE64;VALUE=BINARY`) are handled on calendar object creation and update. URI attachments (`ATTACH:https://...`) are always preserved.
 - CALDAV_BINARY_ATTACHMENT_MODE

   - `filter` : silently strip inline binary attachments from the stored object (default)
   - `reject` : reject any request carrying an inline binary attachment with `403 Forbidden`
   - `allow`  : store the object as-is, inline binary attachments included

   Inline binaries can significantly bloat calendar objects; the default `filter` keeps them out of storage while still accepting the request. Use `allow` to opt back into the historical behaviour, or `reject` to surface an explicit error to clients.

## Scheduling

`TW_CAL_REPLY_PROPAGATION_THRESHOLD` controls reply propagation fan-out after an attendee updates their participation status.

When an attendee sends a `REPLY` such as accepting or declining an event, Sabre always updates the organizer calendar. It may also propagate that attendee `PARTSTAT` change to the other attendees. 
If the event attendee count is greater than or equal to `TW_CAL_REPLY_PROPAGATION_THRESHOLD`, this propagation to the other attendees is skipped to avoid large fan-out work.

- Default: `200`
- Set to `0` or a negative value to disable this skip and always propagate replies.

`SABRE_ENFORCE_RFC_6638` controls whether Sabre rejects attendee updates to scheduling fields that must remain organizer-controlled.

- Default: enabled. Unset, empty, or invalid values are treated as enabled.
- Set to `false`, `0`, `off`, or `no` to disable.

`SABRE_EMAIL_VALARM_RECIPIENT_SCHEDULING` controls recipient-aware scheduling for `ACTION:EMAIL` `VALARM` components.

- Default: enabled. Unset, empty, or invalid values are treated as enabled.
- Set to `false`, `0`, `off`, or `no` to disable.

When enabled, Sabre sends each attendee only the email alarms that explicitly list them as an alarm recipient, preserves attendee-local alarms during organizer updates, See [Alarm Scheduling Specification](ALARM-SCHEDULING.md) for the complete behavior.

## Nginx rate limiting

The embedded Nginx is configured with `ngx_http_limit_req_module` to protect the CalDAV server from request flooding. Three ENV variables control the behaviour:

 - `NGINX_RATE_LIMIT` — sustained request rate per IP (default: `50r/s`)
 - `NGINX_RATE_ZONE_SIZE` — size of the shared-memory tracking zone (default: `10m`, enough for ~160 000 IPs)
 - `NGINX_RATE_BURST` — number of requests above the rate that are served immediately before returning 503 (default: `100`)

Example — lower limits for a small deployment:

```bash
docker run -d -p 8001:80 \
  -e NGINX_RATE_LIMIT=10r/s \
  -e NGINX_RATE_BURST=30 \
  linagora/esn-sabre
```

## create the configuration file

The configuration file can be created from the example file.

```bash
cp config.json.default config.json
```

or by running the generation script:

```bash
sh ./scripts/generate_config.sh > config.json
```

You then have to modify the configuration to match your setup.

-	**webserver.baseUri**

The local part of the url that bring the esn.php file.

From apache, if you reach esn.php through http://YOUR_ESN_SABRE_IP/esn-sabre/esn.php then your baseUri is **/esn-sabre/esn.php**.

By using Docker your baseUri is only **/**.

-	**webserver.allowOrigin**

This setting is used to configure the headers returned by the CalDAV server. It's usefull to configure CORS. Either set the hostname of your ESN server, or leave "*".

-	**database.esn**

This is the configuration to access the ESN datastore

-	**database.sabre**

This is the configuration where the CalDAV server will store its data.

-	**esn.apiRoot**

This is the URL the Caldav server will use to access the OpenPaaS ESN API.

-	**mail**

This is the configuration the Caldav server will use to send emails.