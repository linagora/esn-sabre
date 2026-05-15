<?php

namespace ESN\CalDAV\Validation;

use Sabre\DAV\Exception\BadRequest;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\InvalidDataException;
use Sabre\VObject\Recur\RRuleIterator;

class CalendarObjectValidator {

    public function validate(VCalendar $vCalendar): void {
        $this->validateRecurrenceRules($vCalendar);
        $this->validateRecurrenceIds($vCalendar);
    }

    private function validateRecurrenceRules(VCalendar $vCalendar): void {
        foreach ($vCalendar->select('VEVENT') as $vevent) {
            if (!isset($vevent->RRULE)) {
                continue;
            }

            if (!isset($vevent->DTSTART)) {
                continue;
            }

            try {
                if (count($vevent->RRULE) > 1) {
                    throw new BadRequest('Invalid calendar object: multiple RRULE properties');
                }

                foreach ($vevent->RRULE as $rrule) {
                    $rruleParts = $rrule->getParts();
                    if (isset($rruleParts['COUNT']) && isset($rruleParts['UNTIL'])) {
                        throw new BadRequest('Invalid calendar object: RRULE must not contain both COUNT and UNTIL');
                    }

                    new RRuleIterator($rruleParts, $vevent->DTSTART->getDateTime());
                }
            } catch (BadRequest $e) {
                throw $e;
            } catch (InvalidDataException $e) {
                throw new BadRequest('Invalid calendar object: invalid RRULE: ' . $e->getMessage());
            } catch (\Throwable $e) {
                throw new BadRequest('Invalid calendar object: invalid RRULE');
            }
        }
    }

    private function validateRecurrenceIds(VCalendar $vCalendar): void {
        $recurrenceIdsByUid = [];

        foreach ($vCalendar->select('VEVENT') as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                continue;
            }

            $uid = (string) $vevent->UID;
            $recurrenceId = $vevent->{'RECURRENCE-ID'};

            try {
                $recurrenceId->getDateTime();
            } catch (InvalidDataException $e) {
                throw new BadRequest('Invalid calendar object: invalid RECURRENCE-ID: ' . $e->getMessage());
            } catch (\Throwable $e) {
                throw new BadRequest('Invalid calendar object: invalid RECURRENCE-ID');
            }

            $key = $this->recurrenceIdKey($recurrenceId);
            if (isset($recurrenceIdsByUid[$uid][$key])) {
                throw new BadRequest('Invalid calendar object: duplicate RECURRENCE-ID for UID ' . $uid);
            }

            $recurrenceIdsByUid[$uid][$key] = true;
        }
    }

    private function recurrenceIdKey($recurrenceId): string {
        $valueType = isset($recurrenceId['VALUE']) ? strtoupper((string) $recurrenceId['VALUE']) : 'DATE-TIME';
        $timezoneId = isset($recurrenceId['TZID']) ? (string) $recurrenceId['TZID'] : '';

        return $valueType . "\n" . $timezoneId . "\n" . $recurrenceId->getValue();
    }
}
