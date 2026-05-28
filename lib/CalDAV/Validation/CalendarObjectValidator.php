<?php

namespace ESN\CalDAV\Validation;

use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Property\ICalendar\DateTime as DateTimeProperty;

class CalendarObjectValidator {
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
            $this->fail('UNTIL MUST be a single '.DateLikeValue::DATE.' or '.DateLikeValue::DATE_TIME.' value');
        }

        $untilValue = DateLikeValue::fromUntil($until);
        $dtStartValue = DateLikeValue::fromProperty($dtStart);

        $this->assertKnownDateLikeValue($untilValue);
        $this->assertValidDateLike($untilValue);
        $this->assertSameValueType($untilValue, $dtStartValue);
        $this->assertRRuleUntilDateTimeForm($untilValue, $dtStartValue);
    }

    private function assertRRuleUntilDateTimeForm(DateLikeValue $untilValue, DateLikeValue $dtStartValue): void
    {
        // RFC 5545 requires UTC UNTIL for UTC or TZID DTSTART values, unlike
        // RECURRENCE-ID/EXDATE where this validator enforces matching forms.
        if (!$untilValue->isDateTime()) {
            return;
        }

        if ($dtStartValue->requiresUtcUntil()) {
            if (!$untilValue->isUtc()) {
                $this->fail('UNTIL date-time form ('.$untilValue->form().') must be UTC when DTSTART date-time form is '.$dtStartValue->form());
            }

            return;
        }

        if ($untilValue->form() !== $dtStartValue->form()) {
            $this->fail('UNTIL date-time form ('.$untilValue->form().') must match DTSTART date-time form ('.$dtStartValue->form().')');
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
                $this->assertSameDateTimePropertyShape($recurrenceId, $master->DTSTART);
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
                $this->assertSameDateTimePropertyShape($exDate, $vevent->DTSTART);
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

    private function assertRecurringMasterExists($uid, $master): void
    {
        if (!$master || !$this->isRecurringMaster($master)) {
            $this->fail('RECURRENCE-ID override for UID '.$uid.' has no matching recurring master VEVENT (same UID with RRULE or RDATE)');
        }
    }

    private function assertUniqueRecurrenceId($uid, DateTimeProperty $recurrenceId, array &$seenRecurrenceIds): void
    {
        $key = $uid.'|'.$this->dateTimePropertyKey($recurrenceId);
        if (isset($seenRecurrenceIds[$key])) {
            $this->fail('Duplicate RECURRENCE-ID '.$this->displayDateTimePropertyValue($recurrenceId).' for UID '.$uid);
        }

        $seenRecurrenceIds[$key] = true;
    }

    private function assertSameDateTimePropertyShape(DateTimeProperty $property, DateTimeProperty $dtStart): void
    {
        $this->assertSamePropertyValueType($property, $dtStart);
        $this->assertValidDateLikeParts($property);

        if ($property->getValueType() !== DateLikeValue::DATE_TIME) {
            return;
        }

        $this->assertSameDateTimeForm($property, $dtStart);
    }

    private function assertSamePropertyValueType(DateTimeProperty $property, DateTimeProperty $dtStart): void
    {
        $this->assertSameValueType(DateLikeValue::fromProperty($property), DateLikeValue::fromProperty($dtStart));
    }

    private function assertSameValueType(DateLikeValue $value, DateLikeValue $dtStartValue): void
    {
        if ($value->valueType() !== $dtStartValue->valueType()) {
            $this->fail($value->name().' value type ('.$value->valueType().') must match DTSTART value type ('.$dtStartValue->valueType().')');
        }
    }

    private function assertValidDateLikeParts(DateTimeProperty $property): void
    {
        foreach ($property->getParts() as $value) {
            $this->assertValidDateLike(DateLikeValue::fromProperty($property, $value));
        }
    }

    private function assertSameDateTimeForm(DateTimeProperty $property, DateTimeProperty $dtStart): void
    {
        $dtStartValue = DateLikeValue::fromProperty($dtStart);
        foreach ($property->getParts() as $value) {
            $propertyValue = DateLikeValue::fromProperty($property, $value);

            if ($propertyValue->form() !== $dtStartValue->form()) {
                $this->fail($propertyValue->name().' date-time form ('.$propertyValue->form().') must match DTSTART date-time form ('.$dtStartValue->form().')');
            }
        }
    }

    private function assertKnownDateLikeValue(DateLikeValue $value): void
    {
        if (!$value->isKnown()) {
            $this->fail($value->name().' value ('.$value->value().') must be a valid '.DateLikeValue::DATE.' or '.DateLikeValue::DATE_TIME.' value');
        }
    }

    private function assertValidDateLike(DateLikeValue $value): void
    {
        if (!$value->isValid()) {
            $this->fail($value->name().' value ('.$value->value().') is not a valid '.$value->valueType().' value');
        }
    }

    private function dateTimePropertyKey(DateTimeProperty $property): string
    {
        return DateLikeValue::fromProperty($property)->form().'|'.implode(',', $property->getParts());
    }

    private function displayDateTimePropertyValue(DateTimeProperty $property): string
    {
        try {
            return implode(',', $property->getJsonValue());
        } catch (\Throwable) {
            return $property->getValue();
        }
    }

    private function fail($message): void
    {
        throw new BadRequest('Validation error in iCalendar (RFC 5545: '.$message.')');
    }
}
