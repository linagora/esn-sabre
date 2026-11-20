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
 * the range. The instances that end too early for any relative trigger to fall
 * after the start of the range are passed over without being materialised.
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

        // Likewise a relative trigger, repetitions included, is at most this
        // long after the start of its instance: an instance starting before
        // $from cannot trigger after the start of the range.
        $maxLagSeconds = $this->maxTriggerLagSeconds($this->eventsOfSeries($master->parent, $uid), $relativeAlarms);
        $from = $maxLagSeconds === null
            ? PHP_INT_MIN
            : $start->getTimestamp() - $maxLagSeconds - self::TRIGGER_LEAD_SLACK_SECONDS;

        return $this->anyInstanceAlarmInTimeRange(new VObject\Recur\EventIterator($master->parent, $uid), $start, $end, $from, $limit);
    }

    /**
     * Whether a relative alarm of an instance starting in [$from, $limit] triggers in [$start, $end).
     *
     * @param VObject\Recur\EventIterator $it
     * @param \DateTimeInterface $start
     * @param \DateTimeInterface $end
     * @param int $from
     * @param int $limit
     * @return bool
     */
    private function anyInstanceAlarmInTimeRange(VObject\Recur\EventIterator $it, $start, $end, $from, $limit) {
        for (; $it->valid() && ($instanceStart = $it->getDtStart()->getTimestamp()) <= $limit; $it->next()) {
            if ($instanceStart < $from) {
                // Neither cloned nor evaluated: it still counts against the
                // iterator's Settings::$maxRecurrences.
                continue;
            }
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

    /**
     * How long after the start of its instance a relative alarm of the series
     * fires at the latest: the longest instance (a trigger may be related to
     * the end), plus the largest positive offset and repetition span. Null
     * when a value cannot be read, so that no instance is passed over and the
     * evaluation fails as it would without the bound.
     *
     * @param VEvent[] $events the master and its overrides
     * @param VAlarm[] $relativeAlarms
     * @return int|null
     */
    private function maxTriggerLagSeconds(array $events, array $relativeAlarms) {
        try {
            $maxLength = max(array_merge([0], array_map(fn($event) => $this->lengthSeconds($event), $events)));
            $maxAlarmLag = max(array_merge([0], array_map(fn($alarm) => $this->triggerLagSeconds($alarm), $relativeAlarms)));
        } catch (\Exception $e) {
            return null;
        }

        return $maxLength + $maxAlarmLag;
    }

    /**
     * The length of $event as EventIterator reads it, a day for a date
     * without an end.
     */
    private function lengthSeconds(VEvent $event) {
        if (!isset($event->DTSTART)) {
            return 0;
        }
        if (isset($event->DTEND)) {
            return max(0, $event->DTEND->getDateTime()->getTimestamp() - $event->DTSTART->getDateTime()->getTimestamp());
        }
        if (isset($event->DURATION)) {
            return max(0, $this->durationSeconds((string) $event->DURATION));
        }

        return $event->DTSTART->hasTime() ? 0 : 86400;
    }

    /**
     * How long after its anchor a relative alarm fires at the latest, 0 when
     * it fires before it: its positive offset, plus its REPEAT x DURATION.
     */
    private function triggerLagSeconds(VAlarm $alarm) {
        $lag = max(0, $this->durationSeconds((string) $alarm->TRIGGER));

        if (isset($alarm->DURATION)) {
            // Same reading as VAlarm::isInTimeRange().
            $repeat = (string) $alarm->REPEAT;
            $lag += max(0, ((int) ($repeat ?: 1)) * $this->durationSeconds((string) $alarm->DURATION));
        }

        return $lag;
    }

    private function durationSeconds($duration) {
        $epoch = new DateTimeImmutable('@0');

        return $epoch->add(VObject\DateTimeParser::parseDuration($duration))->getTimestamp();
    }
}
