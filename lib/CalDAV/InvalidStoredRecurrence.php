<?php

namespace ESN\CalDAV;

use Sabre\DAV;

/**
 * Raised when a stored recurring event cannot be expanded because sabre/vobject
 * refuses its recurrence data, e.g. an RRULE with WKST=2 delivered by iTIP
 * before strict validation rejected such rules.
 *
 * Answered as 422 Unprocessable Content: the request is well formed, but the
 * stored event cannot be expanded by this server.
 */
class InvalidStoredRecurrence extends DAV\Exception {

    function __construct(\Throwable $previous) {
        parent::__construct(
            'The stored recurring event cannot be expanded (' . $previous->getMessage() . ')',
            0,
            $previous
        );
    }

    function getHTTPCode() {
        return 422;
    }
}
