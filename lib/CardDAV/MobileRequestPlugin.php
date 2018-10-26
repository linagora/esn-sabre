<?php
namespace ESN\CardDAV;

use \Sabre\VObject;
use \Sabre\Uri;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;

class MobileRequestPlugin extends \ESN\JSON\BasePlugin {

    /**
     * This is the official CardDAV namespace
     */
    const NS_CALDAV = 'urn:ietf:params:xml:ns:carddav';

    function initialize(Server $server) {
        parent::initialize($server);

        $server->on('afterMethod:PROPFIND', [$this, 'afterMethodPropfind']);
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
        return 'carddav-mobile-request';
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
            'description' => 'support of some mobile dav client for CardDAV',
            'link'        => 'http://sabre.io/dav/carddav/',
        ];
    }

    function afterMethodPropfind($request, $response) {
        if(!$this->checkUserAgent($request) && !$this->acceptJson()) {
            return true;
        }

        $xml = [];
        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        if($node instanceof \Sabre\CardDAV\AddressBookHome) {
            $propFindXml = $this->server->xml->expect('{DAV:}multistatus', $response->getBodyAsString());
            $xmlResponses = $propFindXml->getResponses();

            foreach($xmlResponses as $index => $xmlResponse) {
                $responseProps = $xmlResponse->getResponseProperties();
                $resourceType = isset($responseProps[200]['{DAV:}resourcetype']) ? $responseProps[200]['{DAV:}resourcetype'] : null;
                
                if (isset($resourceType) && $resourceType->is("{urn:ietf:params:xml:ns:carddav}addressbook")) {
                    $addressBookPath = $xmlResponse->getHref();
                    list($type, $ownerId, $addressbookType) = explode('/', trim($addressBookPath, '/'));
                    list(,, $currentUserId) = explode('/', $this->currentUser);

                    $addressBookNode = $this->server->tree->getNodeForPath($addressBookPath);
                    $userPrincipal = $this->server->tree->getNodeForPath($addressBookNode->getOwner());
                    $userDisplayName = $userPrincipal->getDisplayName() ? $userPrincipal->getDisplayName() : current($userPrincipal->getProperties(['{http://sabredav.org/ns}email-address']));

                    if($ownerId === $currentUserId) {
                        $addressBookDisplayName = $addressbookType === 'contacts' ? 'My Contacts' : 'My Collected Contacts';
                    } else {
                        $addressBookDisplayName = $addressbookType === 'contacts' ? 'Contacts - ' . $userDisplayName : 'Collected Contacts - '. $userDisplayName;
                    }

                    $responseProps[200]['{DAV:}displayname'] = isset($responseProps[200]['{DAV:}displayname']) ?
                                    $responseProps[200]['{DAV:}displayname'] . " - " . $userDisplayName :
                                    $addressBookDisplayName;

                    $newResponse = new \Sabre\DAV\Xml\Element\Response($xmlResponse->getHref(), $responseProps);

                    $xml[] = ['{DAV:}response' => $newResponse];
                } else {
                    $xml[] = ['{DAV:}response' => $xmlResponse];
                }
            }

            $service = new \Sabre\Xml\Service();
            $data = $service->write('{DAV:}multistatus', $xml);

            $response->setBody($data);
        }
    }
}