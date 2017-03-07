<?php

namespace ESN\CalDAV;

class CalendarHome extends \Sabre\CalDAV\CalendarHome {

    function getChild($name) {
        return $this->wrapCalendarForACLs(parent::getChild($name));
    }

    function getChildren() {
        return array_map([$this, 'wrapCalendarForACLs'], parent::getChildren());
    }

    private function wrapCalendarForACLs($cal) {
        if ($cal instanceof \Sabre\CalDAV\SharedCalendar) {
            return new SharedCalendar($cal);
        }

        return $cal;
    }

}
