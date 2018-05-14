<?php
namespace ESN\JSON;

use Sabre\DAV\ServerPlugin;

class BasePlugin extends ServerPlugin {

    function initialize(\Sabre\DAV\Server $server) {
        $this->server = $server;

        $server->on('beforeMethod', [$this, 'beforeMethod'], 15); // 15 is after Auth and before ACL
    }

    function beforeMethod($request, $response) {
        $url = $request->getUrl();
        if (strpos($url, '.json') !== false) {
            $url = str_replace('.json','', $url);
            $request->setUrl($url);
        }

        $this->acceptHeader = explode(', ', $request->getHeader('Accept'));
        $this->currentUser = $this->server->getPlugin('auth')->getCurrentPrincipal();

        return true;
    }

    protected function acceptJson() {
        return count(array_intersect($this->getSupportedHeaders(), $this->acceptHeader)) > 0;
    }

    protected function getSupportedHeaders() {
        return array('application/json');
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
        throw new \Exception('You must override this method');
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
        throw new \Exception('You must override this method');
    }
}
