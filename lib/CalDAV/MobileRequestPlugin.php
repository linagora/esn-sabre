<?php
namespace ESN\CalDAV;

use \Sabre\VObject;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;

#[\AllowDynamicProperties]
class MobileRequestPlugin extends \ESN\JSON\BasePlugin {

    /**
     * This is the official CalDAV namespace
     */
    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

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
        return 'mobile-request';
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
            'description' => 'support of some mobile dav client for CalDAV',
            'link'        => 'http://sabre.io/dav/caldav/',
        ];
    }

    function afterMethodPropfind($request, $response) {
        if($this->acceptJson()) {
            return true;
        }

        $xml = [];
        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        if($node instanceof \Sabre\CalDAV\CalendarHome) {
            try {
                $propFindXml = $this->server->xml->expect('{DAV:}multistatus', $response->getBodyAsString());
            } catch (\Exception $e) {
                return true;
            }

            $xmlResponses = $propFindXml->getResponses();
            $modified = false;

            foreach($xmlResponses as $index => $xmlResponse) {
                $responseProps = $xmlResponse->getResponseProperties();

                try {
                    $calendarPath = trim($xmlResponse->getHref(), '/');
                    $calendarNode = $this->server->tree->getNodeForPath($calendarPath);

                    // Check if displayname was requested (present in response with status 200)
                    if (!isset($responseProps[200]) || !array_key_exists('{DAV:}displayname', $responseProps[200])) {
                        $xml[] = ['{DAV:}response' => $xmlResponse];
                        continue;
                    }

                    $existingDisplayName = $responseProps[200]['{DAV:}displayname'];
                    if ($existingDisplayName === null) {
                        $existingDisplayName = '';
                    }

                    // Detect shared/subscribed calendars by node type, not by resourcetype in response
                    $isSharedOrSubscribed = ($calendarNode instanceof \Sabre\CalDAV\SharedCalendar) ||
                                            ($calendarNode instanceof \Sabre\CalDAV\Subscriptions\Subscription);

                    if ($isSharedOrSubscribed) {
                        // Shared/subscribed calendars: get owner and displayname from source calendar
                        $sourceDisplayName = '';

                        if ($calendarNode instanceof \Sabre\CalDAV\Subscriptions\Subscription) {
                            // For subscriptions, get owner and displayname from source calendar
                            $sourceHref = $calendarNode->getProperties(['{http://calendarserver.org/ns/}source'])['{http://calendarserver.org/ns/}source']->getHref();
                            $sourceNode = $this->server->tree->getNodeForPath($this->server->calculateUri($sourceHref));
                            $ownerPrincipalPath = $sourceNode->getOwner();
                            // Get displayname from source calendar
                            $sourceProps = $sourceNode->getProperties(['{DAV:}displayname']);
                            $sourceDisplayName = isset($sourceProps['{DAV:}displayname']) ? $sourceProps['{DAV:}displayname'] : '';
                        } else {
                            // For shared calendars (delegations), getOwner() returns the original owner
                            $ownerPrincipalPath = $calendarNode->getOwner();
                            // Use the displayname from the shared calendar itself
                            $sourceDisplayName = $existingDisplayName;
                        }

                        // Treat #default as empty
                        if ($sourceDisplayName === '#default') {
                            $sourceDisplayName = '';
                        }

                        $userPrincipal = $this->server->tree->getNodeForPath($ownerPrincipalPath);
                        $userDisplayName = $userPrincipal->getDisplayName() ? $userPrincipal->getDisplayName() : current($userPrincipal->getProperties(['{http://sabredav.org/ns}email-address']));

                        $modified = true;
                        if (!empty($sourceDisplayName)) {
                            $responseProps[200]['{DAV:}displayname'] = $sourceDisplayName . " - " . $userDisplayName;
                        } else {
                            $responseProps[200]['{DAV:}displayname'] = $userDisplayName;
                        }

                        $newResponse = new \Sabre\DAV\Xml\Element\Response($xmlResponse->getHref(), $responseProps);
                        $xml[] = ['{DAV:}response' => $newResponse];
                    } else if ($calendarNode instanceof \Sabre\CalDAV\Calendar) {
                        // User's own calendars: rename #default or empty to user's display name
                        if ($existingDisplayName === '' || $existingDisplayName === '#default') {
                            $modified = true;
                            $userPrincipal = $this->server->tree->getNodeForPath($calendarNode->getOwner());
                            $userDisplayName = $userPrincipal->getDisplayName() ? $userPrincipal->getDisplayName() : current($userPrincipal->getProperties(['{http://sabredav.org/ns}email-address']));
                            $responseProps[200]['{DAV:}displayname'] = $userDisplayName;
                            $newResponse = new \Sabre\DAV\Xml\Element\Response($xmlResponse->getHref(), $responseProps);
                            $xml[] = ['{DAV:}response' => $newResponse];
                        } else {
                            $xml[] = ['{DAV:}response' => $xmlResponse];
                        }
                    } else {
                        $xml[] = ['{DAV:}response' => $xmlResponse];
                    }
                } catch (\Exception $e) {
                    $xml[] = ['{DAV:}response' => $xmlResponse];
                }
            }

            // Always rewrite the body to ensure consistency
            $data = $this->server->xml->write('{DAV:}multistatus', $xml);
            $response->setBody($data);
        }
    }
}