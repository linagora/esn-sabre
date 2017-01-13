<?php

namespace ESN\CalDAV;

class CalendarHome extends \Sabre\CalDAV\CalendarHome {

    function getChild($name) {
        $child = parent::getChild($name);
        return $this->wrapCalendarForACLs($child);
    }

    protected function wrapCalendarForACLs($cal) {
        if ($cal instanceof \Sabre\CalDAV\SharedCalendar) {
            return new SharedCalendar($cal);
        }
        return $cal;
    }

}
