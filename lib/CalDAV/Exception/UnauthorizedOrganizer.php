<?php

namespace ESN\CalDAV\Exception;

use Sabre\DAV\Exception\Forbidden;

/**
 * Raised when the ORGANIZER of a calendar object is not allowed to organize in the
 * target calendar.
 *
 * It is deliberately distinct from the plain Forbidden raised for structurally invalid
 * ORGANIZER properties, so that callers can react to an authorization failure without
 * also swallowing a malformed object.
 */
class UnauthorizedOrganizer extends Forbidden {
}
