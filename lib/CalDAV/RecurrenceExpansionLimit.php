<?php

namespace ESN\CalDAV;

use ESN\Utils\Env;
use Sabre\VObject\Settings;

/**
 * Upper bound on the number of occurrences sabre/vobject generates when
 * expanding a recurring event.
 *
 * Expansion always starts at DTSTART, so a long-running series (e.g. a daily
 * event running for more than ten years) exceeds the vobject default of 3500
 * before reaching the requested time range and silently yields no occurrence.
 *
 * Configured through SABRE_MAX_RECURRENCES:
 *   - a positive value is used as the limit
 *   - a negative value disables the limit altogether
 *   - unset, zero or unparseable values keep the default
 */
class RecurrenceExpansionLimit {

    const SETTING = 'SABRE_MAX_RECURRENCES';
    const DEFAULT_LIMIT = 3500;
    const UNLIMITED = -1;

    static function resolve(): int {
        $limit = Env::getInteger(self::SETTING, self::DEFAULT_LIMIT);

        if ($limit < 0) {
            return self::UNLIMITED;
        }

        return $limit === 0 ? self::DEFAULT_LIMIT : $limit;
    }

    static function apply(): void {
        Settings::$maxRecurrences = self::resolve();
    }
}
