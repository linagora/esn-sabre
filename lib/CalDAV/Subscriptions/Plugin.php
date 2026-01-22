<?php

namespace ESN\CalDAV\Subscriptions;

use Sabre\CalDAV\Plugin as CalDAVPlugin;
use Sabre\CalDAV\Subscriptions\ISubscription;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;

/**
 * This plugin extends Sabre's calendar subscription support to properly expose
 * the supported-calendar-component-set property in PROPFIND responses.
 */
class Plugin extends \Sabre\CalDAV\Subscriptions\Plugin {

    /**
     * This initializes the plugin.
     *
     * @param Server $server
     * @return void
     */
    function initialize(Server $server) {
        parent::initialize($server);

        // Add our propFind handler with higher priority to run after the parent's handler
        $server->on('propFind', [$this, 'propFindSubscription'], 151);
    }

    /**
     * Triggered after properties have been fetched.
     *
     * This adds the supported-calendar-component-set property for subscription nodes.
     *
     * @param PropFind $propFind
     * @param INode $node
     * @return void
     */
    function propFindSubscription(PropFind $propFind, INode $node) {
        if (!$node instanceof ISubscription) {
            return;
        }

        $sccs = '{' . CalDAVPlugin::NS_CALDAV . '}supported-calendar-component-set';

        // For allprops requests or when the property hasn't been set yet,
        // we need to add it directly using set() rather than handle()
        if ($propFind->isAllProps() || $propFind->getStatus($sccs) === 404) {
            $value = $this->getSupportedCalendarComponentSet($node, $sccs);
            $propFind->set($sccs, $value, 200);
        } else {
            // For explicit property requests, use handle()
            $propFind->handle($sccs, function() use ($node, $sccs) {
                return $this->getSupportedCalendarComponentSet($node, $sccs);
            });
        }
    }

    /**
     * Get the supported calendar component set for a subscription node.
     *
     * @param INode $node
     * @param string $sccs The property name
     * @return \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet
     */
    private function getSupportedCalendarComponentSet(INode $node, $sccs) {
        // Get the subscription info which should contain the supported-calendar-component-set
        if (method_exists($node, 'getProperties')) {
            $props = $node->getProperties([$sccs]);
            if (isset($props[$sccs])) {
                return $props[$sccs];
            }
        }

        // Default to VEVENT and VTODO if not found in subscription info
        return new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(['VEVENT', 'VTODO']);
    }

    /**
     * Returns a plugin name.
     *
     * @return string
     */
    function getPluginName() {
        return 'esn-caldav-subscriptions';
    }

    /**
     * Returns a bunch of meta-data about the plugin.
     *
     * @return array
     */
    function getPluginInfo() {
        return [
            'name'        => $this->getPluginName(),
            'description' => 'This plugin extends calendar subscriptions with proper supported-calendar-component-set support.',
            'link'        => null,
        ];
    }
}
