<?php
namespace ESN\CardDAV;

use \Sabre\VObject;
use \Sabre\Uri;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\DAV\Exception;

#[\AllowDynamicProperties]
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
        if($this->acceptJson()) {
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
            $modified = false;

            foreach($xmlResponses as $index => $xmlResponse) {
                $responseProps = $xmlResponse->getResponseProperties();
                $resourceType = isset($responseProps[200]['{DAV:}resourcetype']) ? $responseProps[200]['{DAV:}resourcetype'] : null;

                if (isset($resourceType) && $resourceType->is("{urn:ietf:params:xml:ns:carddav}addressbook")) {
                    // Only modify displayname if it was requested in the PROPFIND
                    if (!array_key_exists('{DAV:}displayname', $responseProps[200] ?? [])) {
                        $xml[] = ['{DAV:}response' => $xmlResponse];
                        continue;
                    }

                    $addressBookPath = $xmlResponse->getHref();
                    list($type, $bookId, $addressbookType) = explode('/', trim($addressBookPath, '/'));
                    list(,, $currentUserId) = explode('/', $this->currentUser);

                    $addressBookNode = $this->server->tree->getNodeForPath($addressBookPath);
                    list(,, $shareOwnerId) = explode('/', $addressBookNode->getShareOwner());

                    $existingDisplayName = $responseProps[200]['{DAV:}displayname'] ?? '';

                    $userPrincipal = $this->server->tree->getNodeForPath($addressBookNode->getShareOwner());
                    $userDisplayName = $userPrincipal->getDisplayName() ? $userPrincipal->getDisplayName() : current($userPrincipal->getProperties(['{http://sabredav.org/ns}email-address']));

                    if ($shareOwnerId === $currentUserId) {
                        // User's own address books: rename only if no existing displayname
                        if (empty($existingDisplayName)) {
                            $modified = true;
                            if ($addressbookType === 'collected') {
                                $responseProps[200]['{DAV:}displayname'] = $userDisplayName . ' (collected)';
                            } else {
                                $responseProps[200]['{DAV:}displayname'] = $userDisplayName;
                            }
                            $newResponse = new \Sabre\DAV\Xml\Element\Response($xmlResponse->getHref(), $responseProps);
                            $xml[] = ['{DAV:}response' => $newResponse];
                        } else {
                            $xml[] = ['{DAV:}response' => $xmlResponse];
                        }
                    } else {
                        // Shared/delegated address books: add owner name or just show owner name if no displayname
                        $modified = true;

                        if (!empty($existingDisplayName)) {
                            $addressBookDisplayName = $existingDisplayName . " - " . $userDisplayName;
                        } else {
                            $addressBookDisplayName = $userDisplayName;
                        }

                        $responseProps[200]['{DAV:}displayname'] = $addressBookDisplayName;
                        $newResponse = new \Sabre\DAV\Xml\Element\Response($xmlResponse->getHref(), $responseProps);
                        $xml[] = ['{DAV:}response' => $newResponse];
                    }
                } else {
                    $xml[] = ['{DAV:}response' => $xmlResponse];
                }
            }

            if ($modified) {
                $data = $this->server->xml->write('{DAV:}multistatus', $xml);
                $response->setBody($data);
            }
        }
    }
}