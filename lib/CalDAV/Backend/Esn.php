<?php

namespace ESN\CalDAV\Backend;

class Esn extends Mongo {

    const EVENTS_URI = 'events';

    function getCalendarsForUser($principalUri) {
        $calendars = parent::getCalendarsForUser($principalUri);

        if (count($calendars) == 0) {
            // No calendars yet, inject our default calendars
            $principalExploded = explode('/', $principalUri);
            parent::createCalendar($principalUri, $principalExploded[2], []);

            $calendars = parent::getCalendarsForUser($principalUri);
        }

        return $calendars;
    }
}
