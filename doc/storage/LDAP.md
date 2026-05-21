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

- `uid` — used for bind (`uid=<user>,<LDAP_BASE>`) and search filter.
according to RFC 2798 OID: 0.9.2342.19200300.100.1.1
- `mail` — retrieved after auth; used as the Sabre principal identifier
Mail attribute according to RFC 2798 (may be multiple valued) OID: 0.9.2342.19200300.100.1.3
Required attribute for esn-sabre

## Auth flow

1. Bind as user: `uid=<user>,<LDAP_BASE>` with the provided password.
2. Bind as admin (`LDAP_ADMIN_DN`) to perform the directory search. Technically a bind account
3. Search by `uid` (with `LDAP_FILTER` if set).
4. Read `mail` from the first result.
5. Resolve the Sabre principal via `getAuthTenantByEmail($mail)`.

## derivation of principal

The security model enforces lowercase ASCII for all mail attributes.
When performing a lookup on the mongoDB, the system attempts to retrieve
the preferred email address first. If the entry does not define a preferred
address, the search falls back to any other mail value associated with the user.

## Edge cases

| Situation | Outcome |
|-----------|---------|
| 0 results | Auth denied — `Unable to find $username which is valid for auth` |
| N > 1 results | Warning logged, first entry used, auth proceeds. This is likely an error on LDAP_BASE or LDAP_FILTER |
| `mail` attribute missing | Auth denied — `$user has no mail attribute` |
| No Sabre principal for that mail | Auth denied — `User not found` |

## Impersonation (optional)

Enabled via `SABRE_IMPERSONATION_ENABLED=true`. An admin (`SABRE_ADMIN_LOGIN` / `SABRE_ADMIN_PASSWORD`) can authenticate as another user with the format `adminLogin&user@domain`, bypassing LDAP entirely.

## Security model

The mail attibute ACL should be write restricted and only user with administrative right could write it. It is usually the default on usual LDAP server.

To guarantee that ldapsearch returns a unique user entry, configure LDAP_BASE to point to the user container and set LDAP_FILTER to a filter that uniquely identifies the user (e.g., (uid=)). Both parameters must be defined to ensure that the search scope and filter together produce a single, unambiguous result.

Although the mail attribute is multi-valued (an entry may have several email addresses), every individual email address must be unique within the directory subtree defined by LDAP_BASE.
In other words, two different entries must not contain the same mail value, even if they each have multiple addresses.

Although the mail attribute accepts mixed‑case values, each email address must be treated as case‑insensitive. To ensure uniqueness within LDAP_BASE, it is recommended to normalize all mail values to lowercase before storing them.
sabre-esn authentification will enforce matching on lowercase

The mail attribute is defined as IA5String in RFC 2798, which restricts it to ASCII. UTF‑8 characters are not permitted in email addresses stored in mail attribute.
