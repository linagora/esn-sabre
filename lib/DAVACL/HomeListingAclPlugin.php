<?php

namespace ESN\DAVACL;

use Sabre\DAV;

/**
 * Sabre's ACL plugin, leaving out of the listings of another user's calendar home or address book home the
 * children the current user cannot read.
 *
 * Both homes are readable by every authenticated user so that public calendars and address books can be
 * discovered: the stock plugin would answer one response per private calendar, address book or subscription,
 * with every property 403, disclosing its href, hence its often user-chosen name.
 *
 * Other unreadable nodes keep the stock 403 properties instead of the global hideNodesFromListings: the objects
 * of a calendar only grant read to its owner, and the users who can read a resource or public calendar list its
 * event hrefs that way. The listing of the user's own home is left whole as well: a subscription whose source was
 * unpublished only grants its owner {DAV:}write-properties, and the owner must still find it to delete it.
 */
#[\AllowDynamicProperties]
class HomeListingAclPlugin extends \Sabre\DAVACL\Plugin {
    public function propFind(DAV\PropFind $propFind, DAV\INode $node) {
        if ($this->isUnreadableHomeChild($propFind->getPath())) {
            return false;
        }

        return parent::propFind($propFind, $node);
    }

    private function isUnreadableHomeChild(string $path): bool {
        list($parent) = \Sabre\Uri\split($path);

        return $this->isAnotherUsersHome($parent)
            && !$this->checkPrivileges($path, '{DAV:}read', self::R_PARENT, false);
    }

    private function isAnotherUsersHome(?string $path): bool {
        if ($path === null || $path === '') {
            return false;
        }

        try {
            $node = $this->server->tree->getNodeForPath($path);
        } catch (DAV\Exception\NotFound $e) {
            return false;
        }

        if (!($node instanceof \Sabre\CalDAV\CalendarHome) && !($node instanceof \Sabre\CardDAV\AddressBookHome)) {
            return false;
        }

        return $node->getOwner() !== $this->getCurrentUserPrincipal();
    }
}
