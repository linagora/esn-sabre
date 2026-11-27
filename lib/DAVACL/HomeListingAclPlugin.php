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

        $body = $request->getBodyAsString();
        $this->assertDomainMembersAclBody($body);
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

    private function assertDomainMembersAclBody(string $body): void {
        $document = new \DOMDocument();
        if ($body === '' || !@$document->loadXML($body, LIBXML_NONET)
            || $document->documentElement->namespaceURI !== 'DAV:' || $document->documentElement->localName !== 'acl') {
            throw new DAV\Exception\BadRequest('Expected a well-formed DAV:acl document');
        }
        // Sabre silently drops unknown grant children and empty privileges. Reject the entire ACL before writing it.
        foreach ($this->aclChildElements($document->documentElement) as $ace) {
            if ($ace->namespaceURI !== 'DAV:' || $ace->localName !== 'ace') {
                throw new DAV\Exception\BadRequest('Expected DAV:ace inside DAV:acl');
            }
            $this->assertAclAce($ace);
        }
    }

    private function assertAclAce(\DOMElement $ace): void {
        $elements = [];
        foreach ($this->aclChildElements($ace) as $child) {
            $name = $child->localName;
            if ($child->namespaceURI !== 'DAV:' || !in_array($name, ['principal', 'grant', 'protected'], true)) {
                throw new DAV\Exception\BadRequest('Expected DAV:principal, DAV:grant or DAV:protected inside DAV:ace');
            }
            if (isset($elements[$name])) {
                throw new DAV\Exception\BadRequest('Duplicate DAV:' . $name . ' inside DAV:ace');
            }
            $elements[$name] = $child;
        }
        if (!isset($elements['principal'], $elements['grant'])) {
            throw new DAV\Exception\BadRequest('Each DAV:ace requires one DAV:principal and one DAV:grant');
        }
        if (count($this->aclChildElements($elements['principal'])) !== 1) {
            throw new DAV\Exception\BadRequest('DAV:principal requires exactly one principal element');
        }
        if (isset($elements['protected'])) {
            $this->aclChildElements($elements['protected']);
        }
        $this->assertAclGrant($elements['grant']);
    }

    private function assertAclGrant(\DOMElement $grant): void {
        $privileges = $this->aclChildElements($grant);
        if (!$privileges) {
            throw new DAV\Exception\BadRequest('DAV:grant requires at least one DAV:privilege');
        }
        foreach ($privileges as $privilege) {
            if ($privilege->namespaceURI !== 'DAV:' || $privilege->localName !== 'privilege') {
                throw new DAV\Exception\BadRequest('Expected DAV:privilege inside DAV:grant');
            }
            $names = $this->aclChildElements($privilege);
            if (count($names) !== 1 || $this->aclChildElements($names[0])) {
                throw new DAV\Exception\BadRequest('DAV:privilege requires exactly one empty privilege element');
            }
        }
    }

    /** Ignore XML whitespace and comments, but never silently drop text in an ACL container. */
    private function aclChildElements(\DOMElement $parent): array {
        $elements = [];
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $elements[] = $child;
            } elseif ($child instanceof \DOMText && trim($child->textContent) !== '') {
                throw new DAV\Exception\BadRequest('Unexpected text inside DAV:' . $parent->localName);
            }
        }
        return $elements;
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
