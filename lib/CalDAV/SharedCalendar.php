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

        $acl = $this->updateAclWithPublicRight($acl);

        return $acl;
    }

    private function updateAclWithPublicRight($acl) {
        $public_right = $this->getPublicRight();

        if (isset($public_right)) {
            $index = array_search('{DAV:}authenticated', array_column($acl, 'principal'));
            if ($index) {
                $acl[$index]['privilege'] = $public_right;

                if ($public_right === '{DAV:}write') {
                    $acl[] = [
                        'privilege' => '{DAV:}read',
                        'principal' => '{DAV:}authenticated',
                        'protected' => true,
                    ];
                }
            }
        }

        return $acl;

    }

    function isPublic() {

        $public = $this->getPublicRight();

        return isset($public);

    }

    function getPublicRight() {

        return $this->caldavBackend->getCalendarPublicRight($this->calendarInfo['id']);

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

        $authenticatedACE = [];
        foreach ($acl as &$ace) {
            if ($ace['principal'] === '{DAV:}authenticated') {
                $authenticatedACE[] = $ace;
            }
        }

        if (isset($authenticatedACE) && count($authenticatedACE) > 0) {
            return array_merge($childACL, $authenticatedACE);
        }

        return $childACL;
    }

    function savePublicRight($privilege) {

        $this->caldavBackend->saveCalendarPublicRight($this->calendarInfo['id'], $privilege);

    }

    function getCalendarId() {

        return $this->calendarInfo['id'][0];

    }

    function getSubscribers($options = null) {
        $principalUriExploded = explode('/', $this->calendarInfo['principaluri']);
        if($options) {
            $source = $options['baseUri'] . 'calendars/' . $principalUriExploded[2] . '/' . $this->calendarInfo['uri'] . $options['extension'];
        } else {
            $source = '/calendars/' . $principalUriExploded[2] . '/' . $this->calendarInfo['uri'];
        }

        return $this->caldavBackend->getSubscribers($source);

    }

    function getInviteStatus() {

        return $this->calendarInfo['share-invitestatus'];

    }

}
