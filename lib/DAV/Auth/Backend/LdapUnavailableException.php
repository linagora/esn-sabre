<?php

namespace ESN\DAV\Auth\Backend;

/**
 * The LDAP directory could not be queried (server down, timeout, broken
 * service account...). Surfaces as a 503 rather than a 401, so that an
 * infrastructure failure is not mistaken for bad credentials.
 */
class LdapUnavailableException extends \Sabre\DAV\Exception\ServiceUnavailable {}
