<?php
namespace ESN\CalDAV;

use DateTime;
use DateTimeImmutable;
use Sabre\VObject;

/**
 * Sabre's calendar-query validator, with the VALARM time-range on a recurring
 * event fixed (linagora/sabre-vobject#170).
 *
 * Sabre gives up as soon as one instance of the series has no relative alarm,
 * which is the case of an override written without its VALARM: the later
 * instances, whose reminders may fall in the range, are never looked at.
 * Here such an instance is skipped instead, and the search stops once the
 * instances start too late for any relative trigger to fall before the end of
 * the range.
 */
class CalendarQueryValidator extends \Sabre\CalDAV\CalendarQueryValidator {

    /**
     * Slack added to the largest trigger lead, so that a day made longer by a
     * DST change does not stop the search one instance too early.
     */
    const TRIGGER_LEAD_SLACK_SECONDS = 86400;

    protected function validateTimeRange(VObject\Node $component, $start, $end) {
        if ($component->name !== 'VALARM' || !$component->parent
            || $component->parent->name !== 'VEVENT' || !$component->parent->RRULE) {
            return parent::validateTimeRange($component, $start, $end);
        }

        if (is_null($start)) {
            $start = new DateTime('1900-01-01');
        }
        if (is_null($end)) {
            $end = new DateTime('3000-01-01');
        }

        return $this->recurringAlarmIsInTimeRange($component->parent, $start, $end);
    }

    /**
     * Whether an alarm of any instance of the series of $master triggers in [$start, $end).
     *
     * @param VObject\Component\VEvent $master
     * @param \DateTimeInterface $start
     * @param \DateTimeInterface $end
     * @return bool
     */
    private function recurringAlarmIsInTimeRange($master, $start, $end) {
        $calendar = $master->parent;
        $uid = (string) $master->UID;

        $events = [];
        foreach ($calendar->select('VEVENT') as $event) {
            if ($event instanceof VObject\Component && (string) $event->UID === $uid) {
                $events[] = $event;
            }
        }

        // An absolute trigger does not depend on the instance: it is checked
        // once here, whatever instance carries it.
        $maxLeadSeconds = 0;
        foreach ($events as $event) {
            foreach ($event->select('VALARM') as $alarm) {
                if (!$alarm instanceof VObject\Component\VAlarm || !isset($alarm->TRIGGER)) {
                    continue;
                }
                if ($this->hasRelativeTrigger($alarm)) {
                    $maxLeadSeconds = max($maxLeadSeconds, $this->triggerLeadSeconds($alarm));
                } elseif ($alarm->isInTimeRange($start, $end)) {
                    return true;
                }
            }
        }

        // A relative trigger is at most $maxLeadSeconds before the start of its
        // instance: an instance starting after this limit, like every later one,
        // cannot trigger before the end of the range.
        $limit = $end->getTimestamp() + $maxLeadSeconds + self::TRIGGER_LEAD_SLACK_SECONDS;

        $it = new VObject\Recur\EventIterator($calendar, $uid);
        while ($it->valid()) {
            if ($it->getDtStart()->getTimestamp() > $limit) {
                return false;
            }

            $expandedEvent = $it->getEventObject();
            foreach ($expandedEvent->select('VALARM') as $expandedAlarm) {
                if ($expandedAlarm instanceof VObject\Component\VAlarm
                    && isset($expandedAlarm->TRIGGER)
                    && $this->hasRelativeTrigger($expandedAlarm)
                    && $expandedAlarm->isInTimeRange($start, $end)) {
                    return true;
                }
            }

            $it->next();
        }

        return false;
    }

    /**
     * Same test as VAlarm::getEffectiveTriggerTime() for a trigger relative to its component.
     */
    private function hasRelativeTrigger(VObject\Component\VAlarm $alarm) {
        $trigger = $alarm->TRIGGER;

        return !isset($trigger['VALUE']) || 'DURATION' === strtoupper((string) $trigger['VALUE']);
    }

    /**
     * How long before the start of its instance a relative trigger fires, 0
     * when it fires at or after it. A trigger related to the end fires no
     * earlier than its offset applied to the start.
     */
    private function triggerLeadSeconds(VObject\Component\VAlarm $alarm) {
        try {
            $duration = VObject\DateTimeParser::parseDuration($alarm->TRIGGER);
        } catch (\Exception $e) {
            // The iteration reads it again and fails the same way.
            return 0;
        }

        $epoch = new DateTimeImmutable('@0');

        return max(0, -$epoch->add($duration)->getTimestamp());
    }
}
