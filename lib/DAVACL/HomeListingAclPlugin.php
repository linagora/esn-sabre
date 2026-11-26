<?php

namespace ESN\DAVACL;

use Sabre\DAV;
use Sabre\DAVACL\Exception\NoAbstract;
use Sabre\DAVACL\Exception\NotRecognizedPrincipal;
use Sabre\DAVACL\Exception\NotSupportedPrivilege;

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
    private bool $replacingDomainMembersAcl = false;

    public function getAcl($node) {
        $acl = parent::getAcl($node);
        if ($this->replacingDomainMembersAcl && $node instanceof \ESN\CardDAV\Group\GroupAddressBook
            && $node->getName() === \ESN\CardDAV\Backend\Esn::DOMAIN_MEMBERS_URI) {
            // Implicit administrator grants are not part of the ACL a technical token replaces.
            return array_values(array_filter($acl, fn($ace) => empty($ace['protected'])));
        }
        return $acl;
    }

    public function httpAcl(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) {
        $node = $this->server->tree->getNodeForPath($request->getPath());
        if (!($node instanceof \ESN\CardDAV\Group\GroupAddressBook)
            || $node->getName() !== \ESN\CardDAV\Backend\Esn::DOMAIN_MEMBERS_URI) {
            return parent::httpAcl($request, $response);
        }

        // Sabre silently drops unknown children of DAV:acl, so a mistyped ACE could revoke all access.
        $body = $request->getBodyAsString();
        $document = new \DOMDocument();
        if ($body === '' || !@$document->loadXML($body, LIBXML_NONET)) {
            throw new DAV\Exception\BadRequest('Expected a well-formed ACL document');
        }
        foreach ($document->documentElement->childNodes as $child) {
            if ($child instanceof \DOMElement && ($child->namespaceURI !== 'DAV:' || $child->localName !== 'ace')) {
                throw new DAV\Exception\BadRequest('Expected DAV:ace inside DAV:acl');
            }
        }
        // Sabre reads the body again; the incoming request may have a non-seekable stream.
        $request->setBody($body);

        // Sabre otherwise requires protected role and technical-principal grants in the submitted ACL.
        // These privileges are implicit, not editable ACEs. Authorization ran before this.
        try {
            $this->replacingDomainMembersAcl = true;
            return parent::httpAcl($request, $response);
        } catch (\Sabre\Xml\ParseException $error) {
            throw new DAV\Exception\BadRequest($error->getMessage());
        } catch (NotSupportedPrivilege | NotRecognizedPrincipal | NoAbstract $error) {
            throw new Exception\AclPreconditionViolation($error);
        } finally {
            $this->replacingDomainMembersAcl = false;
        }
    }

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
