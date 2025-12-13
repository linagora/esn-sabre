<?php

namespace ESN\JSON\CalDAV;

use \Sabre\DAV;

/**
 * Subscription Handler
 *
 * Handles calendar subscription operations including:
 * - Subscription creation
 * - Subscription property management
 * - Subscription information retrieval
 * - Subscription calendar object queries
 */
class SubscriptionHandler {
    use ValidatesResourceIds;

    protected $server;
    protected $currentUser;

    public function __construct($server, $currentUser) {
        $this->server = $server;
        $this->currentUser = $currentUser;
    }

    public function createSubscription($homePath, $jsonData) {
        $issetdef = $this->propertyOrDefault($jsonData);

        if (!$this->isValidResourceId($jsonData->id ?? null)) {
            return [400, null];
        }

        // Validate calendarserver:source exists and has a valid href
        $source = $issetdef('calendarserver:source');
        if (!$source || !is_object($source) || !isset($source->href) || empty($source->href)) {
            return [400, null];
        }

        $sourcePath = $this->server->calculateUri($source->href);

        if (substr($sourcePath, -5) == '.json') {
            $sourcePath = substr($sourcePath, 0, -5);
        }

        $rt = ['{DAV:}collection', '{http://calendarserver.org/ns/}subscribed'];
        $props = [
            '{DAV:}displayname' => $issetdef('dav:name'),
            '{http://apple.com/ns/ical/}calendar-color' => $issetdef('apple:color'),
            '{http://apple.com/ns/ical/}calendar-order' => $issetdef('apple:order'),
            '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href($sourcePath, false)
        ];

        $this->server->createCollection($homePath . '/' . $jsonData->id, new \Sabre\DAV\MkCol($rt, $props));

        return [201, null];
    }

    public function changeSubscriptionProperties($nodePath, $jsonData) {
        $returncode = 204;
        $davProps = [];
        $propnameMap = [
            'dav:name' => '{DAV:}displayname',
            'apple:color' => '{http://apple.com/ns/ical/}calendar-color',
            'apple:order' => '{http://apple.com/ns/ical/}calendar-order'
        ];

        foreach ($jsonData as $jsonProp => $value) {
            if (isset($propnameMap[$jsonProp])) {
                $davProps[$propnameMap[$jsonProp]] = $value;
            }
        }

        $result = $this->server->updateProperties($nodePath, $davProps);

        foreach ($result as $code) {
            if ((int)$code > 299) {
                $returncode = (int)$code;
                break;
            }
        }

        return [$returncode, null];
    }

    public function getSubscriptionInformation($nodePath, $node, $withRights) {
        $baseUri = $this->server->getBaseUri();

        $calendarHandler = new CalendarHandler($this->server, $this->currentUser);
        $subscription = $calendarHandler->subscriptionToJson($nodePath, $node, $withRights);

        if(!isset($subscription)) {
            return [404, null];
        }

        return [200, $subscription];
    }

    public function getCalendarObjectsForSubscription($subscription, $jsonData) {
        $propertiesList = ['{http://calendarserver.org/ns/}source'];
        $subprops = $subscription->getProperties($propertiesList);

        if (isset($subprops['{http://calendarserver.org/ns/}source'])) {
            $sourcePath = $subprops['{http://calendarserver.org/ns/}source']->getHref();

            if (!$this->server->tree->nodeExists($sourcePath)) {
                return [404, null];
            }

            $sourceNode = $this->server->tree->getNodeForPath($sourcePath);

            $calendarObjectHandler = new CalendarObjectHandler($this->server, $this->currentUser);
            return $calendarObjectHandler->getCalendarObjects($sourcePath, $sourceNode, $jsonData);
        }

        return [404, null];
    }

    public function isBodyForSubscription($jsonData) {
        $issetdef = $this->propertyOrDefault($jsonData);
        return $issetdef('calendarserver:source');
    }

    private function propertyOrDefault($jsonData) {
        return function($key, $default = null) use ($jsonData) {
            return isset($jsonData->{$key}) ? $jsonData->{$key} : $default;
        };
    }
}
