<?php

namespace ESN\CalDAV;

class CalendarHome extends \Sabre\CalDAV\CalendarHome {

    function getChild($name) {
        return $this->wrapCalendarForACLs(parent::getChild($name));
    }

    function getChildren() {
        return array_map([$this, 'wrapCalendarForACLs'], parent::getChildren());
    }

    /**
     * Deletes this object
     *
     * @return void
     */
    function delete() {

        $children = $this->getChildren();

        foreach ($children as $child) {
            if($child instanceof \Sabre\CalDAV\Calendar) {
                $child->delete();
            }
        }

    }

    private function wrapCalendarForACLs($cal) {
        if ($cal instanceof \Sabre\CalDAV\SharedCalendar) {
            return new SharedCalendar($cal);
        }

        return $cal;
    }

    function getACL() {
        return [
            [
                'privilege' => '{DAV:}read',
                'principal' => '{DAV:}authenticated',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => $this->principalInfo['uri'],
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-read',
                'protected' => true,
            ],

        ];
    }

    function getDuplicateCalendarObjectsByURI($uri) {
        return $this->caldavBackend->getDuplicateCalendarObjectsByURI($this->principalInfo['uri'], $uri);
    }
}
