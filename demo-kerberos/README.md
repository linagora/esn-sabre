# Demo #483: Kerberos (SPNEGO) for esn-sabre

Throwaway proof of concept for https://github.com/linagora/esn-sabre/issues/483,
not meant to be merged as is.

## Design under test

```
client --Authorization: Negotiate--> nginx  location /         (if Negotiate: rewrite to /__krb)
                                            location = /__krb  auth_gss + keytab
                                              ok  -> fastcgi_param KRB5_PRINCIPAL $remote_user
                                                     fastcgi_param HTTP_AUTHORIZATION ""
                                              bad -> 403 from nginx, PHP never runs
       --anything else----------------> nginx  location /  -> Sabre: Bearer / TwakeCalendarToken / Basic
```

- nginx: Debian package `libnginx-mod-http-auth-spnego`, now part of the regular image, with
  `docker/config/kerberos.conf.template` rendered by `scripts/kerberos.sh`.
- Sabre (`lib/DAV/Auth/Backend/Esn.php`):
  - `KERBEROS_ENABLED=true` + non empty `KRB5_PRINCIPAL` -> `checkKerberos()`, tried first.
  - `principal -> email` by rewriting the realm (`KERBEROS_REALM_DOMAINS=TEST.LOCAL:linagora.com`),
    then `getAuthTenantByEmail()`. No LDAP lookup, no auto-provisioning.
  - `challenge()` answers `WWW-Authenticate: Negotiate` before `Basic`.
- The module replaces the client's `Authorization` header with a bogus Basic one
  carrying the principal (see its README), so Sabre cannot cross-check the
  Authorization scheme: `/__krb` hides it and only `KRB5_PRINCIPAL` is trusted.

## Run

```sh
./demo-kerberos/build.sh
cd demo-kerberos
docker compose up -d --wait
docker compose exec kdc bash /demo/run-tests.sh
docker compose down -v      # needed before re-running: case 15 rotates a key
```

| Container | Role |
|---|---|
| `kdc` | MIT KDC, realm `TEST.LOCAL`, and the Kerberos client of the tests |
| `sabre` | Kerberos on, `TEST.LOCAL -> linagora.com` |
| `sabre-norealm` | Kerberos on, `TEST.LOCAL` not allowed |
| `sabre-kerberos-off` | `KERBEROS_ENABLED=false` |
| `mongodb` | seeded by `mongo/init.js` (fixed ids) |
| `esn_ldap` | the LDAP of the test suite, for the Basic cases |

Principals: `username@TEST.LOCAL` (maps to `username@linagora.com`, exists),
`nobody@TEST.LOCAL` (no user), `HTTP/{sabre,sabre-kerberos-off,sabre-norealm,other}.test.local`.

## Tests (`run-tests.sh`)

| # | Case | Expected |
|---|---|---|
| 1 | Negotiate | 207, principal of username |
| 2 | no credentials | 401, `Negotiate` then `Basic` |
| 3 | Basic (LDAP) | 207 |
| 4 | Bearer JWT | 207, principal of user1 |
| 5 | Basic, wrong password | 401 |
| 6 | forged `KRB5_PRINCIPAL` / `KRB5-PRINCIPAL` headers | 401 |
| 7 | garbage Negotiate token | 403 from nginx |
| 8 | ticket for another SPN | 403 from nginx |
| 9 | valid ticket, no user | 403 from Sabre, no retry loop |
| 10 | realm not allowed | 403 from Sabre |
| 11 | Kerberos disabled | 401, no Negotiate challenge, Basic still OK |
| 12 | mutual auth token on the 207 | observation |
| 13 | 12 KB Negotiate header | observation (nginx default buffers are 8 KB) |
| 14 | MKCALENDAR / PUT / GET / REPORT / DELETE through `/__krb` | 201 / 201 / 200 / 207 / 204 |
| 15 | key rotated after the keytab was exported | 403 from nginx |

## Manual testing

Start from a fresh stack: case 15 of the tests leaves `sabre` with a stale
keytab, every Negotiate request to it is then a 403.

```sh
cd demo-kerberos
docker compose down -v && docker compose up -d --wait
docker compose logs -f sabre          # in another terminal: nginx and PHP errors
docker compose exec kdc bash          # the commands below run in this shell
```

Only `kdc` has `kinit`, a GSSAPI-enabled `curl` and the `*.test.local` names.
Users: `username@TEST.LOCAL` and `nobody@TEST.LOCAL`, password `secret`.

### Get a ticket

```sh
kinit username@TEST.LOCAL             # password: secret
klist                                 # krbtgt/TEST.LOCAL@TEST.LOCAL
```

Without a ticket (or with an expired one) `curl --negotiate` silently sends
nothing and the answer is a 401: check `klist` first. `kdestroy` drops them all.

### Requests

```sh
# Who am I? 207 with /principals/users/5f000000000000000000000a/ (username@linagora.com).
# -v shows both hops: 401 + "WWW-Authenticate: Negotiate", then the ticket and the 207.
# -u : is required, curl only authenticates with -u; the identity comes from the ticket.
curl -v --negotiate -u : -X PROPFIND -H 'Depth: 0' \
  --data '<d:propfind xmlns:d="DAV:"><d:prop><d:current-user-principal/></d:prop></d:propfind>' \
  http://sabre.test.local/
klist                                 # now also HTTP/sabre.test.local@TEST.LOCAL

# No credentials: Negotiate offered before Basic
curl -si -X PROPFIND http://sabre.test.local/ | grep -i '^www-authenticate'

# Basic and Bearer are unchanged
curl -si -u username:password -X PROPFIND -H 'Depth: 0' http://sabre.test.local/ | head -1   # 207

# Realm not allowed / Kerberos disabled
curl -si --negotiate -u : -X PROPFIND http://sabre-norealm.test.local/ | head -1         # 403
curl -si --negotiate -u : -X PROPFIND http://sabre-kerberos-off.test.local/ | head -1    # 401

# Garbage token: nginx's HTML 403, PHP never runs (nothing in the PHP log)
curl -si -H 'Authorization: Negotiate Z2FyYmFnZQ==' -X PROPFIND http://sabre.test.local/

# Valid ticket, no user in Mongo: one 403, no retry loop
kinit nobody@TEST.LOCAL               # secret
curl -v --negotiate -u : -X PROPFIND http://sabre.test.local/
kinit username@TEST.LOCAL

# A calendar round trip through /__krb
CAL=http://sabre.test.local/calendars/5f000000000000000000000a/manual
curl -si --negotiate -u : -X MKCALENDAR "$CAL/" | head -1                                 # 201
printf 'BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//demo//manual//EN\r\nBEGIN:VEVENT\r\nUID:manual-1\r\nDTSTAMP:20261116T090000Z\r\nDTSTART:20261120T090000Z\r\nDTEND:20261120T100000Z\r\nSUMMARY:Manual test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n' \
  | curl -si --negotiate -u : -X PUT -H 'Content-Type: text/calendar' --data-binary @- "$CAL/manual-1.ics" | head -1   # 201
curl -s --negotiate -u : "$CAL/manual-1.ics"                                              # the event
curl -si --negotiate -u : -X DELETE "$CAL/" | head -1                                     # 204
```

### Break things on purpose

```sh
# Key rotation without a new keytab (like case 15): sabre now answers 403
kadmin.local -q "cpw -randkey HTTP/sabre.test.local@TEST.LOCAL"
kdestroy && kinit username@TEST.LOCAL
curl -si --negotiate -u : -X PROPFIND http://sabre.test.local/ | head -1                  # 403
# then reset the stack (down -v / up) to get a working keytab back

# Clock skew: tickets are refused beyond 5 minutes of difference. Not easy to
# reproduce in containers sharing the host clock; on a real client, a wrong
# clock gives the same 403.
```

## Findings (nginx 1.26.3, libnginx-mod-http-auth-spnego 1.1.3-1, trixie)

`run-tests.sh`: 20 passed, 0 failed.

1. **The Debian module works as is**: `apt install libnginx-mod-http-auth-spnego`,
   no nginx rebuild, auto-loaded through `modules-enabled`.
2. **Optional Kerberos works with a rewrite to an internal location** (option 2a):
   Basic (LDAP), Bearer JWT and anonymous requests are untouched, `REQUEST_URI`
   stays the client's path, bodies and methods (MKCALENDAR, PUT, REPORT,
   DELETE) go through `/__krb` unchanged. Option 2b (`auth_request`) was not needed.
3. **The module replaces `Authorization` with a bogus Basic header**: Sabre
   cannot check the scheme, `/__krb` blanks `HTTP_AUTHORIZATION` and only
   `KRB5_PRINCIPAL` is trusted. Forged `KRB5_PRINCIPAL`/`KRB5-PRINCIPAL`
   headers have no effect (nginx drops the first one, the second one only
   reaches PHP as `HTTP_KRB5_PRINCIPAL`).
4. **A bad ticket is a 403 from nginx, not a 401** (garbage token, wrong SPN,
   key rotated after the keytab was exported). The client gets no challenge,
   so it cannot fall back to Basic: a stale keytab locks Kerberos users out
   until the keytab is replaced.
5. **Bug found and fixed: 401 retry loop.** With a valid ticket but no matching
   user, Sabre answered 401 + `WWW-Authenticate: Negotiate`, the client sent the
   same ticket again, forever (curl gave up with exit 47). Sabre now answers
   **403** whenever it refuses a principal nginx authenticated
   (`KerberosRejectedException`), consistent with point 4.
6. **Mutual authentication works**: the 207 carries `WWW-Authenticate: Negotiate <token>`.
7. **A 12 KB `Authorization` header is rejected with 400** by nginx's default
   `large_client_header_buffers 4 8k`: AD tickets with a large PAC need it raised,
   on every proxy in front of Sabre too. The official configuration now uses
   `4 64k` once Kerberos is enabled (`KERBEROS_LARGE_CLIENT_HEADER_BUFFERS`), and
   case 13 then gets past nginx's header limit (403: the token is garbage).
8. `$remote_user` is in the nginx access log format: Kerberos requests log the
   full principal, Basic requests the unverified username (already the case today).
