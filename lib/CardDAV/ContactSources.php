<?php

namespace ESN\CardDAV;

use \ESN\Utils\Utils as Utils;

/**
 * Address books whose contacts are listed together with the own ones of a home (see
 * AddressBookHome::getContactsPage).
 */
final class ContactSources
{
    public function __construct(
        public readonly bool $delegations = true,
        public readonly bool $subscriptions = true,
        public readonly bool $domainMembers = false,
        public readonly bool $domainContacts = false
    ) {
    }

    /**
     * delegate, share, domainMember and domainContacts query parameters.
     */
    public static function fromQueryParameters(array $queryParams): self
    {
        return new self(
            Utils::getArrayValue($queryParams, 'delegate', 'true') !== 'false',
            Utils::getArrayValue($queryParams, 'share', 'true') !== 'false',
            Utils::getArrayValue($queryParams, 'domainMember', 'false') === 'true',
            Utils::getArrayValue($queryParams, 'domainContacts', 'false') === 'true'
        );
    }
}
