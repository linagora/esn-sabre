<?php
namespace ESN\CalDAV;

use \Sabre\VObject;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;

#[\AllowDynamicProperties]
class TextPlugin extends \ESN\JSON\BasePlugin {

    /**
     * This is the official CalDAV namespace
     */
    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    function initialize(Server $server) {
        parent::initialize($server);

        $server->on('method:GET', [$this, 'httpGet'], 80);
    }

    /**
     * Returns a plugin name.
     *
     * Using this name other plugins will be able to access other plugins
     * using DAV\Server::getPlugin
     *
     * @return string
     */
    function getPluginName() {
        return 'caldav-text';
    }

    /**
     * Returns a bunch of meta-data about the plugin.
     *
     * Providing this information is optional, and is mainly displayed by the
     * Browser plugin.
     *
     * The description key in the returned array may contain html and will not
     * be sanitized.
     *
     * @return array
     */
    function getPluginInfo() {
        return [
            'name'        => $this->getPluginName(),
            'description' => 'Adds Text support for CalDAV',
            'link'        => 'http://sabre.io/dav/caldav/',
        ];
    }

    function httpGet($request, $response) {
        if (!$this->acceptText()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        if($node instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
            return $this->send(204, []);
        }

        if (!($node instanceof \Sabre\CalDAV\ICalendarObjectContainer)) {
            return true;
        }

        $jsonData = json_decode($request->getBodyAsString());
        list($code, $body) = $this->getCalendarObjects($path, $node, $jsonData);

        return $this->send($code, $body);
    }

    function getCalendarObjects($path, $node, $jsonData = null) {
        //For now we get the full calendar without any range of date :(
        $start = null;
        $end = null;

        $filters = [
            'name' => 'VCALENDAR',
            'comp-filters' => [
                [
                    'name' => 'VEVENT',
                    'comp-filters' => [],
                    'prop-filters' => [],
                    'is-not-defined' => false,
                    'time-range' => [
                        'start' => $start,
                        'end' => $end,
                    ],
                ],
            ],
            'prop-filters' => [],
            'is-not-defined' => false,
            'time-range' => null,
        ];

        return [200, $this->getMultipleDAVItems($path, $node, $node->calendarQuery($filters), $start, $end)];
    }

    function getMultipleDAVItems($parentNodePath, $parentNode, $paths, $start = false, $end = false) {
        if (empty($paths)) {
            return "";
        }

        $calendarData = [];
        $props = [ '{' . self::NS_CALDAV . '}calendar-data', '{DAV:}getetag' ];

        foreach ($paths as $path) {
            list($properties) = $this->server->getPropertiesForPath($parentNodePath . '/' . $path, $props);

            $calendarData[] = $properties[200]['{' . self::NS_CALDAV . '}calendar-data'];
        }

        // The components are merged as by the ICS export, which keeps the
        // VTIMEZONEs the objects use, each definition of a TZID under a TZID
        // of its own; the properties of the first object's VCALENDAR are kept.
        $merged = (new ICSExportPlugin())->mergeObjects([], $calendarData);
        $vcal = VObject\Reader::read($calendarData[0]);
        foreach ($vcal->getComponents() as $component) {
            $vcal->remove($component);
        }
        foreach ($merged->getComponents() as $component) {
            $vcal->add($component);
        }

        return $vcal->serialize();
    }

    function send($code, $body, $setContentType = true) {
        if (!isset($code)) {
            return true;
        }

        if ($body) {
            if ($setContentType) {
                    $this->server->httpResponse->setHeader('Content-Type','text/calendar');
            }

            $this->server->httpResponse->setBody($body);
        }
        $this->server->httpResponse->setStatus($code);
        return false;
    }

    function acceptText() {
        return in_array('text/calendar', $this->acceptHeader);
    }
}