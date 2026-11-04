<?php

namespace ESN\JSON\CalDAV;

use Sabre\VObject;

/**
 * Reads the "match" time range of a JSON REPORT body.
 */
class TimeRange {

    /**
     * iCalendar DATE-TIME as the CalDAV time-range attributes take it.
     */
    private const DATE_TIME = '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/';

    /**
     * Returns [start, end] as \DateTimeImmutable, or null when the body has
     * no "match" object, or when its start or end is not an iCalendar
     * DATE-TIME naming an existing instant (20150229T000000Z,
     * 20150101T250000Z): such a value is a client error, never a reason
     * to answer 500 nor to be rolled over to another day.
     *
     * @param mixed $jsonData decoded JSON body
     * @return \DateTimeImmutable[]|null
     */
    public static function fromJson($jsonData) {
        if (!is_object($jsonData) || !isset($jsonData->match) || !is_object($jsonData->match)) {
            return null;
        }

        $start = self::parse($jsonData->match->start ?? null);
        $end = self::parse($jsonData->match->end ?? null);

        return $start === null || $end === null ? null : [$start, $end];
    }

    /**
     * @param mixed $value
     * @return \DateTimeImmutable|null
     */
    public static function parse($value) {
        if (!is_string($value) || !preg_match(self::DATE_TIME, $value, $m)) {
            return null;
        }

        list(, $year, $month, $day, $hour, $minute, $second) = array_map('intval', $m);
        if (!checkdate($month, $day, $year) || $hour > 23 || $minute > 59 || $second > 59) {
            return null;
        }

        try {
            return VObject\DateTimeParser::parseDateTime($value);
        } catch (VObject\InvalidDataException $e) {
            return null;
        }
    }
}
