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
                $resourceType = isset($responseProps[200]['{DAV:}resourcetype']) ? $responseProps[200]['{DAV:}resourcetype'] : null;

                if (isset($resourceType) && ($resourceType->is("{http://calendarserver.org/ns/}shared") || $resourceType->is("{http://calendarserver.org/ns/}subscribed"))) {
                    // Only modify displayname if it was requested in the PROPFIND
                    if (!array_key_exists('{DAV:}displayname', $responseProps[200] ?? [])) {
                        $xml[] = ['{DAV:}response' => $xmlResponse];
                        continue;
                    }

                    // Shared/subscribed calendars: add owner name
                    $calendarPath = $xmlResponse->getHref();
                    $calendarNode = $this->server->tree->getNodeForPath($calendarPath);

                    if (method_exists($calendarNode, 'getOwner')) {
                        $modified = true;
                        $userPrincipal = $this->server->tree->getNodeForPath($calendarNode->getOwner());
                        $userDisplayName = $userPrincipal->getDisplayName() ? $userPrincipal->getDisplayName() : current($userPrincipal->getProperties(['{http://sabredav.org/ns}email-address']));

                        $responseProps[200]['{DAV:}displayname'] = isset($responseProps[200]['{DAV:}displayname']) ?
                                        $responseProps[200]['{DAV:}displayname'] . " - " . $userDisplayName :
                                        "Agenda - " . $userDisplayName;

                        $newResponse = new \Sabre\DAV\Xml\Element\Response($xmlResponse->getHref(), $responseProps);
                        $xml[] = ['{DAV:}response' => $newResponse];
                    } else {
                        $xml[] = ['{DAV:}response' => $xmlResponse];
                    }
                } else {
                    // User's own calendars: rename #default to "My agenda"
                    if (isset($responseProps[200]['{DAV:}displayname']) && $responseProps[200]['{DAV:}displayname'] === '#default') {
                        $modified = true;
                        $responseProps[200]['{DAV:}displayname'] = "My agenda";
                        $newResponse = new \Sabre\DAV\Xml\Element\Response($xmlResponse->getHref(), $responseProps);
                        $xml[] = ['{DAV:}response' => $newResponse];
                    } else {
                        $xml[] = ['{DAV:}response' => $xmlResponse];
                    }
                }
            }

            if ($modified) {
                $data = $this->server->xml->write('{DAV:}multistatus', $xml);
                $response->setBody($data);
            }
        }
    }
}