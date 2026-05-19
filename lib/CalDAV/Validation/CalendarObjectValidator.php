<?php

namespace ESN\CalDAV\Validation;

use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\Component\VCalendar;

class CalendarObjectValidator {
    function validate(VCalendar $vCalendar) {
        $this->validateRecurrenceIdValueType($vCalendar);
    }

    private function validateRecurrenceIdValueType(VCalendar $vCalendar): void
    {
        foreach ($vCalendar->select('VEVENT') as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'}) || !isset($vevent->DTSTART)) {
                continue;
            }

            $recurrenceId = $vevent->{'RECURRENCE-ID'};
            $dtStart = $vevent->DTSTART;
            $recurrenceIdValueType = $recurrenceId->getValueType();
            $dtStartValueType = $dtStart->getValueType();

            if ($recurrenceIdValueType === $dtStartValueType) {
                continue;
            }

            throw new BadRequest(
                'Validation error in iCalendar (RFC 5545): RECURRENCE-ID value type ('.$recurrenceIdValueType.') must match DTSTART value type ('.$dtStartValueType.')'
            );
        }
    }
}
