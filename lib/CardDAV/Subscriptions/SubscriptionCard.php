<?php

namespace ESN\CardDAV\Subscriptions;

/**
 * Subscription Card
 *
 * This node represents a contact card within a subscription.
 * It wraps a standard Card but enforces ACL checks based on the
 * subscription's permissions before allowing write operations.
 */
class SubscriptionCard extends \Sabre\CardDAV\Card {

    /**
     * Reference to the subscription node
     *
     * @var Subscription
     */
    protected $subscription;

    /**
     * Constructor
     *
     * @param \Sabre\CardDAV\Backend\BackendInterface $carddavBackend
     * @param array $addressBookInfo
     * @param array $cardData
     * @param Subscription $subscription
     */
    function __construct(\Sabre\CardDAV\Backend\BackendInterface $carddavBackend, array $addressBookInfo, array $cardData, Subscription $subscription) {
        parent::__construct($carddavBackend, $addressBookInfo, $cardData);
        $this->subscription = $subscription;
    }

    /**
     * Checks if the subscription has write privileges
     *
     * @throws \Sabre\DAV\Exception\Forbidden
     */
    protected function checkWriteAccess() {
        $acl = $this->subscription->getACL();
        $hasWrite = false;

        foreach ($acl as $ace) {
            // Check for write privileges: write, write-content, bind, unbind, or all
            if (in_array($ace['privilege'], [
                '{DAV:}write',
                '{DAV:}write-content',
                '{DAV:}bind',
                '{DAV:}unbind',
                '{DAV:}all'
            ])) {
                $hasWrite = true;
                break;
            }
        }

        if (!$hasWrite) {
            throw new \Sabre\DAV\Exception\Forbidden('You do not have write access to this subscription');
        }
    }

    /**
     * Updates the VCard-formatted object
     *
     * @param string|resource $cardData
     * @return string|null
     */
    function put($cardData) {
        $this->checkWriteAccess();
        return parent::put($cardData);
    }

    /**
     * Deletes the card
     *
     * @return void
     */
    function delete() {
        $this->checkWriteAccess();
        parent::delete();
    }

    /**
     * Returns the ACL for this card based on the subscription's ACL
     *
     * @return array
     */
    function getACL() {
        return $this->subscription->getChildACL();
    }
}
