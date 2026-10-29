<?php
namespace ESN\CardDAV;

use \Sabre\VObject;
use \Sabre\Uri;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\DAV\Exception;
use ESN\Utils\XmlText;

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
                // Stored display names may also carry characters XML 1.0 forbids: strip them first
                $propFindXml = $this->server->xml->expect('{DAV:}multistatus', XmlText::stripIllegalCharacters($response->getBodyAsString()));
            } catch (\Exception $e) {
                return true;
            }

            $xmlResponses = $propFindXml->getResponses();

            foreach($xmlResponses as $index => $xmlResponse) {
                $responseProps = $xmlResponse->getResponseProperties();
                $resourceType = isset($responseProps[200]['{DAV:}resourcetype']) ? $responseProps[200]['{DAV:}resourcetype'] : null;
                if (isset($resourceType) && $resourceType->is("{urn:ietf:params:xml:ns:carddav}addressbook")) {
                    $addressBookPath = \Sabre\HTTP\decodePath($xmlResponse->getHref());
                    list($type, $bookId, $addressbookType) = explode('/', trim($addressBookPath, '/'));
                    list(,, $currentUserId) = explode('/', $this->currentUser);

                    $addressBookNode = $this->server->tree->getNodeForPath($addressBookPath);
                    list(,, $ownerId) = explode('/', $addressBookNode->getOwner());
                    list(,, $shareOwnerId) = explode('/', $addressBookNode->getShareOwner());

                    if ($addressBookNode instanceof Group\GroupAddressBook && $addressBookNode->isDisabled()) continue;

                    // Do not return address book if the query book ID is not owner ID
                    if ($bookId !== $ownerId) continue;

                    $userPrincipal = $this->server->tree->getNodeForPath($addressBookNode->getShareOwner());
                    $userDisplayName = XmlText::stripIllegalCharacters($userPrincipal->getDisplayName() ? $userPrincipal->getDisplayName() : current($userPrincipal->getProperties(['{http://sabredav.org/ns}email-address'])));

                    if($shareOwnerId === $currentUserId) {
                        if($addressbookType === 'contacts') {
                            $addressBookDisplayName = 'My Contacts';
                        }
                        else if($addressbookType === 'collected') {
                            $addressBookDisplayName = 'My Collected Contacts';
                        }
                        else {
                            $addressBookDisplayName = $responseProps[200]['{DAV:}displayname'] ?? "Carnet d'addresse - nom indefinit";
                        }
                    } else {
                        if (array_key_exists("{DAV:}displayname", $responseProps[200])) {
                            if($responseProps[200]['{DAV:}displayname'] === 'Collected contacts') {
                                $addressBookDisplayName = 'Collected Contacts - '. $userDisplayName;
                            }
                            else if($responseProps[200]['{DAV:}displayname'] === 'My contacts') {
                                $addressBookDisplayName = 'Contacts - ' . $userDisplayName;
                            }
                            else {
                                $addressBookDisplayName = $responseProps[200]['{DAV:}displayname'] . " - " . $userDisplayName;
                            }
                        }
                        else if($addressbookType === 'dab') {
                            $addressBookDisplayName = 'Domain address book - '. $userDisplayName;
                        }
                        else {
                            $addressBookDisplayName = "Domain members";
                        }
                    }

                    $responseProps[200]['{DAV:}displayname'] = $addressBookDisplayName;

                    $xml[] = ['{DAV:}response' => $this->rebuildResponse($xmlResponse, $responseProps)];
                } else {
                    $xml[] = ['{DAV:}response' => $this->rebuildResponse($xmlResponse, $xmlResponse->getResponseProperties())];
                }
            }

            $service = new \Sabre\Xml\Service();
            $data = $service->write('{DAV:}multistatus', $xml, '');
            
            $response->setBody($data);
        }
    }

    /**
     * Parsed hrefs are percent-encoded while Response::xmlSerialize encodes
     * the href it is given: rebuild the response from the decoded path so
     * that the href is not encoded twice.
     */
    private function rebuildResponse($xmlResponse, array $responseProps) {
        return new \Sabre\DAV\Xml\Element\Response(
            \Sabre\HTTP\decodePath($xmlResponse->getHref()),
            $responseProps,
            $xmlResponse->getHttpStatus()
        );
    }
}
