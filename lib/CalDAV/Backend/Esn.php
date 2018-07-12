<?php

namespace ESN\CalDAV\Backend;

class Esn extends Mongo {

    const EVENTS_URI = 'events';

    function getCalendarsForUser($principalUri) {
        $principalExploded = explode('/', $principalUri);
        
        parent::createDefaultCalendar($principalUri, $principalExploded[2]);
        $calendars = parent::getCalendarsForUser($principalUri);

        return $calendars;
    }
}
