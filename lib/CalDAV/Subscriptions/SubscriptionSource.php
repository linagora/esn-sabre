<?php

namespace ESN\CalDAV\Subscriptions;

/**
 * The single parser of a stored subscription source.
 *
 * A source is only valid as an exact calendars/{home}/{calendarUri} path (leading and
 * trailing slashes tolerated). Absolute URLs, query strings, fragments, empty or dot
 * segments are rejected, so that every reader of the source resolves the same calendar.
 */
class SubscriptionSource {

    const PREFIX = 'calendars';

    /**
     * @param string|null $source the stored source href
     * @return array|null [home, calendarUri], or null when the source is not a calendar path
     */
    static function parse($source) {
        if (!is_string($source) || $source === '') {
            return null;
        }

        $parts = explode('/', trim($source, '/'));

        return self::isCalendarPath($parts) ? [$parts[1], $parts[2]] : null;
    }

    private static function isCalendarPath(array $parts) {
        return self::hasCalendarShape($parts) && self::hasPlainSegments($parts);
    }

    private static function hasCalendarShape(array $parts) {
        return count($parts) === 3 && $parts[0] === self::PREFIX;
    }

    private static function hasPlainSegments(array $parts) {
        return !array_intersect($parts, ['', '.', '..'])
            && !preg_match('/[?#]/', implode('', $parts));
    }

    /**
     * @param string|null $source the stored source href
     * @return string|null the canonical calendars/{home}/{calendarUri} path, or null when invalid
     */
    static function toPath($source) {
        $parsed = self::parse($source);

        return $parsed === null ? null : self::path(...$parsed);
    }

    /**
     * @param string $home
     * @param string $calendarUri
     * @return string the canonical source path of a calendar
     */
    static function path($home, $calendarUri) {
        return self::PREFIX . '/' . $home . '/' . $calendarUri;
    }
}
