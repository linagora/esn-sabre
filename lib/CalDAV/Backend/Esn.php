<?php

namespace ESN\CalDAV\Backend;

class Esn extends Mongo {

    public $EVENTS_URI = 'events';

    function getCalendarsForUser($principalUri) {
        $calendars = parent::getCalendarsForUser($principalUri);

        if (count($calendars) == 0) {
            // No calendars yet, inject our default calendars
            parent::createCalendar($principalUri, $this->EVENTS_URI, []);

            $calendars = parent::getCalendarsForUser($principalUri);
        }

        return $calendars;
    }
}
