## Configuration sources

Every setting below can be provided either in `config.json` or as an ENV variable. They are
resolved in this order:

1. the `environment` section of `config.json`
2. the process environment
3. the built-in default documented in the tables below

Blank values (`""` or `null`) in `config.json` count as *not set* and fall through to the
environment, so an empty entry never shadows an exported variable. A setting can therefore not
be forced to the empty string — that matches the historical behaviour, where an unset variable
and an empty one were equivalent.

In the Docker image, [`scripts/generate_config.sh`](../scripts/generate_config.sh) writes
`config.json` from the variables exported to the container, `environment` section included, so
exporting an ENV variable and setting the matching `config.json` key are equivalent.
[`config.json.default`](../config.json.default) lists every key with its default value.

Booleans accept `true` / `1` / `yes` / `on` and `false` / `0` / `no` / `off`, in any case, as a
JSON string (`"true"`) or as a JSON native (`true`). Unset, empty and unparseable values all
fall back to the default, so a flag that defaults to enabled can only be turned off by an
explicit false-ish value.

## Infrastructure variables

These are read by the generation script only: they end up in the regular `config.json` sections,
not in `environment`. Setting the target key in `config.json` directly has the same effect.

| Variable | Default | Ends up in |
|---|---|---|
| `SABRE_MONGO_HOST` | `sabre_mongo` | `database.sabre.connectionString` |
| `SABRE_MONGO_PORT` | `27017` | `database.sabre.connectionString` |
| `SABRE_MONGO_DBNAME` | `sabre` | `database.sabre.connectionString` |
| `SABRE_MONGO_URI` | unset — falls back to `host:port/dbname` | `database.sabre.connectionString` |
| `SABRE_MONGO_USER` | unset — no credentials in the URI | `database.sabre.connectionString` |
| `SABRE_MONGO_PASSWORD` | unset | `database.sabre.connectionString` |
| `ESN_MONGO_HOST` | `esn_mongo` | `database.esn.connectionString` |
| `ESN_MONGO_PORT` | `27017` | `database.esn.connectionString` |
| `ESN_MONGO_DBNAME` | `esn` | `database.esn.connectionString` |
| `ESN_MONGO_URI` | unset — falls back to `host:port/dbname` | `database.esn.connectionString` |
| `ESN_MONGO_USER` | unset — no credentials in the URI | `database.esn.connectionString` |
| `ESN_MONGO_PASSWORD` | unset | `database.esn.connectionString` |
| `MONGO_TIMEOUT` | `10000` | `database.*.connectionOptions.connectTimeoutMS` |
| `ESN_HOST` | `esn_host` | `esn.apiRoot`, `esn.calendarRoot` |
| `ESN_PORT` | `8080` | `esn.apiRoot`, `esn.calendarRoot` |
| `AMQP_HOST` | `amqp_host` | `amqp.host` |
| `AMQP_PORT` | `5672` | `amqp.port` |
| `AMQP_LOGIN` | `guest` | `amqp.login` |
| `AMQP_PASSWORD` | `guest` | `amqp.password` |
| `AMQP_VHOST` | `/` | `amqp.vhost` |
| `AMQP_SSL_ENABLED` | `false` | `amqp.sslEnabled` |
| `AMQP_SSL_TRUST_ALL_CERTS` | `false` | `amqp.sslTrustAllCerts` |

## Runtime settings

These are read by PHP on demand, through `ESN\Utils\Env`, and live in the `environment` section
of `config.json`.

| Variable | Default | Purpose |
|---|---|---|
| `SABRE_ENV` | `production` | `dev` adds stack traces to error responses and enables dev-only plugins |
| `LDAP_SERVER` | unset | LDAP server URL; required for LDAP authentication |
| `LDAP_BASE` | unset | Base DN for the user bind and the directory search |
| `LDAP_FILTER` | unset — no extra filter | Filter ANDed into the search, e.g. `(objectClass=inetOrgPerson)` |
| `LDAP_ADMIN_DN` | unset | Bind account used to search the directory after the user bind |
| `LDAP_ADMIN_PASSWORD` | unset | Password of that bind account |
| `LDAP_USERNAME_MODE` | unset — bind with the full address | Set to `username` to strip the `@domain` part before binding |
| `SABRE_ADMIN_LOGIN` | unset — impersonation unavailable | Admin login used for impersonation |
| `SABRE_ADMIN_PASSWORD` | unset — impersonation unavailable | Admin password used for impersonation |
| `SABRE_IMPERSONATION_ENABLED` | `false` | Master switch for admin impersonation |
| `AUTO_PROVISION` | `true` | Create missing users on successful authentication |
| `PRINCIPAL_PRIVACY` | `true` | Restrict DAV principal discovery |
| `CALDAV_BINARY_ATTACHMENT_MODE` | `filter` | Inline binary attachment policy: `allow`, `reject` or `filter` |
| `CALDAV_ORGANIZER_VALIDATION` | `false` | Enforce `ORGANIZER` validation on calendar objects |
| `SABRE_ENFORCE_RFC_6638` | `true` | Reject attendee updates to organizer-controlled scheduling fields |
| `SABRE_EMAIL_VALARM_RECIPIENT_SCHEDULING` | `true` | Recipient-aware scheduling for `ACTION:EMAIL` `VALARM` components |
| `TW_CAL_REPLY_PROPAGATION_THRESHOLD` | `200` | Attendee count above which reply propagation is skipped |
| `SHOULD_CREATE_INDEX` | `true` | Provision MongoDB indexes on every request |
| `LOG_TRACE` | `false` | Add exception stack traces to the logs |

Both spellings are equivalent — as an ENV variable:

```bash
docker run -d -p 8001:80 \
  -e SABRE_IMPERSONATION_ENABLED=true \
  -e PRINCIPAL_PRIVACY=false \
  linagora/esn-sabre
```

or in `config.json`:

```json
{
  "environment": {
    "SABRE_IMPERSONATION_ENABLED": true,
    "PRINCIPAL_PRIVACY": false
  }
}
```

The rest of this section details the flags whose behaviour needs more than one line.

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

- Default: `200`. Unset, empty, or non-numeric values are treated as `200`.
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

## Nginx basic authentication

 - `NGINX_AUTH_BASIC` — unset by default, which leaves the server open. When set, its content is
   written to `/etc/nginx/.htpasswd` and basic authentication is enabled. Commas separate the
   entries, one `user:hashed_password` pair each.

The rate-limiting and basic-authentication settings above are applied to the Nginx configuration
by the container entrypoint, before PHP starts, so they have no `config.json` equivalent.

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