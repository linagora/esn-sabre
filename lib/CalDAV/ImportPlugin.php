<?php
namespace ESN\CalDAV;

use \Sabre\VObject;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\Uri;
use DateTimeZone;

class ImportPlugin extends \ESN\JSON\BasePlugin  {

    /**
     * This is the official CalDAV namespace
     */
    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    function initialize(Server $server) {
        parent::initialize($server);

        $server->on('schedule', [$this, 'schedule'], 99);
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
        return 'caldav-import';
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
            'description' => 'Adds import support for CalDAV',
            'link'        => 'http://sabre.io/dav/caldav/',
        ];
    }

    function schedule(\Sabre\VObject\ITip\Message $iTipMessage) {
        $queryParams = $this->server->httpRequest->getQueryParameters();
        if (!array_key_exists('import', $queryParams)) return;

        return false;
    }
}