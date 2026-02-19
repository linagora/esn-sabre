<?php

namespace ESN\CalDAV;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use ESN\DAV\Sharing\Plugin as SPlugin;

/**
 * Allows delegates (sharees with write access) to MOVE events to and from
 * the owner's calendar via the owner's path.
 *
 * Standard WebDAV ACL blocks this because MOVE requires:
 *   - {DAV:}read    on the source event   (DAVACL::beforeMethod:MOVE)
 *   - {DAV:}unbind  on the source calendar (DAVACL::beforeUnbind)
 *   - {DAV:}bind    on the dest calendar   (DAVACL::beforeBind)
 *
 * Granting these privileges broadly would also expose events to GET/REPORT,
 * enable PUT, and enable DELETE — all of which must stay blocked for delegates
 * accessing the owner's path directly.
 *
 * This plugin fires at priority 10 (before DAVACL at priority 20) and
 * short-circuits the DAVACL checks only for MOVE operations where the
 * current user is a delegate with READWRITE or ADMINISTRATION access.
 * PUT, DELETE, GET, and REPORT remain governed by normal ACL rules.
 */
#[\AllowDynamicProperties]
class MoveWithDelegationPlugin extends ServerPlugin {

    /** @var Server */
    protected $server;

    function initialize(Server $server) {
        $this->server = $server;
        // Priority 10 fires before Sabre\DAVACL\Plugin (priority 20).
        $server->on('beforeMethod:MOVE', [$this, 'beforeMethodMove'], 10);
        $server->on('beforeUnbind',      [$this, 'beforeUnbind'],      10);
        $server->on('beforeBind',        [$this, 'beforeBind'],        10);
    }

    function getPluginName() {
        return 'move-with-delegation';
    }

    function getPluginInfo() {
        return [
            'name'        => $this->getPluginName(),
            'description' => 'Allows delegates with write access to MOVE events via the owner\'s calendar path.',
        ];
    }

    /**
     * Intercepts the {DAV:}read check on the MOVE source.
     *
     * DAVACL's beforeMethod:MOVE checks {DAV:}read on the source node.
     * For a calendar event that the current user is a delegate of (with write
     * rights), we bypass this check by returning false to stop the event chain
     * before DAVACL runs.  The actual MOVE is still performed by httpMove which
     * listens on method:MOVE (a separate event).
     */
    function beforeMethodMove(RequestInterface $request, ResponseInterface $response) {
        $path = $request->getPath();

        try {
            $node = $this->server->tree->getNodeForPath($path);
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            return; // Let Sabre return the 404 normally.
        }

        if (!($node instanceof \Sabre\CalDAV\CalendarObject)) {
            return;
        }

        list($calendarPath) = \Sabre\Uri\split($path);
        $calendarNode = $this->getCalendarNode($calendarPath);
        if (!$calendarNode) {
            return;
        }

        $currentUser = $this->getCurrentUserPrincipal();
        if (!$currentUser) {
            return;
        }

        if ($this->hasDelegateWriteAccess($calendarNode, $currentUser)) {
            // Stop DAVACL's beforeMethod:MOVE from checking {DAV:}read on source.
            return false;
        }
    }

    /**
     * Intercepts the {DAV:}unbind check on the MOVE source calendar.
     *
     * Only bypasses for MOVE — DELETE operations still go through normal ACL.
     */
    function beforeUnbind($path) {
        if ($this->server->httpRequest->getMethod() !== 'MOVE') {
            return;
        }

        list($parentPath) = \Sabre\Uri\split($path);
        $calendarNode = $this->getCalendarNode($parentPath);
        if (!$calendarNode) {
            return;
        }

        $currentUser = $this->getCurrentUserPrincipal();
        if (!$currentUser) {
            return;
        }

        if ($this->hasDelegateWriteAccess($calendarNode, $currentUser)) {
            return false;
        }
    }

    /**
     * Intercepts the {DAV:}bind check on the MOVE destination calendar.
     *
     * Only bypasses for MOVE — PUT operations still go through normal ACL.
     */
    function beforeBind($path) {
        if ($this->server->httpRequest->getMethod() !== 'MOVE') {
            return;
        }

        list($parentPath) = \Sabre\Uri\split($path);
        $calendarNode = $this->getCalendarNode($parentPath);
        if (!$calendarNode) {
            return;
        }

        $currentUser = $this->getCurrentUserPrincipal();
        if (!$currentUser) {
            return;
        }

        if ($this->hasDelegateWriteAccess($calendarNode, $currentUser)) {
            return false;
        }
    }

    /**
     * Returns true when the given principal is a sharee of $calendar with
     * READWRITE or ADMINISTRATION access (i.e. write delegation rights).
     */
    private function hasDelegateWriteAccess(SharedCalendar $calendar, $currentUserPrincipal) {
        foreach ($calendar->getInvites() as $invite) {
            if ($invite->principal === $currentUserPrincipal
                && in_array($invite->access, [
                    SPlugin::ACCESS_READWRITE,
                    SPlugin::ACCESS_ADMINISTRATION,
                ])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the SharedCalendar node at $path, or null if $path does not
     * resolve to a SharedCalendar.
     */
    private function getCalendarNode($path) {
        try {
            $node = $this->server->tree->getNodeForPath($path);
            return ($node instanceof SharedCalendar) ? $node : null;
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            return null;
        }
    }

    /**
     * Returns the current user's principal URI from the ACL plugin.
     */
    private function getCurrentUserPrincipal() {
        $aclPlugin = $this->server->getPlugin('acl');
        if (!$aclPlugin) {
            return null;
        }
        return $aclPlugin->getCurrentUserPrincipal();
    }
}
