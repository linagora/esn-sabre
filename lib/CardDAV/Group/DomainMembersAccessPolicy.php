<?php

namespace ESN\CardDAV\Group;

/**
 * Who may read the domain-members address book of a domain, see DomainMembersAccessPlugin.
 *
 * The cards are kept whatever the policy: switching it only changes the ACL of the address book.
 */
enum DomainMembersAccessPolicy: string {
    /**
     * Only a technical token of the domain reads and writes it: neither the domain members nor the domain
     * administrators may read it.
     */
    case Restricted = 'restricted';

    /**
     * The domain members are granted their members right, the domain administrators their own rights.
     */
    case Readable = 'readable';

    const PROPERTY = '{http://open-paas.org/contacts}access-policy';

    /**
     * The policy stored on an address book: an address book without one is readable.
     */
    static function fromAddressBookInfo(array $addressBookInfo): self {
        return self::tryFrom($addressBookInfo[self::PROPERTY] ?? '') ?? self::Readable;
    }
}
