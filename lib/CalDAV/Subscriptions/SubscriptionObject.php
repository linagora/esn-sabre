<?php

namespace ESN\CalDAV\Subscriptions;

use Sabre\CalDAV\CalendarObject;

/**
 * SubscriptionObject
 *
 * This class represents a calendar object accessed through a subscription.
 * It grants access to the subscription owner based on the source calendar's permissions.
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
     * Grants read access always, and write access if the source calendar allows it.
     *
     * @return array
     */
    function getACL() {
        $acl = [
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->subscriptionOwner,
                'protected' => true,
            ]
        ];

        // Check if write access should be granted based on source calendar
        if ($this->hasWriteAccess()) {
            $acl[] = [
                'privilege' => '{DAV:}write',
                'principal' => $this->subscriptionOwner,
                'protected' => true,
            ];
            $acl[] = [
                'privilege' => '{DAV:}write-content',
                'principal' => $this->subscriptionOwner,
                'protected' => true,
            ];
        }

        return $acl;
    }

    /**
     * Checks if write access is allowed based on the source calendar's permissions.
     *
     * @return bool
     */
    protected function hasWriteAccess() {
        // Check if the source calendar has public write right
        $publicRight = $this->caldavBackend->getCalendarPublicRight($this->calendarInfo['id']);
        return $publicRight === '{DAV:}write';
    }
}
