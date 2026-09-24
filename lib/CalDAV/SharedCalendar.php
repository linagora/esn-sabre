<?php

namespace ESN\CalDAV;

use ESN\DAV\Sharing\Plugin as SPlugin;
use ESN\Utils\Utils;

#[\AllowDynamicProperties]
class SharedCalendar extends \Sabre\CalDAV\SharedCalendar {
    const PUBLIC_RIGHTS = [
        '{DAV:}all',
        '{DAV:}read',
        '{DAV:}write',
        '{' . Plugin::NS_CALDAV . '}read-free-busy'
    ];

    /**
     * Sharees of this calendar, read once per node: the ACL of the calendar and of its objects,
     * and getOwner(), all need them.
     */
    private $invites = null;

    function __construct(\Sabre\CalDAV\SharedCalendar $sharedCalendar) {
        parent::__construct($sharedCalendar->caldavBackend, $sharedCalendar->calendarInfo);
    }

    function getInvites() {
        if ($this->invites === null) {
            $this->invites = parent::getInvites();
        }

        return $this->invites;
    }

    function updateInvites(array $sharees) {
        $this->invites = null;
        parent::updateInvites($sharees);
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
        return $this->buildACL(true);
    }

    /**
     * The ACL reported to clients in the JSON API. The read ACEs of the sharees on the owner's instance of a
     * user calendar are left out: clients list the sharees from the invites, and compute the rights of the
     * current user and the public right from this ACL.
     *
     * @return array
     */
    function getReportedACL() {
        return $this->buildACL(Utils::isTeamCalendarFromPrincipal($this->getOwner()));
    }

    private function buildACL(bool $withShareeReadAces) {
        $acl = parent::getACL();

        if ($withShareeReadAces) {
            $acl = $this->appendShareeReadAces($acl);
        }
        $acl = $this->appendSourceCalendarDelegateWriteAces($acl);

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
                    'privilege' => '{DAV:}share',
                    'principal' => $this->calendarInfo['principaluri'],
                    'protected' => true,
                ];
                $acl[] = [
                    'privilege' => '{DAV:}share',
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

        if (isset($public_right) && strlen($public_right) > 0) {
            $index = array_search('{DAV:}authenticated', array_column($acl, 'principal'));
            if ($index !== false) {
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

        return in_array($public, self::PUBLIC_RIGHTS);

    }

    function getPublicRight() {

        return $this->caldavBackend->getCalendarPublicRight($this->calendarInfo['id']);

    }

    /**
     * Sabre computes the child ACL once per calendar object. Here that computation reads the calendar sharing
     * state from the database, so it is computed once for the whole listing.
     */
    function getChildren() {
        return $this->asCalendarObjects($this->caldavBackend->getCalendarObjects($this->calendarInfo['id']));
    }

    function getMultipleChildren(array $paths) {
        return $this->asCalendarObjects($this->caldavBackend->getMultipleCalendarObjects($this->calendarInfo['id'], $paths));
    }

    private function asCalendarObjects(array $objs) {
        if (empty($objs)) {
            return [];
        }

        $childACL = $this->getChildACL();
        $children = [];
        foreach ($objs as $obj) {
            $obj['acl'] = $childACL;
            $children[] = new \Sabre\CalDAV\CalendarObject($this->caldavBackend, $this->calendarInfo, $obj);
        }

        return $children;
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

        $childACL = $this->appendShareeReadAces($childACL);
        $childACL = $this->appendSourceCalendarDelegateWriteAces($childACL);

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
        $calendarInfo = [];
        $calendarInfo['principaluri'] = $this->calendarInfo['principaluri'];
        $calendarInfo['uri'] = $this->calendarInfo['uri'];

        $this->caldavBackend->saveCalendarPublicRight($this->calendarInfo['id'], $privilege, $calendarInfo);

    }

    function getCalendarId() {

        return $this->calendarInfo['id'][0];

    }

    function getFullCalendarId() {

        return $this->calendarInfo['id'];

    }

    function getSubscribers() {
        $principalUriExploded = explode('/', $this->calendarInfo['principaluri']);
        $source = 'calendars/' . $principalUriExploded[2] . '/' . $this->calendarInfo['uri'];

        return $this->caldavBackend->getSubscribers($source);

    }


    function getInviteStatus() {

        return $this->calendarInfo['share-invitestatus'];

    }

    function updateInviteStatus($status) {

        $this->invites = null;
        $this->caldavBackend->saveCalendarInviteStatus($this->calendarInfo['id'], $status);

    }

    function isSharedInstance() {

        return $this->getShareAccess() !== SPlugin::ACCESS_SHAREDOWNER && $this->getShareAccess() !== SPlugin::ACCESS_NOTSHARED;

    }

    function getOwner() {
        $sharees = $this->getInvites();

        foreach ($sharees as $sharee) {
            if ($sharee->access === SPlugin::ACCESS_SHAREDOWNER) {
                return $sharee->principal;
            }
        }

        // Fallback to parent implementation for non-shared or public calendars
        // This ensures getOwner() always returns the principaluri
        return parent::getOwner();
    }

    function getBackend() {
        return $this->caldavBackend;
    }

    private function appendSourceCalendarDelegateWriteAces(array $acl) {
        if (!$this->shouldAppendSourceCalendarDelegateWriteAces()) {
            return $acl;
        }

        foreach ($this->getInvites() as $sharee) {
            if (!$this->isWriteEnabledAccess((int) $sharee->access) || !$sharee->principal) {
                continue;
            }

            $acl = $this->appendAce($acl, '{DAV:}read', $sharee->principal);
            $acl = $this->appendAce($acl, '{DAV:}write', $sharee->principal);
        }

        return $acl;
    }

    /**
     * Sharees read the owner's instance: the JSON listing advertises it to them as
     * calendarserver:delegatedsource, and team calendars are only reachable there. Read only: sharees of
     * a user calendar write through their own instance.
     */
    private function appendShareeReadAces(array $acl) {
        if ($this->isSharedInstance()) {
            return $acl;
        }

        foreach ($this->getInvites() as $sharee) {
            if ($this->isReadEnabledShare($sharee)) {
                $acl = $this->appendAce($acl, '{DAV:}read', $sharee->principal);
            }
        }

        return $acl;
    }

    private function appendAce(array $acl, $privilege, $principal) {
        $ace = [
            'privilege' => $privilege,
            'principal' => $principal,
            'protected' => true,
        ];

        if (!in_array($ace, $acl, true)) {
            $acl[] = $ace;
        }

        return $acl;
    }

    private function isReadEnabledShare($sharee): bool {
        return in_array((int) $sharee->access, [SPlugin::ACCESS_READ, SPlugin::ACCESS_READWRITE, SPlugin::ACCESS_ADMINISTRATION], true)
            && (int) $sharee->inviteStatus !== SPlugin::INVITE_DECLINED
            && !empty($sharee->principal);
    }

    private function shouldAppendSourceCalendarDelegateWriteAces(): bool {
        if ($this->getShareAccess() !== SPlugin::ACCESS_NOTSHARED) {
            return false;
        }

        return Utils::isResourceFromPrincipal($this->getOwner()) || Utils::isTeamCalendarFromPrincipal($this->getOwner());
    }

    private function isWriteEnabledAccess(int $access): bool {
        return in_array($access, [SPlugin::ACCESS_READWRITE, SPlugin::ACCESS_ADMINISTRATION], true);
    }

}
