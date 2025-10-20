<?php
namespace ESN\JSON;

use Sabre\DAV\ServerPlugin;

#[\AllowDynamicProperties]
class BasePlugin extends ServerPlugin {

    const USER_AGENT_REGEXP = [
        "/DAVdroid.*/",
        "/Thunderbird.*/",
        "/DAVx5*/"
    ];

    protected $server;
    protected $acceptHeader;
    protected $currentUser;

    function initialize(\Sabre\DAV\Server $server) {
        $this->server = $server;

        $server->on('beforeMethod:*', [$this, 'beforeMethod'], 15); // 15 is after Auth and before ACL
    }

    function beforeMethod($request, $response) {
        $url = $request->getUrl();
        if (strpos($url, '.json') !== false) {
            $url = str_replace('.json','', $url);
            $request->setUrl($url);
        }

        $this->acceptHeader = explode(', ', $request->getHeader('Accept') ?? '');
        $this->currentUser = $this->server->getPlugin('auth')->getCurrentPrincipal();

        return true;
    }

    protected function send($code, $body, $setContentType = true) {
        if (!isset($code)) {
            return true;
        }

        if ($body) {
            if ($setContentType) {
                $this->server->httpResponse->setHeader('Content-Type','application/json; charset=utf-8');
            }
            $this->server->httpResponse->setBody(json_encode($body));
        }
        $this->server->httpResponse->setStatus($code);
        return false;
    }

    protected function acceptJson() {
        return count(array_intersect($this->getSupportedHeaders(), $this->acceptHeader)) > 0;
    }

    protected function getSupportedHeaders() {
        return array('application/json');
    }

    protected function checkUserAgent($request) {
        $userAgents = $request->getHeader('User-Agent');

        if (!isset($userAgents)) {
            return false;
        }

        $userAgent = false;

        foreach(self::USER_AGENT_REGEXP as $userAgentRegexp) {
            if(preg_match($userAgentRegexp, $userAgents)) {
                $userAgent = true;
            }
        }

        return $userAgent;
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
