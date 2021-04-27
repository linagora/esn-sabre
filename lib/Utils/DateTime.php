<?php

namespace ESN\Utils;

use Sabre\VObject\Component\VEvent;

class DateTime {

    /**
     * Computes the duration of a VEvent object.
     *
     * @param VEvent $event
     *
     * @return int
     */
    static function computeVEventDuration($event) {
        $eventDuration = 0;
        $start = $event->DTSTART->getDateTime();

        if (isset($event->DTEND)) {
            return $event->DTEND->getDateTime()->getTimeStamp() -
                $start->getTimeStamp();
        }

        if (isset($event->DURATION)) {
            $duration = $event->DURATION->getDateInterval();
            $end = clone $start;
            $end = $end->add($duration);

            return $end->getTimeStamp() - $start->getTimeStamp();
        }

        if (!$event->DTSTART->hasTime()) {
            return 3600 * 24;
        }

        return $eventDuration;
    }
    
}
