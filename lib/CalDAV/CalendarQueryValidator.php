<?php
namespace ESN\CalDAV;

use DateTime;
use DateTimeImmutable;
use Sabre\VObject;
use Sabre\VObject\Component\VAlarm;
use Sabre\VObject\Component\VEvent;

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
        if (!$this->isAlarmOfRecurringEvent($component)) {
            return parent::validateTimeRange($component, $start, $end);
        }

        return $this->recurringAlarmIsInTimeRange(
            $component->parent,
            $start ?? new DateTime('1900-01-01'),
            $end ?? new DateTime('3000-01-01')
        );
    }

    /**
     * @param VObject\Node $component
     * @return bool
     */
    private function isAlarmOfRecurringEvent(VObject\Node $component) {
        if ($component->name !== 'VALARM') {
            return false;
        }

        return $component->parent instanceof VEvent && isset($component->parent->RRULE);
    }

    /**
     * Whether an alarm of any instance of the series of $master triggers in [$start, $end).
     *
     * @param VEvent $master
     * @param \DateTimeInterface $start
     * @param \DateTimeInterface $end
     * @return bool
     */
    private function recurringAlarmIsInTimeRange(VEvent $master, $start, $end) {
        $uid = (string) $master->UID;
        $alarms = $this->alarmsOf($this->eventsOfSeries($master->parent, $uid));

        // An absolute trigger does not depend on the instance: it is checked
        // once here, whatever instance carries it.
        $absoluteAlarms = array_filter($alarms, fn($alarm) => !$this->hasRelativeTrigger($alarm));
        if ($this->anyAlarmInTimeRange($absoluteAlarms, $start, $end)) {
            return true;
        }

        // A relative trigger is at most this long before the start of its
        // instance: an instance starting after the limit, like every later one,
        // cannot trigger before the end of the range.
        $relativeAlarms = array_filter($alarms, fn($alarm) => $this->hasRelativeTrigger($alarm));
        $maxLeadSeconds = max(array_merge([0], array_map(fn($alarm) => $this->triggerLeadSeconds($alarm), $relativeAlarms)));
        $limit = $end->getTimestamp() + $maxLeadSeconds + self::TRIGGER_LEAD_SLACK_SECONDS;

        return $this->anyInstanceAlarmInTimeRange(new VObject\Recur\EventIterator($master->parent, $uid), $start, $end, $limit);
    }

    /**
     * Whether a relative alarm of an instance starting before $limit triggers in [$start, $end).
     *
     * @param VObject\Recur\EventIterator $it
     * @param \DateTimeInterface $start
     * @param \DateTimeInterface $end
     * @param int $limit
     * @return bool
     */
    private function anyInstanceAlarmInTimeRange(VObject\Recur\EventIterator $it, $start, $end, $limit) {
        for (; $it->valid() && $it->getDtStart()->getTimestamp() <= $limit; $it->next()) {
            $relativeAlarms = array_filter(
                $this->alarmsOf([$it->getEventObject()]),
                fn($alarm) => $this->hasRelativeTrigger($alarm)
            );
            if ($this->anyAlarmInTimeRange($relativeAlarms, $start, $end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The VEVENTs of $calendar that have the UID $uid: the master and its overrides.
     *
     * @param VObject\Component $calendar
     * @param string $uid
     * @return VEvent[]
     */
    private function eventsOfSeries(VObject\Component $calendar, $uid) {
        return array_filter(
            $calendar->select('VEVENT'),
            // select() also returns a property named VEVENT.
            fn($event) => $event instanceof VEvent && (string) $event->UID === $uid
        );
    }

    /**
     * The alarms with a TRIGGER of $events.
     *
     * @param VObject\Component[] $events
     * @return VAlarm[]
     */
    private function alarmsOf(array $events) {
        $alarms = [];
        foreach ($events as $event) {
            $alarms = array_merge($alarms, $event->select('VALARM'));
        }

        return array_filter($alarms, fn($alarm) => $alarm instanceof VAlarm && isset($alarm->TRIGGER));
    }

    /**
     * @param VAlarm[] $alarms
     * @param \DateTimeInterface $start
     * @param \DateTimeInterface $end
     * @return bool
     */
    private function anyAlarmInTimeRange(array $alarms, $start, $end) {
        foreach ($alarms as $alarm) {
            if ($alarm->isInTimeRange($start, $end)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Same test as VAlarm::getEffectiveTriggerTime() for a trigger relative to its component.
     */
    private function hasRelativeTrigger(VAlarm $alarm) {
        $trigger = $alarm->TRIGGER;

        return !isset($trigger['VALUE']) || 'DURATION' === strtoupper((string) $trigger['VALUE']);
    }

    /**
     * How long before the start of its instance a relative trigger fires, 0
     * when it fires at or after it. A trigger related to the end fires no
     * earlier than its offset applied to the start.
     */
    private function triggerLeadSeconds(VAlarm $alarm) {
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
