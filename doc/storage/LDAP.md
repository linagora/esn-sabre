# LDAP

## Environment variables

| Variable              | Purpose |
|-----------------------|---------|
| `LDAP_SERVER`         | LDAP server URL |
| `LDAP_BASE`           | Base DN for user bind and search |
| `LDAP_ADMIN_DN`       | Admin DN used to search after user bind |
| `LDAP_ADMIN_PASSWORD` | Admin password |
| `LDAP_FILTER`         | Optional extra filter ANDed into the search (e.g. `(objectClass=inetOrgPerson)`) |
| `LDAP_USERNAME_MODE`  | Set to `username` to strip the `@domain` part before bind |

## Attributes

- `uid` — used for bind (`uid=<user>,<LDAP_BASE>`) and search filter
- `mail` — retrieved after auth; used as the Sabre principal identifier

## Auth flow

1. Bind as user: `uid=<user>,<LDAP_BASE>` with the provided password.
2. Bind as admin (`LDAP_ADMIN_DN`) to perform the directory search.
3. Search by `uid` (with `LDAP_FILTER` if set).
4. Read `mail` from the first result.
5. Resolve the Sabre principal via `getPrincipalIdByEmail($mail)`.

## Edge cases

| Situation | Outcome |
|-----------|---------|
| 0 results | Auth denied — `Unable to find $username which is valid for auth` |
| N > 1 results | Warning logged, first entry used, auth proceeds |
| `mail` attribute missing | Auth denied — `$user has no mail attribute` |
| No Sabre principal for that mail | Auth denied — `User not found` |

## Impersonation (optional)

Enabled via `SABRE_IMPERSONATION_ENABLED=true`. An admin (`SABRE_ADMIN_LOGIN` / `SABRE_ADMIN_PASSWORD`) can authenticate as another user with the format `adminLogin&user@domain`, bypassing LDAP entirely.
