<?php

namespace ESN\DAV\Auth\Backend;

/**
 * nginx authenticated a Kerberos principal, but Sabre refuses it (realm not
 * allowed, no matching user...).
 *
 * Answered with a 403 rather than a 401: a 401 carries a Negotiate challenge,
 * and a client that just presented a valid ticket would send it again forever.
 */
class KerberosRejectedException extends AuthException {}
