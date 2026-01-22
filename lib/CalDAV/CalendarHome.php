<?php

namespace ESN\CalDAV;

#[\AllowDynamicProperties]
class CalendarHome extends \Sabre\CalDAV\CalendarHome {

    function getChild($name) {
        return $this->wrapCalendarForACLs(parent::getChild($name));
    }

    function getChildren() {
        return array_map([$this, 'wrapCalendarForACLs'], parent::getChildren());
    }

    /**
     * Deletes this object
     *
     * @return void
     */
    function delete() {

        $children = $this->getChildren();

        foreach ($children as $child) {
            if($child instanceof \Sabre\CalDAV\Calendar) {
                $child->delete();
            }
        }

    }

    private function wrapCalendarForACLs($cal) {
        if ($cal instanceof \Sabre\CalDAV\SharedCalendar) {
            return new SharedCalendar($cal);
        }

        // Wrap Sabre's default Subscription with our custom one that exposes source calendar events
        if ($cal instanceof \Sabre\CalDAV\Subscriptions\Subscription && !$cal instanceof \ESN\CalDAV\Subscriptions\Subscription) {
            // Get the subscription info from the original subscription
            $subscriptionInfo = $this->getSubscriptionInfoForNode($cal);
            if ($subscriptionInfo) {
                return new \ESN\CalDAV\Subscriptions\Subscription($this->caldavBackend, $subscriptionInfo);
            }
        }

        return $cal;
    }

    /**
     * Get subscription info for a subscription node
     *
     * @param \Sabre\CalDAV\Subscriptions\Subscription $subscription
     * @return array|null
     */
    private function getSubscriptionInfoForNode($subscription) {
        $uri = $subscription->getName();
        $subscriptions = $this->caldavBackend->getSubscriptionsForUser($this->principalInfo['uri']);

        foreach ($subscriptions as $sub) {
            if ($sub['uri'] === $uri) {
                return $sub;
            }
        }

        return null;
    }

    function getACL() {
        return [
            [
                'privilege' => '{DAV:}read',
                'principal' => '{DAV:}authenticated',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => $this->principalInfo['uri'],
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}write',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->principalInfo['uri'] . '/calendar-proxy-read',
                'protected' => true,
            ],

        ];
    }

    function getDuplicateCalendarObjectsByURI($uri) {
        return $this->caldavBackend->getDuplicateCalendarObjectsByURI($this->principalInfo['uri'], $uri);
    }

    function getCalDAVBackend() {
        return $this->caldavBackend;
    }
}
