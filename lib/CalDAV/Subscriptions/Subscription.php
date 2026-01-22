<?php

namespace ESN\CalDAV\Subscriptions;

use Sabre\CalDAV\Backend\SubscriptionSupport;
use Sabre\CalDAV\Subscriptions\ISubscription;
use Sabre\DAV\Collection;
use Sabre\DAV\PropPatch;
use Sabre\DAV\Xml\Property\Href;
use Sabre\DAVACL\ACLTrait;
use Sabre\DAVACL\IACL;

/**
 * Subscription Node
 *
 * This node represents a calendar subscription that proxies to a source calendar.
 * Unlike Sabre's default Subscription which returns no children,
 * this implementation fetches and returns calendar objects from the source calendar.
 */
#[\AllowDynamicProperties]
class Subscription extends Collection implements ISubscription, IACL {

    use ACLTrait;

    /**
     * caldavBackend
     *
     * @var SubscriptionSupport
     */
    protected $caldavBackend;

    /**
     * subscriptionInfo
     *
     * @var array
     */
    protected $subscriptionInfo;

    /**
     * Cached source calendar info
     *
     * @var array|null
     */
    protected $sourceCalendarInfo = null;

    /**
     * Constructor
     *
     * @param SubscriptionSupport $caldavBackend
     * @param array $subscriptionInfo
     */
    function __construct(SubscriptionSupport $caldavBackend, array $subscriptionInfo) {
        $this->caldavBackend = $caldavBackend;
        $this->subscriptionInfo = $subscriptionInfo;

        $required = [
            'id',
            'uri',
            'principaluri',
            'source',
        ];

        foreach ($required as $r) {
            if (!isset($subscriptionInfo[$r])) {
                throw new \InvalidArgumentException('The ' . $r . ' field is required when creating a subscription node');
            }
        }
    }

    /**
     * Returns the name of the node.
     *
     * @return string
     */
    function getName() {
        return $this->subscriptionInfo['uri'];
    }

    /**
     * Returns the last modification time
     *
     * @return int
     */
    function getLastModified() {
        if (isset($this->subscriptionInfo['lastmodified'])) {
            return $this->subscriptionInfo['lastmodified'];
        }
    }

    /**
     * Deletes the current node
     *
     * @return void
     */
    function delete() {
        $this->caldavBackend->deleteSubscription(
            $this->subscriptionInfo['id']
        );
    }

    /**
     * Returns an array with all the child nodes (calendar objects from the source calendar)
     *
     * @return \Sabre\DAV\INode[]
     */
    function getChildren() {
        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            return [];
        }

        $objs = $this->caldavBackend->getCalendarObjects($sourceCalendarInfo['id']);
        $children = [];

        foreach ($objs as $obj) {
            $children[] = new SubscriptionObject($this->caldavBackend, $sourceCalendarInfo, $obj);
        }

        return $children;
    }

    /**
     * Returns a single child node by name
     *
     * @param string $name
     * @return \Sabre\DAV\INode
     */
    function getChild($name) {
        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            throw new \Sabre\DAV\Exception\NotFound('Calendar object not found');
        }

        $obj = $this->caldavBackend->getCalendarObject($sourceCalendarInfo['id'], $name);
        if (!$obj) {
            throw new \Sabre\DAV\Exception\NotFound('Calendar object not found: ' . $name);
        }

        return new SubscriptionObject($this->caldavBackend, $sourceCalendarInfo, $obj);
    }

    /**
     * Checks if a child-node with the specified name exists
     *
     * @param string $name
     * @return bool
     */
    function childExists($name) {
        $sourceCalendarInfo = $this->getSourceCalendarInfo();
        if (!$sourceCalendarInfo) {
            return false;
        }

        $obj = $this->caldavBackend->getCalendarObject($sourceCalendarInfo['id'], $name);
        return (bool)$obj;
    }

    /**
     * Returns calendar info for the source calendar that this subscription points to.
     *
     * @return array|null
     */
    protected function getSourceCalendarInfo() {
        if ($this->sourceCalendarInfo !== null) {
            return $this->sourceCalendarInfo ?: null;
        }

        $source = $this->subscriptionInfo['source'];
        if (!$source) {
            $this->sourceCalendarInfo = false;
            return null;
        }

        // Parse the source URL to extract principalUri and calendar URI
        // Format: calendars/{principalId}/{calendarUri}
        $sourcePath = ltrim($source, '/');
        $parts = explode('/', $sourcePath);

        if (count($parts) < 3 || $parts[0] !== 'calendars') {
            error_log("Invalid subscription source format: " . $source);
            $this->sourceCalendarInfo = false;
            return null;
        }

        $principalId = $parts[1];
        $calendarUri = $parts[2];
        $principalUri = 'principals/users/' . $principalId;

        // Get the source calendar
        $calendars = $this->caldavBackend->getCalendarsForUser($principalUri);
        foreach ($calendars as $calendar) {
            if ($calendar['uri'] === $calendarUri) {
                $this->sourceCalendarInfo = $calendar;
                return $this->sourceCalendarInfo;
            }
        }

        error_log("Source calendar not found for subscription: " . $source);
        $this->sourceCalendarInfo = false;
        return null;
    }

    /**
     * Updates properties on this node.
     *
     * @param PropPatch $propPatch
     * @return void
     */
    function propPatch(PropPatch $propPatch) {
        return $this->caldavBackend->updateSubscription(
            $this->subscriptionInfo['id'],
            $propPatch
        );
    }

    /**
     * Returns a list of properties for this nodes.
     *
     * @param array $properties
     * @return array
     */
    function getProperties($properties) {
        $r = [];

        foreach ($properties as $prop) {
            switch ($prop) {
                case '{http://calendarserver.org/ns/}source':
                    $r[$prop] = new Href($this->subscriptionInfo['source']);
                    break;
                default:
                    if (array_key_exists($prop, $this->subscriptionInfo)) {
                        $r[$prop] = $this->subscriptionInfo[$prop];
                    }
                    break;
            }
        }

        return $r;
    }

    /**
     * Returns the owner principal.
     *
     * @return string|null
     */
    function getOwner() {
        return $this->subscriptionInfo['principaluri'];
    }

    /**
     * Returns a list of ACE's for this node.
     *
     * @return array
     */
    function getACL() {
        return [
            [
                'privilege' => '{DAV:}all',
                'principal' => $this->getOwner(),
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}all',
                'principal' => $this->getOwner() . '/calendar-proxy-write',
                'protected' => true,
            ],
            [
                'privilege' => '{DAV:}read',
                'principal' => $this->getOwner() . '/calendar-proxy-read',
                'protected' => true,
            ]
        ];
    }
}
