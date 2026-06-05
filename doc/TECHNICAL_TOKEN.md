# Technical Token Authentication

Technical tokens are used for service-to-service calls to `esn-sabre`.
They are not for end-user authentication.

## Why technical tokens

Technical tokens may perform operations that normal users cannot perform.
This is required for domain-level automation such as creating resources or
consolidating the domain member address book, etc.

## Authentication flow

Clients authenticate with a `TwakeCalendarToken` HTTP header:

```http
TwakeCalendarToken: <technical-token>
```

`esn-sabre` does not validate claims embedded in this token locally. Instead,
the DAV authentication backend validates the token through the configured
introspection endpoint:

```http
GET <token-issuer-url>/api/technicalToken/introspect
X-TECHNICAL-TOKEN: <technical-token>
```

`<token-issuer-url>` is the configured token issuer URL. In the current
deployment, it is the `esn.apiRoot` value and points to the Calendar side
service.

The introspection response defines the authenticated tenant.
For a technical token, it must identify the technical user and its tenant
domain:

```json
{
  "_id": "technical-user-id",
  "domainId": "domain-id",
  "domain": "example.com",
  "user_type": "technical"
}
```

- `_id`: the technical identifier
- `domainId`: the domain id
- `domain`: the domain name associated with `domainId`
- `user_type`: must be `technical`

The token issuer (currently the Calendar side service) controls token creation,
lifetime, revocation, and validation policy. `esn-sabre` acts as the validator:
it uses the tenant identity returned by introspection.

## Principal and tenant mapping

After a successful introspection, `esn-sabre` keeps the technical user id and
the domain scope in the authenticated request tenant.

For SabreDAV ACL checks, all technical tenants authenticate as the technical
principal:

```text
principals/technicalUser
```

This principal is used for DAV ACL checks. The domain scope remains in the
request `AuthTenant`.

## Domain isolation

Technical token authentication is domain-scoped. A token introspected for domain A must
not be used to view, update, or delete CalDAV or CardDAV resources belonging to
domain B.
