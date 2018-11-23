<?php
namespace ESN\CardDAV;

use \Sabre\VObject;
use \Sabre\Uri;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\DAV\Exception;

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
        if(!$this->checkUserAgent($request)) {
            return true;
        }

        $xml = [];
        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);
        if($node instanceof \Sabre\CardDAV\AddressBook || $node instanceof \Sabre\CardDAV\AddressBookHome) {
            try {
                // With some client (DAVDroid), On a PROPFIND for a gab addressBook
                // the xml is not good so we try to parse it, if we can't we do nothing
                $propFindXml = $this->server->xml->expect('{DAV:}multistatus', $response->getBodyAsString());
            } catch (\Exception $e) {
                return true;
            }

            $xmlResponses = $propFindXml->getResponses();

            foreach($xmlResponses as $index => $xmlResponse) {
                $responseProps = $xmlResponse->getResponseProperties();
                $resourceType = isset($responseProps[200]['{DAV:}resourcetype']) ? $responseProps[200]['{DAV:}resourcetype'] : null;
                if (isset($resourceType) && $resourceType->is("{urn:ietf:params:xml:ns:carddav}addressbook")) {
                    $addressBookPath = $xmlResponse->getHref();
                    list($type, $bookId, $addressbookType) = explode('/', trim($addressBookPath, '/'));
                    list(,, $currentUserId) = explode('/', $this->currentUser);

                    $addressBookNode = $this->server->tree->getNodeForPath($addressBookPath);
                    list(,, $ownerId) = explode('/', $addressBookNode->getOwner());
                    list(,, $shareOwnerId) = explode('/', $addressBookNode->getShareOwner());

                    if ($addressBookNode instanceof Group\GroupAddressBook && $addressBookNode->isDisabled()) continue;

                    // Do not return address book if the query book ID is not owner ID
                    if ($bookId !== $ownerId) continue;

                    $userPrincipal = $this->server->tree->getNodeForPath($addressBookNode->getOwner());
                    $userDisplayName = $userPrincipal->getDisplayName() ? $userPrincipal->getDisplayName() : current($userPrincipal->getProperties(['{http://sabredav.org/ns}email-address']));

                    if($shareOwnerId === $currentUserId) {
                        if($addressbookType === 'contacts') {
                            $addressBookDisplayName = 'My Contacts';
                        }
                        else if($addressbookType === 'collected') {
                            $addressBookDisplayName = 'My Collected Contacts';
                        }
                        else {
                            $addressBookDisplayName = $responseProps[200]['{DAV:}displayname'];
                        }
                    } else {
                        if($addressbookType === 'contacts') {
                            $addressBookDisplayName = 'Contacts - ' . $userDisplayName;
                        }
                        else if($addressbookType === 'collected') {
                            $addressBookDisplayName = 'Collected Contacts - '. $userDisplayName;
                        }
                        else if($addressbookType === 'dab') {
                            $addressBookDisplayName = 'Domain address book - '. $userDisplayName;
                        }
                        else {
                            $addressBookDisplayName = $responseProps[200]['{DAV:}displayname'] . " - " . $userDisplayName;
                        }
                    }

                    $responseProps[200]['{DAV:}displayname'] = $addressBookDisplayName;

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