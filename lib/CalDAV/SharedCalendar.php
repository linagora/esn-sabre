<?php

namespace ESN\CalDAV;

use ESN\DAV\Sharing\Plugin as SPlugin;

class SharedCalendar extends \Sabre\CalDAV\SharedCalendar {

    function __construct(\Sabre\CalDAV\SharedCalendar $sharedCalendar) {
        parent::__construct($sharedCalendar->caldavBackend, $sharedCalendar->calendarInfo);
    }

    /**
     * Returns a list of ACE's for this node.
     *
     * Each ACE has the following properties:
     *   * 'privilege', a string such as {DAV:}read or {DAV:}write. These are
     *     currently the only supported privileges
     *   * 'principal', a url to the principal who owns the node
     *   * 'protected' (optional), indicating that this ACE is not allowed to
     *      be updated.
     *
     * @return array
     */
    function getACL() {
        $acl = parent::getACL();

        switch ($this->getShareAccess()) {
            case SPlugin::ACCESS_ADMINISTRATION :
                $acl[] = [
                    'privilege' => '{DAV:}read',
                    'principal' => $this->calendarInfo['principaluri'],
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}read',
                    'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-read',
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}read',
                    'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-write',
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}write',
                    'principal' => $this->calendarInfo['principaluri'],
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}write',
                    'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-write',
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}write-properties',
                    'principal' => $this->calendarInfo['principaluri'],
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}write-properties',
                    'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-write',
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}read-acl',
                    'principal' => $this->calendarInfo['principaluri'],
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}read-acl',
                    'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-write',
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}write-acl',
                    'principal' => $this->calendarInfo['principaluri'],
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}write-acl',
                    'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-write',
                    'protected' => true,
                ];
                // No break intentional!
            case SPlugin::ACCESS_FREEBUSY :
                $acl[] = [
                    'privilege' => '{' . Plugin::NS_CALDAV . '}read-free-busy',
                    'principal' => '{DAV:}authenticated',
                    'protected' => true,
                ];
                break;
        }

        $public_right = $this->caldavBackend->getCalendarPublicRight($this->calendarInfo['id']);

        if (isset($public_right)) {
            foreach ($acl as &$ace) {
                // we know it will exist since it is hardcoded in Sabre\CalDAV\SharedCalendar
                if ($ace['principal'] === '{DAV:}authenticated') {
                    $ace['privilege'] = $public_right;
                    break;
                }
            }
        }

        return $acl;
    }

    /**
     * This method returns the ACL's for calendar objects in this calendar.
     * The result of this method automatically gets passed to the
     * calendar-object nodes in the calendar.
     *
     * @return array
     */
    function getChildACL() {
        $childACL = parent::getChildACL();

        if ($this->getShareAccess() == SPlugin::ACCESS_ADMINISTRATION) {
            $childACL[] = [
                'privilege' => '{DAV:}write',
                'principal' => $this->calendarInfo['principaluri'],
                'protected' => true,
            ];
            $childACL[] = [
                'privilege' => '{DAV:}write',
                'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-write',
                'protected' => true,
            ];
            $childACL[] = [
                'privilege' => '{DAV:}read',
                'principal' => $this->calendarInfo['principaluri'],
                'protected' => true,
            ];
            $childACL[] = [
                'privilege' => '{DAV:}read',
                'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-write',
                'protected' => true,
            ];
            $childACL[] = [
                'privilege' => '{DAV:}read',
                'principal' => $this->calendarInfo['principaluri'] . '/calendar-proxy-read',
                'protected' => true,
            ];
        }

        $acl = $this->getACL();

        $authenticatedACE = null;
        foreach ($acl as &$ace) {
            if ($ace['principal'] === '{DAV:}authenticated') {
                $authenticatedACE = $ace;
            }
        }

        if (isset($authenticatedACE)) {
            array_push($childACL, $authenticatedACE);
        }

        return $childACL;
    }

    function savePublicRight($privilege) {
        $this->caldavBackend->saveCalendarPublicRight($this->calendarInfo['id'], $privilege);
    }

    function getCalendarId() {
        return $this->calendarInfo['id'][0];
    }
}
