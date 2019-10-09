<?php

namespace ESN\DAVACL;

use Sabre\DAV;

class Plugin extends \ESN\JSON\BasePlugin {

    function initialize(DAV\Server $server) {
        parent::initialize($server);

        $server->on('method:PROPFIND', [$this, 'propFind'], 80);
    }

    public function propFind($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        /* Adding principal properties */
        if ($node instanceof \Sabre\DAVACL\Principal) {
            $groupMemberSet = $node->getGroupMemberSet();
            foreach ($groupMemberSet as $k => $member) {
                $groupMemberSet[$k] = rtrim($member, '/').'/';
            }

            $groupMemberShip = $node->getGroupMembership();
            foreach ($groupMemberShip as $k => $member) {
                $groupMemberShip[$k] = rtrim($member, '/').'/';
            }

            $body = [
              'alternate-URI-set' => $node->getAlternateUriSet(),
              'principal-URL' => $node->getPrincipalUrl().'/',
              'group-member-set' => $groupMemberSet,
              'group-membership' => $groupMemberShip
            ];

            $this->send(200, $body);
            return false;
        }

        return true;
    }

    function send($code, $body = null, $setContentType = true) {
        if (!isset($code)) {
            return true;
        }

        $this->server->httpResponse->setHeader('Content-Type','application/json; charset=utf-8');

        if ($body) {
            $this->server->httpResponse->setBody(json_encode($body));
        }

        $this->server->httpResponse->setStatus($code);
        return false;
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
        return 'davacl-json';
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
            'description' => 'Adds JSON support for DAVACL',
        ];
    }
}