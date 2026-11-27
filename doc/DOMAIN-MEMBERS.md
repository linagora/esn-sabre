# Domain Members Address Book

## ACL /addressbooks/{domainId}/domain-members

[RFC 3744](https://www.rfc-editor.org/rfc/rfc3744.html#section-8.1).
Requires a `TwakeCalendarToken` for the target domain,
`Content-Type: application/xml` and `Accept: application/xml`. Success: `200 OK`.

Revoke ordinary member access; domain administrators retain access:

```xml
<d:acl xmlns:d="DAV:"/>
```

Grant domain members read access:

```xml
<d:acl xmlns:d="DAV:">
  <d:ace>
    <d:principal><d:href>/principals/domains/{domainId}</d:href></d:principal>
    <d:grant><d:privilege><d:read/></d:privilege></d:grant>
  </d:ace>
</d:acl>
```

Cards are preserved; technical-token access and synchronization continue.
The default `DAV:acl` grants member access to the domain principal, so it follows
current membership without listing individual users. To restore access for all
current members, submit the domain read grant above. Explicit grants to individual
users remain limited to those users and do not expand when new members join.
