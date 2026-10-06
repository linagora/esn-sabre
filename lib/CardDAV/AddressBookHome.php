<?php

namespace ESN\CardDAV;

use ESN\DAV\SortOrder;
use Sabre\DAV\MkCol;
use ESN\Utils\Utils;

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
     * One page of the contacts of the address books of this home, sorted by full name or email, read in one query.
     *
     * @param callable $canRead (string $path): bool, whether the current user can read an address book that is
     *                          not in this home (domain address book): access control stays with the caller
     * @param string|null $after opaque cursor of the previous page, see ContactCursor
     * @return array [ 'items' => [ [ 'path', 'etag', 'carddata' ], ... ], 'next' => cursor or null ]
     */
    function getContactsPage(ContactSources $sources, int $limit, ?string $after, callable $canRead, string $order = SortOrder::ASC, string $sort = ContactSort::FN): array {
        $paths = $this->aggregatedAddressBookPaths($sources, $canRead);

        // One more card than asked tells whether there is a next page
        $cards = $this->carddavBackend->getCardsOfAddressBooks(
            array_keys($paths), $limit + 1, ContactCursor::decode($after, $order, $sort), $order, $sort);

        $next = count($cards) > $limit ? ContactCursor::encode($cards[$limit - 1], $order, $sort) : null;

        $items = [];
        foreach (array_slice($cards, 0, $limit) as $card) {
            $items[] = [
                'path' => $paths[$card['addressbookid']] . '/' . $card['uri'],
                'etag' => $card['etag'],
                'carddata' => $card['carddata']
            ];
        }

        return [ 'items' => $items, 'next' => $next ];
    }

    /**
     * DAV paths through which the owner reads the address books listed together, keyed by address book id.
     *
     * Own address books come first, then accepted delegations, subscriptions and domain address books: an address
     * book reached several ways keeps the first one, array union keeping the left entry.
     */
    private function aggregatedAddressBookPaths(ContactSources $sources, callable $canRead): array {
        $paths = [];
        foreach ($this->carddavBackend->getAddressBooksFor($this->principalUri) as $addressBook) {
            $paths[$addressBook['id']] = $this->homePath($addressBook['uri']);
        }

        if ($sources->delegations) {
            $paths += $this->delegatedAddressBookPaths();
        }

        // Subscriptions and domain address books are only known by principaluri + uri, while cards reference
        // their address book id: they are resolved to ids with a single query.
        $toResolve = array_merge(
            $sources->subscriptions ? $this->subscribedAddressBooks() : [],
            $this->domainAddressBooks($sources, $canRead)
        );

        return $paths + $this->resolveAddressBookPaths($toResolve);
    }

    private function delegatedAddressBookPaths(): array {
        $paths = [];
        foreach ($this->carddavBackend->getSharedAddressBookSourcesForUser($this->principalUri) as $share) {
            // Pending or declined delegations do not give access to the contacts
            if ($share['share_invitestatus'] !== \ESN\DAV\Sharing\Plugin::INVITE_ACCEPTED) {
                continue;
            }

            // Delegated group address books are not exposed in this home (see removeChildrenSharedByGroupAddressBooks),
            // they are read directly
            $paths[$share['addressbookid']] ??= Utils::isUserPrincipal($share['source_principaluri'])
                ? $this->homePath($share['uri'])
                : $this->directPath($share['source_principaluri'], $share['source_uri']);
        }

        return $paths;
    }

    /**
     * Source address books of the subscriptions, as principaluri + uri + path, not resolved to ids.
     */
    private function subscribedAddressBooks(): array {
        $addressBooks = [];
        foreach ($this->carddavBackend->getSubscriptionsForUser($this->principalUri) as $subscription) {
            // Same resolution as Subscriptions\Subscription::getSourceAddressBookInfo
            $parts = explode('/', trim($subscription['source'], '/'));
            if (count($parts) < 3 || $parts[0] !== 'addressbooks') {
                continue;
            }

            $addressBooks[] = [
                'principaluri' => 'principals/users/' . $parts[1],
                'uri' => $parts[2],
                'path' => $this->homePath($subscription['uri'])
            ];
        }

        return $addressBooks;
    }

    /**
     * Address books of the domain of the owner listed with its contacts, as principaluri + uri + path: the domain
     * members one, and the domain address book when the current user can read it.
     */
    private function domainAddressBooks(ContactSources $sources, callable $canRead): array {
        $domainPrincipalUri = $this->getDomainPrincipalUri();
        if ($domainPrincipalUri === null) {
            return [];
        }

        $addressBooks = [];
        if ($sources->domainMembers) {
            $addressBooks[] = $this->domainAddressBook($domainPrincipalUri, Backend\Esn::DOMAIN_MEMBERS_URI);
        }

        if ($sources->domainContacts && $this->hasEnabledDomainAddressBook($domainPrincipalUri)) {
            $domainAddressBook = $this->domainAddressBook($domainPrincipalUri, Backend\Esn::DOMAIN_ADDRESS_BOOK_URI);
            // Left out when the members right was revoked
            if ($canRead($domainAddressBook['path'])) {
                $addressBooks[] = $domainAddressBook;
            }
        }

        return $addressBooks;
    }

    private function domainAddressBook($domainPrincipalUri, $uri): array {
        return [ 'principaluri' => $domainPrincipalUri, 'uri' => $uri, 'path' => $this->directPath($domainPrincipalUri, $uri) ];
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
     * Paths of address books known by principaluri + uri, keyed by id. When several resolve to the same address
     * book the first one wins; those that no longer exist (deleted source) are left out.
     */
    private function resolveAddressBookPaths(array $addressBooks): array {
        $ids = [];
        foreach ($this->carddavBackend->getAddressBooksByPrincipalAndUri($addressBooks) as $found) {
            $ids[$found['principaluri'] . '/' . $found['uri']] = $found['id'];
        }

        $paths = [];
        foreach ($addressBooks as $addressBook) {
            $id = $ids[$addressBook['principaluri'] . '/' . $addressBook['uri']] ?? null;
            if ($id !== null) {
                $paths[$id] ??= $addressBook['path'];
            }
        }

        return $paths;
    }

    /**
     * Path of a node of this home.
     */
    private function homePath($uri): string {
        return \Sabre\CardDAV\Plugin::ADDRESSBOOK_ROOT . '/' . $this->getName() . '/' . $uri;
    }

    /**
     * Path of an address book in the home of its owner, used for address books of groups (domains) which are
     * reached directly, like in Plugin::getAddressBookDetail.
     */
    private function directPath($ownerPrincipalUri, $uri): string {
        return \Sabre\CardDAV\Plugin::ADDRESSBOOK_ROOT . '/' . basename($ownerPrincipalUri) . '/' . $uri;
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
