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
| `ESN_SCHEME` | `http` — set `https` when the ESN API is not on a trusted local network: it carries technical tokens and answers with the authenticated identity; any value but `http` or `https` (case-insensitive) stops the container | `esn.apiRoot`, `esn.calendarRoot` |
| `ESN_API_URL` | unset — falls back to `ESN_SCHEME://ESN_HOST:ESN_PORT`; when set, the whole ESN API base URL (e.g. `https://esn.example.com`), trailing slashes dropped, and `ESN_SCHEME`/`ESN_HOST`/`ESN_PORT` are ignored; a value not starting with `http://` or `https://` and a host stops the container | `esn.apiRoot`, `esn.calendarRoot` |
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
| `LDAP_TIMEOUT` | `5` | Network and operation timeout of LDAP calls, in seconds |
| `SABRE_ADMIN_LOGIN` | unset — impersonation unavailable | Admin login used for impersonation |
| `SABRE_ADMIN_PASSWORD` | unset — impersonation unavailable | Admin password used for impersonation |
| `SABRE_IMPERSONATION_ENABLED` | `false` | Master switch for admin impersonation |
| `KERBEROS_ENABLED` | `false` | Kerberos (SPNEGO) authentication; also configures Nginx at startup, so set it in the environment. See [Kerberos authentication](#kerberos-authentication) |
| `KERBEROS_REALM_DOMAINS` | unset | Kerberos realm to mail domain mapping, e.g. `CORP.EXAMPLE.COM:example.com`. See [Kerberos authentication](#kerberos-authentication) |
| `KERBEROS_LDAP_PRINCIPAL_FILTER` | `(uid={user})` | LDAP filter finding the directory entry of a Kerberos principal. See [Kerberos authentication](#kerberos-authentication) |
| `CORS_ALLOWED_ORIGINS` | unset | Comma separated origins (e.g. `https://calendar.example.com`) that get `Access-Control-Allow-Credentials` and may send Kerberos requests. See [CORS](#cors) |
| `SABRE_MIGRATE_ON_STARTUP` | `true` | Run database migrations in the background at startup; See [database migrations](storage/MONGO.md#database-migrations) |
| `SABRE_PRUNE_CHANGES_ON_STARTUP` | `true` | Delete the sync changes older than their retention in the background at startup; See [`calendarchanges`](storage/MONGO.md#calendarchanges) |
| `AUTO_PROVISION` | `true` | Create missing users on successful authentication |
| `PRINCIPAL_PRIVACY` | `true` | Restrict DAV principal discovery |
| `TRUSTED_VIDEO_URL_BASE` | unset | Keep only video conference URLs at this base; unset leaves links untouched |
| `TRUSTED_ATTACH_URL_BASE` | unset | Keep only calendar ATTACH and contact PHOTO URLs at this base; unset leaves links untouched |
| `CALDAV_BINARY_ATTACHMENT_MODE` | `filter` | Inline binary attachment policy: `allow`, `reject`, `filter` or experimental `offload` |
| `CARDDAV_INLINE_ATTACHMENT_MODE` | `filter` | Inline contact photo policy: `allow`, `reject` or `filter` |
| `SABRE_ENFORCE_RFC_6638` | `true` | Reject attendee updates to organizer-controlled scheduling fields |
| `SABRE_EMAIL_VALARM_RECIPIENT_SCHEDULING` | `true` | Recipient-aware scheduling for `ACTION:EMAIL` `VALARM` components |
| `TW_CAL_REPLY_PROPAGATION_THRESHOLD` | `200` | Attendee count above which reply propagation is skipped |
| `SABRE_MAX_RECURRENCES` | `3500` | Maximum number of occurrences generated when expanding a recurring event; See [recurrence expansion](#recurrence-expansion) |
| `SABRE_CONTACT_SEARCH_MIN_LENGTH` | `3` | Minimum length, in characters, of the `search` of a contact listing; a shorter search returns no contact. `0` or negative removes the minimum. See [JSON API](JSON-API.md) |
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

MongoDB indexes are created once when the container starts (`scripts/create-indexes.php`), not on every request. See [doc/storage/MONGO.md](storage/MONGO.md#index-creation).
Index creation is retried while MongoDB is not reachable, then the container refuses to start (these variables are read by `scripts/start.sh` from the process environment only):
 - MONGO_INDEX_CREATION_ATTEMPTS: number of attempts (default `30`)
 - MONGO_INDEX_CREATION_RETRY_DELAY: seconds between two attempts (default `2`)
 - MONGO_INDEX_CREATION_REQUIRED: `true` (default) stops the container when every attempt failed; `false` starts anyway and logs an `ERROR`

Feature flag to control how inline binary attachments (`ATTACH;ENCODING=BASE64;VALUE=BINARY`) are handled on calendar object creation and update. URI attachments (`ATTACH:https://...`) are always preserved.
 - CALDAV_BINARY_ATTACHMENT_MODE

   - `filter` : silently strip inline binary attachments from the stored object (default)
   - `reject` : reject any request carrying an inline binary attachment with `403 Forbidden`
   - `allow`  : store the object as-is, inline binary attachments included
   - `offload` : upload inline attachments to TDrive and replace them with URLs before saving

   Inline binaries can significantly bloat calendar objects; the default `filter` keeps them out of storage while still accepting the request. Use `allow` to opt back into the historical behaviour, or `reject` to surface an explicit error to clients.

Feature flag to control how inline contact photos are handled on contact creation and update: `PHOTO;ENCODING=b` (vCard 3.0), `PHOTO;ENCODING=BASE64` (vCard 2.1), `PHOTO;VALUE=BINARY`, or a `data:` URI (`PHOTO:data:image/jpeg;base64,...`, vCard 4.0 / jCard). Photos referenced by an external URI (`PHOTO;VALUE=URI:https://...`) are always preserved, and only `PHOTO` is inspected.
 - CARDDAV_INLINE_ATTACHMENT_MODE

   - `filter` : silently strip inline photos from the stored card (default)
   - `reject` : reject any vCard carrying an inline photo with `403 Forbidden`
   - `allow`  : store the vCard as-is, inline photo included

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

## Recurrence expansion

`SABRE_MAX_RECURRENCES` caps the number of occurrences generated when a recurring event is expanded: calendar queries with a time range, `expand`, free/busy, iTIP processing...

Expansion always starts from the first occurrence (`DTSTART`), whatever the requested time range. A long-running series exceeding the limit before reaching that range (e.g. a daily event started more than ~10 years ago with the default value) is stored fine but returns no occurrence for it.

- Default: `3500`. Unset, empty, `0` or non-numeric values are treated as `3500`.
- Set to a negative value to disable the limit. Expansion then remains bounded by year 2100.
  This bounds dates, not cost: a dense series (`FREQ=HOURLY` started centuries ago, `FREQ=SECONDLY`...) may still generate millions of occurrences. The time window stored for each event is computed from at most 3500 occurrences of each VEVENT; beyond that the event is treated as running until 2038. Other expansions have no such bound.
- This limit protects the server against events crafted to recur many times: raise it with care.

## Nginx request body limit

Set `NGINX_CLIENT_MAX_BODY_SIZE` on the Sabre container to control the maximum
size of an HTTP request body. The default is `1m` (1 MiB). Use a positive
integer in bytes or append `k` or `m`.

CalDAV clients embed file contents as Base64 in the iCalendar body. For example,
`16m` leaves room for up to 10 MiB of files plus Base64, line folding and event
metadata. This setting applies to all requests served by the container's Nginx.

## Nginx rate limiting

The embedded Nginx is configured with `ngx_http_limit_req_module` to protect the CalDAV server from
request flooding. Every setting is an environment variable read by `scripts/start.sh` when the
container starts: no image rebuild, no `config.json` entry, no file to mount. To change a value,
restart the container with the new environment. An invalid value stops the container at startup with
an error that names it.

| Variable                     | Default  | Meaning                                                                                  |
|------------------------------|----------|------------------------------------------------------------------------------------------|
| `NGINX_RATE_LIMIT`           | `50r/s`  | Sustained rate per client IP (`<n>r/s` or `<n>r/m`). `off`, `false`, `0` or `disabled` (any case) disables rate limiting. |
| `NGINX_RATE_BURST`           | `100`    | Requests above the rate served immediately before rejecting.                            |
| `NGINX_RATE_ZONE_SIZE`       | `10m`    | Shared-memory size of the tracking zone (`10m` is enough for ~160 000 IPs).              |
| `NGINX_RATE_LIMIT_STATUS`    | `429`    | HTTP status of rejected requests (400 to 599).                                           |
| `NGINX_TRUSTED_CLIENTS_CIDR` | (unset)  | CIDR(s) of trusted clients, never rate limited (e.g. `10.42.0.0/16`).                    |
| `NGINX_TRUSTED_PROXIES`      | `0.0.0.0/0,::/0` | CIDR(s) of peers allowed to set `X-Forwarded-For`. Set to an empty string to ignore the header. |

Rejected requests get `429 Too Many Requests` with a `Retry-After: 1` header and the usual CORS
headers, so browsers let the frontend see the 429. Rejections are logged at the `warn` level. The
container logs its effective configuration at startup, for example:

```
start.sh: nginx rate limiting enabled: limit=50r/s burst=100 status=429 zone_size=10m trusted_proxies=[10.0.0.0/8] trusted_clients=[10.42.0.0/16]
```

**The limit is per client IP.** By default `X-Forwarded-For` from any peer replaces the TCP peer IP;
without the header, the TCP peer IP is used. The ingress must sanitize the header.

 - `NGINX_TRUSTED_PROXIES` takes one or more CIDRs, separated by commas or spaces. It defaults to
   all IPv4 and IPv6 peers. Set explicit CIDRs to restrict who may supply `X-Forwarded-For`, or an
   empty string to ignore it. With `real_ip_header X-Forwarded-For; real_ip_recursive on;`, trusted
   hops are skipped; when all peers are trusted, the first address in the header is used. The rate
   limit, access logs and anything else that uses the client address then see the selected IP.
 - `NGINX_TRUSTED_CLIENTS_CIDR` takes one or more CIDRs, separated by commas or spaces
   (`10.42.0.0/16,192.168.10.0/24`). A single IP is accepted too: `10.42.3.7` is treated as `/32`, an
   IPv6 address as `/128`. Requests from these clients are never rate limited. `0.0.0.0/0` and `::/0`
   are rejected: use `NGINX_RATE_LIMIT=off` to disable rate limiting. With a trusted
   `X-Forwarded-For` header, the exemption applies to the forwarded client IP, not to the proxy IP.
   The exemption is based on the address only, never on credentials: Nginx cannot validate an
   `Authorization` header, so anyone could forge one to bypass the limit.
 - `NGINX_RATE_LIMIT=off` removes the `limit_req_zone` and `limit_req` directives from the
   configuration, for deployments that already rate limit at their ingress.

Recommended setup for Twake Calendar: exempt the network of the side service, which legitimately
sends bursts (imports, reindexing, alarm scheduling, domain member sync, ITIP), and trust the ingress
so that end users each get their own bucket.

```yaml
sabre_dav:
  image: linagora/esn-sabre
  environment:
    - NGINX_TRUSTED_CLIENTS_CIDR=10.42.0.0/16   # side service internal requests without X-Forwarded-For: never rate limited
    - NGINX_RATE_LIMIT=50r/s
```

The same variables work in a Kubernetes `env:` block or in the Helm chart values. With `docker run`:

```bash
docker run -d -p 8001:80 \
  -e NGINX_TRUSTED_CLIENTS_CIDR=10.42.0.0/16 \
  -e NGINX_RATE_LIMIT=10r/s \
  -e NGINX_RATE_BURST=30 \
  linagora/esn-sabre
```

## PHP-FPM slow log and status page

The PHP-FPM pool that runs Sabre (`www`, `pm.max_children = 96`) logs requests that take too long and
publishes its status page. As for the Nginx settings, these are environment variables that
`scripts/start.sh` reads when the container starts. An invalid value stops the container at startup.

| Variable                      | Default          | Meaning                                                                                  |
|-------------------------------|------------------|------------------------------------------------------------------------------------------|
| `PHP_FPM_SLOWLOG_TIMEOUT`     | `5s`             | A request running longer than this is reported as slow (`request_slowlog_timeout`). A number with an optional `s`, `m`, `h` or `d` unit (no unit means seconds). `0`, `off`, `false` or `disabled` turns the slow log off. |
| `PHP_FPM_SLOWLOG_TRACE_DEPTH` | `20`             | Depth of the PHP backtrace written for a slow request (`request_slowlog_trace_depth`).   |
| `PHP_FPM_STATUS_LISTEN`       | `127.0.0.1:8081` with `SABRE_ENV=dev`, else `off` | Address where Nginx serves the FPM `/status` and `/ping` pages (`[address:]port`). Set it explicitly to enable them in production (a monitoring probe, on a private port). `off`, `false` or `disabled` turns them off: Nginx does not serve them and FPM does not answer them. |

Each slow request writes a line to the container log (stderr), naming the worker, the script and the
request:

```
WARNING: [pool www] child 67, script '/var/www/esn.php' (request: "REPORT /calendars/...") executing too slow (5.004245 sec), logging
```

**The backtrace and the `slow requests` counter need `ptrace`.** After this line, the FPM master
attaches to the worker with `ptrace` to write the PHP backtrace (for example the
`ESN\CalDAV\Backend\DAO\BaseDAO::find` the request waits on) to the container log. Only then does it
increment the `slow requests` counter of the status page. A container without the `SYS_PTRACE`
capability, which is the Docker and Kubernetes default, refuses this. The `executing too slow` line is
still logged, followed by `ERROR: failed to ptrace(ATTACH) child 67: Operation not permitted`. The
backtrace is missing and `slow requests` stays at `0`. To get them, add the capability:
`docker run --cap-add SYS_PTRACE`, or in Kubernetes
`securityContext: { capabilities: { add: ["SYS_PTRACE"] } }`.

**The status page answers even when the pool is full.** FPM serves `/status` and `/ping` from a
dedicated pool (`pm.status_listen = 127.0.0.1:9001`), not from the 96 workers that run Sabre. A
monitoring probe therefore keeps getting an answer while every worker is busy, which is exactly when
it matters. Nginx serves these pages on their own port, never on port 80: the public server answers
`404` to `/status` and `/ping`. That block matters, because `pm.status_listen` reads the status URIs of
the `www` pool, so they are set on `www` too and its workers would answer them on its socket, before
`esn.php` and its authentication run. The full view
(`/status?full`) shows the URI of each running request: the pages are a dev tool, on by default with
`SABRE_ENV=dev` only, and even then the port is only reachable from inside the container. In
production, or to scrape it from outside, set `PHP_FPM_STATUS_LISTEN` (here `8081`) and keep that
port off the public network (do not route it through the ingress):

```bash
docker run -d -p 8001:80 -p 127.0.0.1:8081:8081 \
  -e PHP_FPM_STATUS_LISTEN=8081 \
  linagora/esn-sabre
curl http://127.0.0.1:8081/status   # pool counters: active processes, listen queue, slow requests...
curl http://127.0.0.1:8081/ping     # pong
```

## Nginx basic authentication

 - `NGINX_AUTH_BASIC` — unset by default, which leaves the server open. When set, its content is
   written to `/etc/nginx/.htpasswd` and basic authentication is enabled. Commas separate the
   entries, one `user:hashed_password` pair each.

The rate-limiting and basic-authentication settings above are applied to the Nginx configuration
by the container entrypoint, before PHP starts, so they have no `config.json` equivalent.

## CORS

Nginx answers every origin with its own `Access-Control-Allow-Origin`. `Access-Control-Allow-Credentials: true`
additionally lets a web page of that origin make the browser send its ambient credentials (cookies, a Kerberos
ticket). Front-ends that send their own `Authorization` header (Bearer, Basic) do not need it.

| Setting | `Access-Control-Allow-Credentials` |
|---|---|
| `CORS_ALLOWED_ORIGINS` unset, Kerberos disabled | sent to every origin, as before |
| `CORS_ALLOWED_ORIGINS` unset, `KERBEROS_ENABLED=true` | sent to no origin: any web page could otherwise read the responses of requests sent with the visitor's Kerberos ticket |
| `CORS_ALLOWED_ORIGINS` set | sent to these origins only |

These headers only decide what a web page may read, and which preflighted requests the browser sends. A
CORS-simple request (a `POST` with a `text/plain` body, an HTML form) is sent anyway, and the browser
answers the `Negotiate` challenge with the visitor's ticket. So with `KERBEROS_ENABLED=true` Nginx also
refuses, with `403` and before the ticket is used, a `Negotiate` request:

 - whose `Origin` is neither the DAV host's own (same host name as the `Host` header, any scheme or
   port) nor listed in `CORS_ALLOWED_ORIGINS`, `Origin: null` included;
 - without `Origin` but with `Sec-Fetch-Site: cross-site`, unless its method is `GET`, `HEAD` or `OPTIONS`.

Desktop clients send no `Origin`, and front-ends sending their own `Authorization` header are not
concerned. Behind a proxy that rewrites the `Host` header, list the public origin of the DAV host in
`CORS_ALLOWED_ORIGINS` if web pages of that origin use Kerberos.

`CORS_ALLOWED_ORIGINS` lists exact origins, comma separated, scheme and host (and port) without a path:
`https://calendar.example.com,https://contacts.example.com`. It is applied to the Nginx configuration by
`scripts/start.sh` when the container starts.

## Kerberos authentication

Clients holding a Kerberos ticket, typically desktop clients (Thunderbird, Evolution) on machines
joined to an Active Directory or FreeIPA domain, can authenticate without a password through
SPNEGO (`Authorization: Negotiate`, RFC 4559). Basic, Bearer and `TwakeCalendarToken`
authentication keep working alongside, so clients without a ticket still use their password.

| Variable | Default | Meaning |
|---|---|---|
| `KERBEROS_ENABLED` | `false` | Enable Kerberos authentication. |
| `KERBEROS_SERVICE_PRINCIPAL` | (required) | Service principal of the keytab, with its realm, as `klist -k` prints it: `HTTP/<host name the clients use>@REALM`, e.g. `HTTP/dav.example.com@CORP.EXAMPLE.COM`. |
| `KERBEROS_KEYTAB` | `/etc/krb5.keytab` | Keytab holding the key of that service principal, readable by `www-data`. |
| `KERBEROS_REALM_DOMAINS` | (required) | Comma separated `REALM:domain` pairs. Realms are case sensitive. Two realms cannot be mapped onto the same domain. |
| `KERBEROS_LDAP_PRINCIPAL_FILTER` | `(uid={user})` | When `LDAP_SERVER` is set, the filter finding the directory entry of a principal. `{user}` is replaced by the principal name (`alice`), `{principal}` by the full principal (`alice@CORP.EXAMPLE.COM`), both escaped. E.g. `(sAMAccountName={user})` for Active Directory, `(krbPrincipalName={principal})` for FreeIPA. |
| `KERBEROS_LARGE_CLIENT_HEADER_BUFFERS` | `4 64k` | Nginx `large_client_header_buffers` once Kerberos is enabled. |

All of them are environment variables read by `scripts/start.sh` when the container starts; an
invalid or missing value stops the container with an error that names it. For example:

```yaml
sabre_dav:
  image: linagora/esn-sabre
  environment:
    - KERBEROS_ENABLED=true
    - KERBEROS_SERVICE_PRINCIPAL=HTTP/dav.example.com@CORP.EXAMPLE.COM
    - KERBEROS_KEYTAB=/etc/sabre/dav.keytab
    - KERBEROS_REALM_DOMAINS=CORP.EXAMPLE.COM:example.com
  volumes:
    - ./dav.keytab:/etc/sabre/dav.keytab:ro   # owned by root:www-data (33), mode 0640
```

How it works:

 - Nginx validates the ticket against the keytab (`ngx_http_auth_spnego_module`), offline: Sabre
   never contacts the KDC, and does not need port 88 open to it.
 - The realm of the principal must be listed in `KERBEROS_REALM_DOMAINS`. Principals of a realm
   that is not listed, or with an instance (`HTTP/host@REALM`), are refused.
 - **With an LDAP directory** (`LDAP_SERVER` set), the directory tells which mailbox the principal
   owns, as for Basic authentication: Sabre binds with `LDAP_ADMIN_DN` and searches `LDAP_BASE`
   with `KERBEROS_LDAP_PRINCIPAL_FILTER`, ANDed with `LDAP_FILTER`. The principal is refused unless
   exactly one entry matches, and the `mail` of that entry must be in the domain its realm maps to
   and belong to an existing user. A directory that cannot be reached gets `503`.
 - **Without a directory**, the principal name is assumed to be the local part of the user's
   primary address in the domain of its realm: `alice@CORP.EXAMPLE.COM` must be the user whose
   primary address (the first address of its account) is `alice@example.com`. Secondary addresses
   and aliases never match, so that a principal named after an alias (`sales@`) cannot sign in as
   the user who holds it; an address that is the primary one of several users is refused. Only use
   this mode when every principal name equals the primary mail local part of its owner.
 - Kerberos names are case sensitive while addresses and directory matches usually are not: as in
   James, a principal whose name is not lower case (`Alice@CORP.EXAMPLE.COM`) is refused, so that
   two principals differing only by case cannot sign in as the same user.
 - Users are not auto-provisioned.
 - Unauthenticated requests are challenged with `WWW-Authenticate: Negotiate` before `Basic`.

Operational notes:

 - **The service principal must match the host name the clients connect to.** Clients ask the KDC
   for a ticket for `HTTP/<host>`, after resolving CNAMEs. Behind a load balancer, use the public
   host name, and the same keytab on every replica.
 - **A rejected ticket gets `403`, not `401`**: a ticket the keytab cannot decrypt (wrong service
   principal, key rotated since the keytab was exported) is refused by Nginx, and a valid ticket
   whose principal does not map to a user is refused by Sabre. No challenge is sent, so that clients
   do not resend the same ticket in a loop. When the key of the service principal changes (password
   reset of its AD account, `ktadd` without `-norandkey`), every Kerberos user gets `403` until the
   new keytab is deployed.
 - **Tickets can be large.** Active Directory tickets carry the groups of the user, and Nginx answers
   `400 Request Header Or Cookie Too Large` to an `Authorization` header that does not fit in
   `KERBEROS_LARGE_CLIENT_HEADER_BUFFERS`. Raise the same limit on every proxy in front of Sabre,
   which must also forward the `Authorization` request header and every `WWW-Authenticate` response
   header unchanged.
 - A user disabled in the KDC keeps access until their ticket expires (10 hours by default in
   Active Directory): Sabre never queries the KDC. Use `LDAP_FILTER` to exclude disabled directory
   entries, as for Basic authentication.
 - `KERBEROS_ENABLED=true` cannot be combined with `NGINX_AUTH_BASIC`.

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