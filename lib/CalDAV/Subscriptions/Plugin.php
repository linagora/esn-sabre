<?php

namespace ESN\CalDAV\Subscriptions;

use Sabre\CalDAV\Calendar;
use Sabre\CalDAV\CalendarHome;
use Sabre\CalDAV\Plugin as CalDAVPlugin;
use Sabre\CalDAV\Subscriptions\ISubscription;
use Sabre\DAV;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;

/**
 * This plugin extends Sabre's calendar subscription support to properly expose
 * the supported-calendar-component-set property in PROPFIND responses.
 */
class Plugin extends \Sabre\CalDAV\Subscriptions\Plugin {

    const SOURCE_PROPERTY = '{http://calendarserver.org/ns/}source';

    protected $server;

    /**
     * This initializes the plugin.
     *
     * @param Server $server
     * @return void
     */
    function initialize(Server $server) {
        parent::initialize($server);
        $this->server = $server;

        // Add our propFind handler with higher priority to run after the parent's handler
        $server->on('propFind', [$this, 'propFindSubscription'], 151);
        // Before Sabre's MKCOL handler (100).
        $server->on('method:MKCOL', [$this, 'httpMkcol'], 90);
        $server->on('propPatch', [$this, 'checkSubscriptionSource'], 90);
    }

    // XML MKCOL bypasses the JSON subscription handler's source ACL check.
    // Subscriptions are created here rather than by Sabre's MKCOL handler, which
    // would store the raw source href instead of the path whose access was checked.
    function httpMkcol($request, $response) {
        list($parentPath) = \Sabre\Uri\split($request->getPath());
        if (!$this->server->tree->nodeExists($parentPath)
            || !$this->server->tree->getNodeForPath($parentPath) instanceof CalendarHome) {
            return;
        }

        $contentType = strtolower(explode(';', $request->getHeader('Content-Type') ?? '')[0]);
        if (!in_array($contentType, ['application/xml', 'text/xml'])) {
            return;
        }

        $body = $request->getBodyAsString();
        // Sabre's MKCOL handler still needs to read the request body.
        $request->setBody($body);

        // Leave malformed/unsupported requests to Sabre's normal MKCOL handler.
        try {
            $properties = $this->server->xml->expect('{DAV:}mkcol', $body)->getProperties();
        } catch (\Sabre\Xml\ParseException $e) {
            return;
        }
        $resourceType = $properties['{DAV:}resourcetype'] ?? null;
        if (!$resourceType?->is('{http://calendarserver.org/ns/}subscribed')) {
            return;
        }

        $properties[self::SOURCE_PROPERTY] = $this->readableSource($properties[self::SOURCE_PROPERTY] ?? null);
        unset($properties['{DAV:}resourcetype']);

        $result = $this->server->createCollection($request->getPath(), new DAV\MkCol($resourceType->getValue(), $properties));

        if (is_array($result)) {
            $response->setStatus(207);
            $response->setHeader('Content-Type', 'application/xml; charset=utf-8');
            $response->setBody($this->server->generateMultiStatus([$result]));
        } else {
            $response->setHeader('Content-Length', '0');
            $response->setStatus(201);
        }

        return false;
    }

    function checkSubscriptionSource($path, DAV\PropPatch $propPatch) {
        $mutations = $propPatch->getRemainingValues();
        if (!array_key_exists(self::SOURCE_PROPERTY, $mutations)) {
            return;
        }
        $node = $this->server->tree->getNodeForPath($path);
        if (!$node instanceof ISubscription) {
            return;
        }

        // Owning a subscription does not grant access to a different source calendar.
        $source = $this->readableSource($mutations[self::SOURCE_PROPERTY]);

        // The PropPatch value cannot be replaced, so store the checked path through a PropPatch of our own.
        $propPatch->handle(self::SOURCE_PROPERTY, function() use ($node, $source) {
            $sourcePatch = new DAV\PropPatch([self::SOURCE_PROPERTY => $source]);
            $node->propPatch($sourcePatch);

            return $sourcePatch->commit();
        });
    }

    /**
     * Checks that the current user may read the calendar a source href points to.
     *
     * @return DAV\Xml\Property\Href the canonical calendars/<home>/<uri> path that was checked
     */
    private function readableSource($source) {
        if (!$source instanceof DAV\Xml\Property\Href || !$source->getHref()) {
            throw new DAV\Exception\BadRequest('A subscription requires a source href');
        }

        $sourcePath = $this->server->calculateUri($source->getHref());
        if (!preg_match('|^calendars/[^/]+/[^/]+$|', $sourcePath)
            || !$this->server->tree->nodeExists($sourcePath)
            || !$this->server->tree->getNodeForPath($sourcePath) instanceof Calendar) {
            throw new DAV\Exception\BadRequest('A subscription source must be a calendar');
        }

        $this->server->getPlugin('acl')->checkPrivileges($sourcePath, '{DAV:}read');

        return new DAV\Xml\Property\Href($sourcePath, false);
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
