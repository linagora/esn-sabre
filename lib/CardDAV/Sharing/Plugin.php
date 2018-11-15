<?php

namespace ESN\CardDAV\Sharing;

use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\DAV\Xml\Element\Sharee;
use Sabre\DAV\Xml\Property;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use \ESN\Utils\Utils as Utils;

/**
 * This plugin implements HTTP requests and properties related to:
 *
 * draft-pot-webdav-resource-sharing
 *
 * This specification allows people to share webdav resources with others.
 *
 */
class Plugin extends \ESN\JSON\BasePlugin {

    /**
     * Returns a plugin name.
     *
     * Using this name other plugins will be able to access other plugins
     * using \Sabre\DAV\Server::getPlugin
     *
     * @return string
     */
    function getPluginName() {
        return 'carddav-sharing-json';
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
            'description' => 'This plugin implements JSON CardDAV resource sharing',
            'link'        => 'https://github.com/evert/webdav-sharing'
        ];
    }

    protected function getSupportedHeaders() {
        return array('application/json', 'application/vcard+json');
    }

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

        $server->resourceTypeMapping['ESN\\CardDAV\\Sharing\SharedAddressBook'] = '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}shared';

        $server->on('method:POST', [$this, 'httpPost']);
        $server->on('method:PROPFIND', [$this, 'httpPropfind'], 80);
    }

    /**
     * We intercept this to handle POST requests on shared resources
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return null|bool
     */
    function httpPost(RequestInterface $request, ResponseInterface $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);
        $code = null;
        $body = null;

        if ($node instanceof \ESN\CardDAV\Sharing\ISharedAddressBook) {
            $requestBody = $request->getBodyAsString();
            $jsonData = json_decode($requestBody);

            $data = null;

            if ($data = Utils::getJsonValue($jsonData, 'dav:share-resource')) {
                $sharees = Utils::getJsonValue($data, 'dav:sharee', []);
                $sharingPlugin = $this->server->getPlugin('sharing');
                $sharingPlugin->shareResource($path, $this->jsonToSharees($sharees));

                $code = 204;
                return $this->send($code, $body);
            }

            if ($data = Utils::getJsonValue($jsonData, 'dav:invite-reply')) {
                $accepted = Utils::getJsonValue($data, 'dav:invite-accepted', false);

                if ($accepted) {
                    $options = [];

                    if ($slug = Utils::getJsonValue($data, 'dav:slug', false)) {
                        $options['dav:slug'] = $slug;
                    }

                    $node->replyInvite(\Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED, $options);
                }

                $code = 204;
                return $this->send($code, $body);
            }

            if ($data = Utils::getJsonValue($jsonData, 'dav:publish-addressbook')) {
                $this->server->getPlugin('acl')->checkPrivileges($path, '{DAV:}share');

                $privilege = Utils::getJsonValue($data, 'privilege', false);

                if (!in_array($privilege, ['{DAV:}read', '{DAV:}write'])) {
                    throw new \Sabre\DAV\Exception\BadRequest('Privilege must be either {DAV:}read or {DAV:}write');
                }

                $node->setPublishStatus($privilege);

                $code = 204;
                return $this->send($code, $body);
            }

            if ($data = Utils::getJsonValue($jsonData, 'dav:unpublish-addressbook')) {
                $this->server->getPlugin('acl')->checkPrivileges($path, '{DAV:}share');

                $node->setPublishStatus(false);

                $code = 204;
                return $this->send($code, $body);
            }

            if ($data = Utils::getJsonValue($jsonData, 'dav:group-addressbook')) {
                $this->server->getPlugin('acl')->checkPrivileges($path, '{DAV:}share');
                $supportMembersRights = [
                    '{DAV:}read',
                    '{DAV:}write-content',
                    '{DAV:}bind',
                    '{DAV:}unbind'
                ];

                $privileges = Utils::getJsonValue($data, 'privileges', false);

                if (!is_array($privileges)) {
                    throw new \Sabre\DAV\Exception\BadRequest('Privileges must be an array');
                }

                if (empty($privileges)) {
                    throw new \Sabre\DAV\Exception\BadRequest('Privileges must not an empty array');
                }

                foreach ($privileges as $privilege) {
                    if (!in_array($privilege, $supportMembersRights)) {
                        throw new \Sabre\DAV\Exception\BadRequest('Privilege is not supported. Supported privileges are ' . join(',', $supportMembersRights));
                    }
                }

                $node->setMembersRight($privileges);

                $code = 204;
                return $this->send($code, $body);
            }

            // If this request handler could not deal with this POST request, it
            // will return 'null' and other plugins get a chance to handle the
            // request.
            //
            // However, we already requested the full body. This is a problem,
            // because a body can only be read once. This is why we preemptively
            // re-populated the request body with the existing data.
            $request->setBody($requestBody);
        }

        return true;
    }

    function httpPropfind($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);
        $code = null;
        $body = null;

        if ($node instanceof SharedAddressBook) {
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

    private function jsonToSharees(array $sharees) {
        $result = [];

        foreach ($sharees as $sharee) {
            $result[] = new \Sabre\DAV\Xml\Element\Sharee([
                'href' => Utils::getJsonValue($sharee, 'dav:href'),
                'access' => (int)Utils::getJsonValue($sharee, 'dav:share-access', \Sabre\DAV\Sharing\Plugin::ACCESS_NOACCESS),
                'properties' => []
            ]);
        }

        return $result;
    }

}
