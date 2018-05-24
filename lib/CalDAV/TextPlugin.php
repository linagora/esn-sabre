<?php
namespace ESN\CalDAV;

use \Sabre\VObject;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;

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

        $code = null;
        $body = null;
        $path = $request->getPath();
        $jsonData = json_decode($request->getBodyAsString());

        $node = $this->server->tree->getNodeForPath($path);

        if ($node instanceof \Sabre\CalDAV\ICalendarObjectContainer) {
            list($code, $body) = $this->getCalendarObjects($path, $node, $jsonData);
        } else {
            $code = 204;
            $body = [];
        }

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

        $allVcal = [];
        $baseUri = $this->server->getBaseUri();
        $props = [ '{' . self::NS_CALDAV . '}calendar-data', '{DAV:}getetag' ];

        foreach ($paths as $path) {
            list($properties) = $this->server->getPropertiesForPath($parentNodePath . '/' . $path, $props);

            $allVcal[] = VObject\Reader::read($properties[200]['{' . self::NS_CALDAV . '}calendar-data']);
        }

        $vcal = array_shift($allVcal);
    
        foreach($allVcal as $cal) {
            foreach($cal->VEVENT as $vevent) {
                $vcal->add($vevent);
            }
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