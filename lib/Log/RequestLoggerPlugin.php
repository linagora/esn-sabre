<?php
namespace ESN\Log;

use \Sabre\DAV\Server;

class RequestLoggerPlugin extends \ESN\JSON\BasePlugin {

    /**
     * This is the official CalDAV namespace
     */

    function initialize(Server $server) {
        parent::initialize($server);

        $server->on('afterResponse', [$this, 'afterResponseLogger']);
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
        return 'request_logger';
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
            'description' => 'internal plugin to log the response of all external requests',
            'link'        => 'http://sabre.io/dav/caldav/',
        ];
    }

    function afterResponseLogger($request, $response)
    {
        $logger = $this->server->getLogger();

        if (isset($logger) && isset($logger->debug)) {
            $logger->debug('Request: ', [$request]);
            $logger->debug('Response: ', [$response]);
        }
    }
}