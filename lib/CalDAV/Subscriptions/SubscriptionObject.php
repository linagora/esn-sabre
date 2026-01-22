<?php

namespace ESN\CalDAV\Subscriptions;

use Sabre\CalDAV\CalendarObject;

/**
 * SubscriptionObject
 *
 * This class represents a calendar object accessed through a subscription.
 * It grants read access to the subscription owner, not the source calendar owner.
 */
class SubscriptionObject extends CalendarObject {

    /**
     * The principal URI of the subscription owner
     *
     * @var string
     */
    protected $subscriptionOwner;

    /**
     * Constructor
     *
     * @param \Sabre\CalDAV\Backend\BackendInterface $caldavBackend
     * @param array $calendarInfo
     * @param array $objectData
     * @param string $subscriptionOwner
     */
    function __construct(\Sabre\CalDAV\Backend\BackendInterface $caldavBackend, array $calendarInfo, array $objectData, $subscriptionOwner) {
        parent::__construct($caldavBackend, $calendarInfo, $objectData);
        $this->subscriptionOwner = $subscriptionOwner;
    }

    /**
     * Returns a list of ACE's for this node.
     *
     * Read-only access for the subscription owner.
     *
     * @return array
     */
    function getACL() {
        return [
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->subscriptionOwner,
                'protected' => true,
            ]
        ];
    }
}
