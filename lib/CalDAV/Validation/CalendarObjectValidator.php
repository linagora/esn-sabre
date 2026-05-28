<?php

namespace ESN\CalDAV\Validation;

use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;

class CalendarObjectValidator {
    private const DATE = 'DATE';
    private const DATE_TIME = 'DATE-TIME';
    private const UTC = 'UTC';
    private const FLOATING = 'FLOATING';
    private const TZID_PREFIX = 'TZID:';

    function validate(VCalendar $vCalendar) {
        $this->validateRRules($vCalendar);
        $this->validateRecurrenceIds($vCalendar);
        $this->validateExDates($vCalendar);
    }

    private function validateRRules(VCalendar $vCalendar): void
    {
        foreach ($vCalendar->select('VEVENT') as $vevent) {
            if (!isset($vevent->RRULE) || !isset($vevent->DTSTART)) {
                continue;
            }

            foreach ($vevent->select('RRULE') as $rrule) {
                $this->validateRRule($rrule->getParts(), $vevent->DTSTART);
            }
        }
    }

    private function validateRRule(array $parts, DateTimeProperty $dtStart): void
    {
        if (isset($parts['COUNT']) && isset($parts['UNTIL'])) {
            $this->fail('COUNT and UNTIL MUST NOT both be present in the same RRULE');
        }

        if (isset($parts['UNTIL'])) {
            $this->validateRRuleUntil($parts['UNTIL'], $dtStart);
        }
    }

    private function validateRRuleUntil($until, DateTimeProperty $dtStart): void
    {
        if (is_array($until)) {
            $this->fail('UNTIL MUST be a single '.self::DATE.' or '.self::DATE_TIME.' value');
        }

        $until = (string)$until;
        $untilValueType = $this->dateLikeValueType($until);

        if (!$untilValueType) {
            $this->fail('UNTIL value ('.$until.') must be a valid '.self::DATE.' or '.self::DATE_TIME.' value');
        }

        $this->assertValidDateLike('UNTIL', $until, $untilValueType);

        $dtStartValueType = $dtStart->getValueType();
        if ($untilValueType !== $dtStartValueType) {
            $this->fail('UNTIL value type ('.$untilValueType.') must match DTSTART value type ('.$dtStartValueType.')');
        }

        if ($untilValueType !== self::DATE_TIME) {
            return;
        }

        $untilForm = $this->dateTimeFormFromValue($until, $untilValueType);
        $dtStartForm = $this->dateTimeForm($dtStart);

        // RFC 5545 requires UTC UNTIL for UTC or TZID DTSTART values, unlike
        // RECURRENCE-ID/EXDATE where this validator enforces matching forms.
        if ($dtStartForm === self::UTC || str_starts_with($dtStartForm, self::TZID_PREFIX)) {
            if ($untilForm !== self::UTC) {
                $this->fail('UNTIL date-time form ('.$untilForm.') must be UTC when DTSTART date-time form is '.$dtStartForm);
            }

            return;
        }

        if ($untilForm !== $dtStartForm) {
            $this->fail('UNTIL date-time form ('.$untilForm.') must match DTSTART date-time form ('.$dtStartForm.')');
        }
    }

    private function validateRecurrenceIds(VCalendar $vCalendar): void
    {
        $mastersByUid = $this->mastersByUid($vCalendar);
        $seenRecurrenceIds = [];

        foreach ($vCalendar->select('VEVENT') as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                continue;
            }

            $uid = (string)$vevent->UID;
            $master = $mastersByUid[$uid] ?? null;
            $recurrenceId = $vevent->{'RECURRENCE-ID'};

            $this->assertRecurringMasterExists($uid, $master);

            if (isset($master->DTSTART)) {
                $this->assertSameDateTimePropertyShape('RECURRENCE-ID', $recurrenceId, $master->DTSTART);
            }

            $this->assertUniqueRecurrenceId($uid, $recurrenceId, $seenRecurrenceIds);
        }
    }

    private function validateExDates(VCalendar $vCalendar): void
    {
        foreach ($vCalendar->select('VEVENT') as $vevent) {
            if (!isset($vevent->EXDATE) || !isset($vevent->DTSTART)) {
                continue;
            }

            foreach ($vevent->select('EXDATE') as $exDate) {
                $this->assertSameDateTimePropertyShape('EXDATE', $exDate, $vevent->DTSTART);
            }
        }
    }

    private function mastersByUid(VCalendar $vCalendar): array
    {
        $mastersByUid = [];

        foreach ($vCalendar->select('VEVENT') as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'})) {
                continue;
            }

            $mastersByUid[(string)$vevent->UID] = $vevent;
        }

        return $mastersByUid;
    }

    private function isRecurringMaster($vevent): bool
    {
        return isset($vevent->RRULE) || isset($vevent->RDATE);
    }

    private function assertRecurringMasterExists(string $uid, $master): void
    {
        if (!$master || !$this->isRecurringMaster($master)) {
            $this->fail('RECURRENCE-ID override for UID '.$uid.' has no matching recurring master VEVENT (same UID with RRULE or RDATE)');
        }
    }

    private function assertUniqueRecurrenceId(string $uid, DateTimeProperty $recurrenceId, array &$seenRecurrenceIds): void
    {
        $key = $uid.'|'.$this->dateTimePropertyKey($recurrenceId);
        if (isset($seenRecurrenceIds[$key])) {
            $this->fail('Duplicate RECURRENCE-ID '.$this->displayDateTimePropertyValue($recurrenceId).' for UID '.$uid);
        }

        $seenRecurrenceIds[$key] = true;
    }

    private function assertSameDateTimePropertyShape(string $propertyName, DateTimeProperty $property, DateTimeProperty $dtStart): void
    {
        $this->assertSameValueType($propertyName, $property, $dtStart);
        $this->assertValidDateLikeParts($propertyName, $property);

        if ($property->getValueType() !== self::DATE_TIME) {
            return;
        }

        $this->assertSameDateTimeForm($propertyName, $property, $dtStart);
    }

    private function assertSameValueType(string $propertyName, DateTimeProperty $property, DateTimeProperty $dtStart): void
    {
        $propertyValueType = $property->getValueType();
        $dtStartValueType = $dtStart->getValueType();

        if ($propertyValueType !== $dtStartValueType) {
            $this->fail($propertyName.' value type ('.$propertyValueType.') must match DTSTART value type ('.$dtStartValueType.')');
        }
    }

    private function assertValidDateLikeParts(string $propertyName, DateTimeProperty $property): void
    {
        foreach ($property->getParts() as $value) {
            $this->assertValidDateLike($propertyName, $value, $property->getValueType());
        }
    }

    private function assertSameDateTimeForm(string $propertyName, DateTimeProperty $property, DateTimeProperty $dtStart): void
    {
        $dtStartForm = $this->dateTimeForm($dtStart);
        foreach ($property->getParts() as $value) {
            $propertyForm = $this->dateTimeForm($property, $value);

            if ($propertyForm !== $dtStartForm) {
                $this->fail($propertyName.' date-time form ('.$propertyForm.') must match DTSTART date-time form ('.$dtStartForm.')');
            }
        }
    }

    private function dateLikeValueType(string $value): ?string
    {
        if ($this->isBasicDateValue($value)) {
            return self::DATE;
        }

        if ($this->isBasicDateTimeValue($value)) {
            return self::DATE_TIME;
        }

        return null;
    }

    private function assertValidDateLike(string $name, string $value, string $valueType): void
    {
        if ($valueType === self::DATE) {
            if (!$this->isDateLikeValue($value, '!Ymd', 'Ymd')) {
                $this->fail($name.' value ('.$value.') is not a valid '.$valueType.' value');
            }

            return;
        }

        $format = str_ends_with($value, 'Z') ? '!Ymd\THis\Z' : '!Ymd\THis';
        $expectedFormat = str_ends_with($value, 'Z') ? 'Ymd\THis\Z' : 'Ymd\THis';
        if (!$this->isDateLikeValue($value, $format, $expectedFormat)) {
            $this->fail($name.' value ('.$value.') is not a valid '.$valueType.' value');
        }
    }

    private function isDateLikeValue(string $value, string $parseFormat, string $expectedFormat): bool
    {
        $dateTime = \DateTimeImmutable::createFromFormat(
            $parseFormat,
            $value,
            new \DateTimeZone('UTC')
        );

        return $dateTime && $dateTime->format($expectedFormat) === $value;
    }

    private function isBasicDateValue(string $value): bool
    {
        return strlen($value) === 8 && ctype_digit($value);
    }

    private function isBasicDateTimeValue(string $value): bool
    {
        $length = strlen($value);

        if ($length !== 15 && $length !== 16) {
            return false;
        }

        if ($value[8] !== 'T') {
            return false;
        }

        if ($length === 16 && $value[15] !== 'Z') {
            return false;
        }

        return ctype_digit(substr($value, 0, 8).substr($value, 9, 6));
    }

    private function dateTimeForm(DateTimeProperty $property, ?string $value = null): string
    {
        if ($property->getValueType() === self::DATE) {
            return self::DATE;
        }

        $value = $value ?: $property->getValue();
        if (str_ends_with($value, 'Z')) {
            return self::UTC;
        }

        $tzid = (string)$property['TZID'];
        if ($tzid !== '') {
            return self::TZID_PREFIX.$tzid;
        }

        return self::FLOATING;
    }

    private function dateTimeFormFromValue(string $value, string $valueType): string
    {
        if ($valueType === self::DATE) {
            return self::DATE;
        }

        return str_ends_with($value, 'Z') ? self::UTC : self::FLOATING;
    }

    private function dateTimePropertyKey(DateTimeProperty $property): string
    {
        return $this->dateTimeForm($property).'|'.implode(',', $property->getParts());
    }

    private function displayDateTimePropertyValue(DateTimeProperty $property): string
    {
        try {
            return implode(',', $property->getJsonValue());
        } catch (\Throwable) {
            return $property->getValue();
        }
    }

    private function fail(string $message): void
    {
        throw new BadRequest('Validation error in iCalendar (RFC 5545: '.$message.')');
    }
}
