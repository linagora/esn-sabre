<?php

namespace ESN\CalDAV;

use Sabre\DAV;

/**
 * Raised when a recurring event cannot be expanded over the requested time
 * range because sabre/vobject would generate more occurrences than allowed
 * by RecurrenceExpansionLimit before reaching it.
 *
 * Answered as 422 Unprocessable Content: the request is well formed, but the
 * stored series is too long to be expanded by this server.
 */
class RecurrenceExpansionLimitExceeded extends DAV\Exception {

    function __construct(\Throwable $previous) {
        parent::__construct(
            'The recurring event generates too many occurrences before the requested time range to be expanded ('
                . $previous->getMessage() . ')',
            0,
            $previous
        );
    }

    function getHTTPCode() {
        return 422;
    }
}
