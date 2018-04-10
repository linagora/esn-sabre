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
class Plugin extends ServerPlugin {

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
        $this->server = $server;

        $server->on('method:DELETE', [$this, 'delete'], 80);
        $server->on('method:PROPPATCH', [$this, 'proppatch'], 80);
    }

    function delete($request, $response) {
        $acceptHeader = explode(', ', $request->getHeader('Accept'));
        if (!$this->acceptJson($acceptHeader)) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        $code = null;
        $body = null;

        if ($node instanceof \ESN\CardDAV\Subscriptions\Subscription) {
            list($code, $body) = $this->deleteSubscription($node);
        }

        return $this->send($code, $body);
    }

    function proppatch($request, $response) {
        $acceptHeader = explode(', ', $request->getHeader('Accept'));
        if (!$this->acceptJson($acceptHeader)) {
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

    private function deleteSubscription($node) {
        $node->delete();

        return [204, null];
    }

    private function send($code, $body, $setContentType = true) {
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

    protected function isBodyForSubscription($jsonData) {
        $issetdef = $this->propertyOrDefault($jsonData);

        return $issetdef('openpaas:source');
    }

    private function propertyOrDefault($jsonData) {
        return function($key, $default = null) use ($jsonData) {
            return isset($jsonData->{$key}) ? $jsonData->{$key} : $default;
        };
    }

    private function acceptJson($acceptHeader) {
        return in_array('application/vcard+json', $acceptHeader) ||
               in_array('application/json', $acceptHeader);
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

        return 'address book subscriptions';

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
            'description' => 'This plugin allows users to store iCalendar subscriptions in their calendar-home.',
            'link'        => null,
        ];

    }
}
