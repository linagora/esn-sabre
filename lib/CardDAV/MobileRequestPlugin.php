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

        $path = $request->getPath();

        try {
            $node = $this->server->tree->getNodeForPath($path);
        } catch (\Exception $e) {
            return true;
        }

        if(!($node instanceof \Sabre\CardDAV\AddressBook) && !($node instanceof \Sabre\CardDAV\AddressBookHome)) {
            return true;
        }

        try {
            // With some client (DAVDroid), On a PROPFIND for a gab addressBook
            // the xml is not good so we try to parse it, if we can't we do nothing
            $propFindXml = $this->server->xml->expect('{DAV:}multistatus', $response->getBodyAsString());
        } catch (\Exception $e) {
            return true;
        }

        $xmlResponses = $propFindXml->getResponses();
        $xml = [];
        $modified = false;

        foreach($xmlResponses as $index => $xmlResponse) {
            $responseProps = $xmlResponse->getResponseProperties();

            try {
                $addressBookPath = trim($xmlResponse->getHref(), '/');

                // Check if displayname was requested (present in response with status 200)
                if (!isset($responseProps[200]) || !is_array($responseProps[200]) || !array_key_exists('{DAV:}displayname', $responseProps[200])) {
                    $xml[] = $xmlResponse;
                    continue;
                }

                $addressBookNode = $this->server->tree->getNodeForPath($addressBookPath);

                // Check node type - handle Subscription, SharedAddressBook, and regular AddressBook
                $isSubscription = ($addressBookNode instanceof Subscriptions\Subscription);
                $isSharedAddressBook = ($addressBookNode instanceof Sharing\SharedAddressBook);
                $isAddressBook = ($addressBookNode instanceof \Sabre\CardDAV\AddressBook);

                // Skip non-addressbook nodes (like the AddressBookHome itself)
                if (!$isSubscription && !$isAddressBook) {
                    $xml[] = $xmlResponse;
                    continue;
                }

                $existingDisplayName = $responseProps[200]['{DAV:}displayname'];
                if ($existingDisplayName === null) {
                    $existingDisplayName = '';
                }

                $pathParts = explode('/', trim($addressBookPath, '/'));

                // Skip if path doesn't have expected format
                if (count($pathParts) < 3) {
                    $xml[] = $xmlResponse;
                    continue;
                }

                list($type, $bookId, $addressbookType) = $pathParts;

                // Skip if currentUser is not set or invalid
                if (empty($this->currentUser)) {
                    $xml[] = $xmlResponse;
                    continue;
                }
                $currentUserParts = explode('/', $this->currentUser);
                if (count($currentUserParts) < 3) {
                    $xml[] = $xmlResponse;
                    continue;
                }
                $currentUserId = $currentUserParts[2];

                // Handle subscriptions: get owner from source addressbook
                if ($isSubscription) {
                    $props = $addressBookNode->getProperties(['{http://open-paas.org/contacts}source']);
                    if (!isset($props['{http://open-paas.org/contacts}source'])) {
                        $xml[] = $xmlResponse;
                        continue;
                    }
                    $sourceHref = $props['{http://open-paas.org/contacts}source']->getHref();
                    $sourcePath = $this->server->calculateUri($sourceHref);
                    $sourceNode = $this->server->tree->getNodeForPath($sourcePath);
                    $ownerPrincipalPath = $sourceNode->getOwner();

                    // Get displayname from source addressbook
                    $sourceProps = $sourceNode->getProperties(['{DAV:}displayname']);
                    $sourceDisplayName = isset($sourceProps['{DAV:}displayname']) ? $sourceProps['{DAV:}displayname'] : '';

                    $userPrincipal = $this->server->tree->getNodeForPath($ownerPrincipalPath);
                    $userDisplayName = $this->getPrincipalDisplayName($userPrincipal);

                    $modified = true;
                    if (!empty($sourceDisplayName)) {
                        $responseProps[200]['{DAV:}displayname'] = $sourceDisplayName . " - " . $userDisplayName;
                    } else {
                        $responseProps[200]['{DAV:}displayname'] = $userDisplayName;
                    }

                    $newResponse = new \Sabre\DAV\Xml\Element\Response($xmlResponse->getHref(), $responseProps);
                    $xml[] = $newResponse;
                    continue;
                }

                // Handle SharedAddressBook (delegations): always add owner name
                if ($isSharedAddressBook) {
                    $shareOwner = $addressBookNode->getShareOwner();
                    $userPrincipal = $this->server->tree->getNodeForPath($shareOwner);
                    $userDisplayName = $this->getPrincipalDisplayName($userPrincipal);

                    $modified = true;
                    if (!empty($existingDisplayName)) {
                        $responseProps[200]['{DAV:}displayname'] = $existingDisplayName . " - " . $userDisplayName;
                    } else {
                        $responseProps[200]['{DAV:}displayname'] = $userDisplayName;
                    }

                    $newResponse = new \Sabre\DAV\Xml\Element\Response($xmlResponse->getHref(), $responseProps);
                    $xml[] = $newResponse;
                    continue;
                }

                // Handle regular AddressBook (user's own or domain addressbooks)
                $shareOwner = $addressBookNode->getShareOwner();
                if (empty($shareOwner)) {
                    $xml[] = $xmlResponse;
                    continue;
                }
                $shareOwnerParts = explode('/', $shareOwner);
                if (count($shareOwnerParts) < 3) {
                    $xml[] = $xmlResponse;
                    continue;
                }
                $shareOwnerId = $shareOwnerParts[2];
                $shareOwnerType = $shareOwnerParts[1]; // 'users' or 'domains'

                $userPrincipal = $this->server->tree->getNodeForPath($shareOwner);
                $userDisplayName = $this->getPrincipalDisplayName($userPrincipal);

                // Detect shared address books: shareOwner differs from current user OR it's a domain addressbook
                $isShared = ($shareOwnerId !== $currentUserId) || ($shareOwnerType === 'domains');

                if (!$isShared) {
                    // User's own address books: rename only if no existing displayname
                    if (empty($existingDisplayName)) {
                        $modified = true;
                        if ($addressbookType === 'collected') {
                            $responseProps[200]['{DAV:}displayname'] = $userDisplayName . ' (collected)';
                        } else {
                            $responseProps[200]['{DAV:}displayname'] = $userDisplayName;
                        }
                        $newResponse = new \Sabre\DAV\Xml\Element\Response($xmlResponse->getHref(), $responseProps);
                        $xml[] = $newResponse;
                    } else {
                        $xml[] = $xmlResponse;
                    }
                } else {
                    // Shared/domain address books: add owner name or just show owner name if no displayname
                    $modified = true;

                    if (!empty($existingDisplayName)) {
                        $addressBookDisplayName = $existingDisplayName . " - " . $userDisplayName;
                    } else {
                        $addressBookDisplayName = $userDisplayName;
                    }

                    $responseProps[200]['{DAV:}displayname'] = $addressBookDisplayName;
                    $newResponse = new \Sabre\DAV\Xml\Element\Response($xmlResponse->getHref(), $responseProps);
                    $xml[] = $newResponse;
                }
            } catch (\Exception $e) {
                $xml[] = $xmlResponse;
            }
        }

        // Only rewrite the body if modifications were made
        if ($modified) {
            $data = $this->server->xml->write('{DAV:}multistatus', $xml, $this->server->getBaseUri());
            $response->setBody($data);
        }
    }

    /**
     * Get a display name for a principal, falling back to email if no display name.
     *
     * @param mixed $principal The principal node
     * @return string The display name or email
     */
    private function getPrincipalDisplayName($principal) {
        $displayName = $principal->getDisplayName();
        if (!empty($displayName)) {
            return $displayName;
        }
        $emailProps = $principal->getProperties(['{http://sabredav.org/ns}email-address']);
        if (!empty($emailProps) && !empty($emailProps['{http://sabredav.org/ns}email-address'])) {
            return $emailProps['{http://sabredav.org/ns}email-address'];
        }
        return '';
    }
}