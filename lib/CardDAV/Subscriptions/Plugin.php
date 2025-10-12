<?php

namespace ESN\CardDAV\Subscriptions;

use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;

/**
 * This plugin adds addressbook-subscription support to your CardDAV server.
 *
 * Some clients support 'managed subscriptions' server-side. This is basically
 * a list of subscription urls a user is using.
 */
#[\AllowDynamicProperties]
class Plugin extends \ESN\JSON\BasePlugin {

    /**
     * This initializes the plugin.
     *
     * This function is called by Sabre\DAV\Server, after
     * addPlugin is called.
     *
     * This method should set up the required event subscriptions.
     *
     * @param Server $server
     * @return void
     */
    function initialize(Server $server) {
        parent::initialize($server);

        $server->on('method:PROPFIND', [$this, 'httpPropfind'], 80);
        $server->on('method:PROPPATCH', [$this, 'httpProppatch'], 80);
    }

    /**
     * Returns a plugin name.
     *
     * Using this name other plugins will be able to access other plugins
     * using \Sabre\DAV\Server::getPlugin
     *
     * @return string
     */
    function getPluginName() {
        return 'carddav-subscription-json';
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
            'description' => 'Adds JSON support for CardDAV subscription.',
            'link'        => null,
        ];

    }

    protected function getSupportedHeaders() {
        return array('application/json', 'application/vcard+json');
    }

    function httpPropfind($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);
        $code = null;
        $body = null;

        if ($node instanceof Subscription) {
            $jsonData = json_decode($request->getBodyAsString(), true);

            $bookProps = $node->getProperties($jsonData['properties']);

            if (isset($bookProps['{http://open-paas.org/contacts}source'])) {
                $baseUri = $this->server->getBaseUri();
                $sourcePath = $bookProps['{http://open-paas.org/contacts}source']->getHref();
                $bookProps['{http://open-paas.org/contacts}source'] = $baseUri . $sourcePath . '.json';
            }

            $code = 200;
            $body = $bookProps;
        }

        return $this->send($code, $body);
    }

    function httpProppatch($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }
        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        $code = null;
        $body = null;

        if ($node instanceof \ESN\CardDAV\Subscriptions\Subscription) {
            list($code, $body) = $this->changeSubscriptionProperties(
                $path,
                $node,
                json_decode($request->getBodyAsString())
            );
        }

        return $this->send($code, $body);
    }

    private function changeSubscriptionProperties($nodePath, $node, $jsonData) {
        $returncode = 204;
        $davProps = [];
        $propnameMap = [
            'dav:name' => '{DAV:}displayname',
            'carddav:description' => '{urn:ietf:params:xml:ns:carddav}addressbook-description'
        ];

        foreach ($jsonData as $jsonProp => $value) {
            if (isset($propnameMap[$jsonProp])) {
                $davProps[$propnameMap[$jsonProp]] = $value;
            }
        }

        $result = $this->server->updateProperties($nodePath, $davProps);

        foreach ($result as $prop => $code) {
            if ((int)$code > 299) {
                $returncode = (int)$code;
                break;
            }
        }

        return [$returncode, null];
    }
}
