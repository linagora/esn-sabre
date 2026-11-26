<?php

namespace ESN\CardDAV;

/**
 * Paths within the domain-members address book of a domain: addressbooks/{domainId}/domain-members[/{card}].
 *
 * Empty segments ('//', or a decoded '%2F') are skipped, as the tree does when resolving the node.
 */
final class DomainMembersPath {
    private function __construct() {
    }

    static function matches($path): bool {
        return self::domainIdOf($path) !== null;
    }

    /**
     * The id of the domain owning the domain-members address book the path is within, null for any other path.
     */
    static function domainIdOf($path): ?string {
        $pathParts = array_values(array_filter(explode('/', (string) $path), 'strlen'));

        $isDomainMembersPath = count($pathParts) >= 3
            && $pathParts[0] === \Sabre\CardDAV\Plugin::ADDRESSBOOK_ROOT
            && $pathParts[2] === Backend\Esn::DOMAIN_MEMBERS_URI;

        return $isDomainMembersPath ? $pathParts[1] : null;
    }
}
