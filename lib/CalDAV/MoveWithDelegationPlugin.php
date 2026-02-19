<?php

namespace ESN\CalDAV;

use ESN\DAV\Sharing\Plugin as SPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

/**
 * Extends Sabre\DAVACL\Plugin to allow delegates with write access to MOVE
 * events to and from the owner's calendar via the owner's path.
 *
 * Standard DAVACL blocks MOVE because it requires:
 *   - {DAV:}read    on the source event   (checked in beforeMethod for MOVE)
 *   - {DAV:}unbind  on the source calendar (checked in beforeUnbind)
 *   - {DAV:}bind    on the dest calendar   (checked in beforeBind)
 *
 * Granting those privileges broadly would expose events to GET/REPORT and
 * enable PUT/DELETE as well.  We therefore override only these three DAVACL
 * methods and skip the privilege check when the current user is a delegate
 * with READWRITE or ADMINISTRATION access on the relevant calendar.
 * All other HTTP methods (GET, PUT, DELETE, REPORT …) continue to use the
 * normal ACL enforcement provided by the parent class.
 */
#[\AllowDynamicProperties]
class MoveWithDelegationPlugin extends \Sabre\DAVACL\Plugin {

    /**
     * Overrides beforeMethod to skip the {DAV:}read check on the MOVE source
     * when the current user is a write-delegate of the source calendar.
     *
     * For every other HTTP method the parent implementation is used unchanged.
     */
    function beforeMethod(RequestInterface $request, ResponseInterface $response) {
        if ($request->getMethod() === 'MOVE') {
            $path = $request->getPath();
            if ($this->server->tree->nodeExists($path)) {
                list($calendarPath) = \Sabre\Uri\split($path);
                if ($this->currentUserHasDelegateWriteAccess($calendarPath)) {
                    // Delegate has write rights — skip {DAV:}read check on source.
                    return;
                }
            }
        }
        parent::beforeMethod($request, $response);
    }

    /**
     * Overrides beforeUnbind to allow a MOVE from a delegated calendar.
     * DELETE operations still go through normal ACL checks (parent call).
     */
    function beforeUnbind($uri) {
        if ($this->server->httpRequest->getMethod() === 'MOVE') {
            list($parentUri) = \Sabre\Uri\split($uri);
            if ($this->currentUserHasDelegateWriteAccess($parentUri)) {
                return; // Skip {DAV:}unbind check for delegate MOVE.
            }
        }
        parent::beforeUnbind($uri);
    }

    /**
     * Overrides beforeBind to allow a MOVE to a delegated calendar.
     * PUT operations still go through normal ACL checks (parent call).
     */
    function beforeBind($uri) {
        if ($this->server->httpRequest->getMethod() === 'MOVE') {
            list($parentUri) = \Sabre\Uri\split($uri);
            if ($this->currentUserHasDelegateWriteAccess($parentUri)) {
                return; // Skip {DAV:}bind check for delegate MOVE.
            }
        }
        parent::beforeBind($uri);
    }

    /**
     * Returns true when the current principal is a sharee of the calendar
     * collection at $calendarPath with READWRITE or ADMINISTRATION access.
     */
    private function currentUserHasDelegateWriteAccess($calendarPath) {
        try {
            $node = $this->server->tree->getNodeForPath($calendarPath);
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            return false;
        }

        if (!($node instanceof SharedCalendar)) {
            return false;
        }

        $currentUser = $this->getCurrentUserPrincipal();
        if (!$currentUser) {
            return false;
        }

        foreach ($node->getInvites() as $invite) {
            if ($invite->principal === $currentUser
                && in_array($invite->access, [
                    SPlugin::ACCESS_READWRITE,
                    SPlugin::ACCESS_ADMINISTRATION,
                ])) {
                return true;
            }
        }

        return false;
    }
}
