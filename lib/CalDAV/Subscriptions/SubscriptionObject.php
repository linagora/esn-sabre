<?php

namespace ESN\CalDAV\Subscriptions;

use Sabre\CalDAV\CalendarObject;

/**
 * SubscriptionObject
 *
 * This class represents a calendar object accessed through a subscription.
 * It's read-only since subscriptions don't allow modifications to the source.
 */
class SubscriptionObject extends CalendarObject {

    /**
     * Returns a list of ACE's for this node.
     *
     * Read-only access for subscription objects.
     *
     * @return array
     */
    function getACL() {
        $calendarInfo = $this->calendarInfo;
        $owner = $calendarInfo['principaluri'];

        return [
            [
                'privilege' => '{DAV:}read',
                'principal' => $owner,
                'protected' => true,
            ]
        ];
    }
}
