<?php

namespace ESN\DAVACL;

use Sabre\DAV;

/**
 * Sabre's ACL plugin, leaving out of the listings of a calendar home the children the current user cannot read.
 *
 * A calendar home is readable by every authenticated user so that public calendars can be discovered: the stock
 * plugin would answer one response per private calendar, with every property 403, disclosing its href, hence its
 * often user-chosen name.
 *
 * Other unreadable nodes keep the stock 403 properties instead of the global hideNodesFromListings: the objects
 * of a calendar only grant read to its owner, and the users who can read a resource or public calendar list its
 * event hrefs that way.
 */
#[\AllowDynamicProperties]
class CalendarHomeListingAclPlugin extends \Sabre\DAVACL\Plugin {
    public function propFind(DAV\PropFind $propFind, DAV\INode $node) {
        if ($this->isUnreadableCalendarHomeChild($propFind->getPath())) {
            return false;
        }

        return parent::propFind($propFind, $node);
    }

    private function isUnreadableCalendarHomeChild(string $path): bool {
        list($parent) = \Sabre\Uri\split($path);

        return $this->isCalendarHome($parent)
            && !$this->checkPrivileges($path, '{DAV:}read', self::R_PARENT, false);
    }

    private function isCalendarHome(?string $path): bool {
        if ($path === null || $path === '') {
            return false;
        }

        try {
            return $this->server->tree->getNodeForPath($path) instanceof \Sabre\CalDAV\CalendarHome;
        } catch (DAV\Exception\NotFound $e) {
            return false;
        }
    }
}
