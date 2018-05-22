<?php

namespace ESN\CardDAV;

use Sabre\DAV\MkCol;

class AddressBookHome extends \Sabre\CardDAV\AddressBookHome {

    /**
     * Returns a list of addressbooks. In contrast to the sabre version of this
     * method, the returned addressbook instance has extra methods.
     *
     * @return array
     */
    function getChildren() {
        $addressbooks = $this->carddavBackend->getAddressBooksForUser($this->principalUri);
        $objs = [];
        foreach($addressbooks as $addressbook) {
            $objs[] = new \ESN\CardDAV\AddressBook($this->carddavBackend, $addressbook);
        }

        // If the backend supports subscriptions, we'll add those as well
        if ($this->carddavBackend instanceof Backend\SubscriptionSupport) {
            foreach ($this->carddavBackend->getSubscriptionsForUser($this->principalUri) as $subscription) {
                $objs[] = new \ESN\CardDAV\Subscriptions\Subscription($this->carddavBackend, $subscription);
            }
        }

        // If the backend supports shared address books, we'll add those as well
        if ($this->carddavBackend instanceof Backend\SharingSupport) {
            foreach ($this->carddavBackend->getSharedAddressBooksForUser($this->principalUri) as $sharedAddressBook) {
                $objs[] = new Sharing\SharedAddressBook($this->carddavBackend, $sharedAddressBook);
            }
        }

        return $objs;
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
}
