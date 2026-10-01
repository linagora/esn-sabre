<?php

namespace ESN\CardDAV;

use Sabre\DAV\MkCol;

#[\AllowDynamicProperties]
class AddressBookHome extends \Sabre\CardDAV\AddressBookHome {

    protected $principal;
    protected $sourcesOfSharedAddressBooks;

    /**
     * Constructor
     *
     * @param Backend\BackendInterface $carddavBackend
     * @param array $principal
     */
    function __construct(\Sabre\CardDAV\Backend\BackendInterface $carddavBackend, $principal) {
        $this->principal = $principal;

        parent::__construct($carddavBackend, $principal['uri']);
    }

    /**
     * Returns a list of addressbooks. In contrast to the sabre version of this
     * method, the returned addressbook instance has extra methods.
     *
     * @return array
     */
    function getChildren() {
        $this->sourcesOfSharedAddressBooks = [];
        $children = [];

        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->principalUri);

        foreach($addressbooks as $addressbook) {
            $children[] = new \ESN\CardDAV\AddressBook($this->carddavBackend, $addressbook);
        }

        // If the backend supports subscriptions, we'll add those as well
        if ($this->carddavBackend instanceof Backend\SubscriptionSupport) {
            $children = $this->updateChildrenWithSubscriptionAddressBooks($children);
        }

        // If the backend supports shared address books, we'll add those as well
        if ($this->carddavBackend instanceof Backend\SharingSupport) {
            $children = $this->updateChildrenWithSharedAddressBooks($children);
        }

        // Remove children that are shared by group address books
        if (isset($this->principal['groupPrincipals'])) {
            $children = $this->removeChildrenSharedByGroupAddressBooks($children);
        }

        return $children;
    }

    /**
     * Creates a new address book.
     *
     * @param string $name
     * @param MkCol $mkCol
     * @throws DAV\Exception\InvalidResourceType
     * @return void
     */
    function createExtendedCollection($name, MkCol $mkCol) {
        $isAddressBook = false;
        $isSubscription = false;

        foreach ($mkCol->getResourceType() as $rt) {
            switch ($rt) {
                case '{DAV:}collection' :
                    // ignore
                    break;
                case '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook' :
                    $isAddressBook = true;
                    break;
                case '{http://open-paas.org/contacts}subscribed' :
                    $isSubscription = true;
                    break;
                default :
                    throw new DAV\Exception\InvalidResourceType('Unknown resourceType: ' . $rt);
            }
        }

        $properties = $mkCol->getRemainingValues();
        $mkCol->setRemainingResultCode(201);

        if ($isSubscription) {
            if (!$this->carddavBackend instanceof Backend\SubscriptionSupport) {
                throw new DAV\Exception\InvalidResourceType('This backend does not support subscriptions');
            }

            $this->carddavBackend->createSubscription($this->principalUri, $name, $properties);

        } elseif ($isAddressBook) {

            $this->carddavBackend->createAddressBook($this->principalUri, $name, $properties);

        } else {
            throw new DAV\Exception\InvalidResourceType('You can only create address book and subscriptions in this collection');
        }
    }

    /**
     * Allows authenticated users can list address books of a user
     */
    function getACL() {
        $acl = parent::getACL();
        $acl[] = [
            'privilege' => '{DAV:}read',
            'principal' => '{DAV:}authenticated',
            'protected' => true
        ];

        return $acl;
    }

    /**
     * One page of the contacts of the address books of this home, sorted by full name, read in one query.
     *
     * @param callable $canRead (string $path): bool, whether the current user can read an address book that is
     *                          not in this home (domain address book): access control stays with the caller
     * @param string|null $after opaque cursor of the previous page, see ContactCursor
     * @return array [ 'items' => [ [ 'path', 'etag', 'carddata' ], ... ], 'next' => cursor or null ]
     */
    function getContactsPage(ContactSources $sources, int $limit, ?string $after, callable $canRead): array {
        $addressBooks = $this->carddavBackend->getAggregatedAddressBooks(
            $this->principalUri,
            $sources->delegations,
            $sources->subscriptions,
            $this->domainAddressBooks($sources, $canRead)
        );

        // One more card than asked tells whether there is a next page
        $cards = $this->carddavBackend->getCardsOfAddressBooks(
            array_keys($addressBooks), $limit + 1, ContactCursor::decode($after));

        $next = count($cards) > $limit ? ContactCursor::encode($cards[$limit - 1]) : null;

        $items = [];
        foreach (array_slice($cards, 0, $limit) as $card) {
            $items[] = [
                'path' => $this->addressBookPath($addressBooks[$card['addressbookid']]) . '/' . $card['uri'],
                'etag' => $card['etag'],
                'carddata' => $card['carddata']
            ];
        }

        return [ 'items' => $items, 'next' => $next ];
    }

    /**
     * Address books of the domain of the owner listed with its contacts, as principaluri + uri pairs: the domain
     * members one, and the domain address book when the current user can read it.
     */
    private function domainAddressBooks(ContactSources $sources, callable $canRead): array {
        $domainPrincipalUri = $this->getDomainPrincipalUri();
        if ($domainPrincipalUri === null) {
            return [];
        }

        $addressBooks = [];
        if ($sources->domainMembers) {
            $addressBooks[] = [ 'principaluri' => $domainPrincipalUri, 'uri' => Backend\Esn::DOMAIN_MEMBERS_URI ];
        }

        if ($sources->domainContacts && $this->hasEnabledDomainAddressBook($domainPrincipalUri)) {
            $domainAddressBook = [ 'principaluri' => $domainPrincipalUri, 'uri' => Backend\Esn::DOMAIN_ADDRESS_BOOK_URI ];
            // Left out when the members right was revoked
            if ($canRead($this->addressBookPath($domainAddressBook))) {
                $addressBooks[] = $domainAddressBook;
            }
        }

        return $addressBooks;
    }

    private function hasEnabledDomainAddressBook($domainPrincipalUri): bool {
        foreach ($this->carddavBackend->getAddressBooksFor($domainPrincipalUri) as $addressBook) {
            if ($addressBook['uri'] === Backend\Esn::DOMAIN_ADDRESS_BOOK_URI) {
                return ($addressBook['{http://open-paas.org/contacts}state'] ?? '') !== 'disabled';
            }
        }

        return false;
    }

    /**
     * DAV path through which the owner reaches an address book: its node in this home, except for address books
     * of groups (domains) which are reached directly, like in Plugin::getAddressBookDetail.
     */
    private function addressBookPath(array $addressBook): string {
        $root = \Sabre\CardDAV\Plugin::ADDRESSBOOK_ROOT;

        if (($addressBook['homeUri'] ?? null) !== null && \ESN\Utils\Utils::isUserPrincipal($addressBook['principaluri'])) {
            return $root . '/' . $this->getName() . '/' . $addressBook['homeUri'];
        }

        return $root . '/' . basename($addressBook['principaluri']) . '/' . $addressBook['uri'];
    }

    /**
     * Principal uri of the domain the owner of this home belongs to, null when there is none.
     */
    function getDomainPrincipalUri() {
        foreach ($this->principal['groupPrincipals'] ?? [] as $groupPrincipal) {
            if (strpos($groupPrincipal['uri'], 'principals/domains/') === 0) {
                return $groupPrincipal['uri'];
            }
        }

        return null;
    }

    protected function updateChildrenWithSubscriptionAddressBooks($children) {
        foreach ($this->carddavBackend->getSubscriptionsForUser($this->principalUri) as $subscription) {
            $children[] = new \ESN\CardDAV\Subscriptions\Subscription($this->carddavBackend, $subscription);
        }

        return $children;
    }

    protected function updateChildrenWithSharedAddressBooks($children) {
        foreach ($this->carddavBackend->getSharedAddressBooksForUser($this->principalUri) as $sharedAddressBook) {
            $sharedAddressBookInstance = new Sharing\SharedAddressBook($this->carddavBackend, $sharedAddressBook);

            $this->sourcesOfSharedAddressBooks[(string)$sharedAddressBook['addressbookid']] = $sharedAddressBookInstance;

            $children[] = $sharedAddressBookInstance;
        }

        return $children;
    }

    protected function removeChildrenSharedByGroupAddressBooks($children) {
        foreach ($this->principal['groupPrincipals'] as $groupPrincipal) {
            foreach ($this->carddavBackend->getAddressBooksFor($groupPrincipal['uri']) as $addressBook) {
                if (isset($this->sourcesOfSharedAddressBooks[(string)$addressBook['id']])) {
                    $index = array_search($this->sourcesOfSharedAddressBooks[(string)$addressBook['id']], $children);

                    array_splice($children, $index, 1);
                }
            }
        }

        return $children;
    }
}
