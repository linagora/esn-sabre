<?php
namespace ESN\CardDAV;

use Sabre\DAV;

class Plugin extends \Sabre\CardDAV\Plugin {

    function initialize(DAV\Server $server) {
        parent::initialize($server);

        $server->on('method:GET', [$this, 'get'], 80);
        $server->on('method:ACL', [$this, 'acl'], 80);
        $server->on('method:PROPFIND', [$this, 'propfind'], 80);
        $server->on('method:POST', [$this, 'post'], 80);
    }

    function get($request, $response) {
        $acceptHeader = explode(', ', $request->getHeader('Accept'));
        if (!$this->acceptJson($acceptHeader)) {
            return true;
        }

        $path = $request->getPath();

        $node = $this->server->tree->getNodeForPath($path);
        $queryParams = $request->getQueryParameters();

        $code = null;
        $body = null;

        if ($node instanceof \Sabre\CardDAV\AddressBookHome) {
            if (isset($queryParams['public']) && $queryParams['public'] === 'true') {
                list($code, $body) = $this->getPublicAddressBooks($path, $node);
            } else {
                $options = new \stdClass();
                $options->subscribed = isset($queryParams['subscribed']) && $queryParams['subscribed'] === 'true';
                $options->personal = isset($queryParams['personal']) && $queryParams['personal'] === 'true';

                // If there is no params is given, get all address books
                if (!$options->subscribed && !$options->personal) {
                    $options->subscribed = true;
                    $options->personal = true;
                }

                list($code, $body) = $this->getAddressBooks($path, $node, $options);
            }
        }

        return $this->send($code, $body);
    }

    function propfind($request, $response) {
        $acceptHeader = explode(', ', $request->getHeader('Accept'));
        if (!$this->acceptJson($acceptHeader)) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);
        $code = null;
        $body = null;

        if ($node instanceof \ESN\CardDAV\Subscriptions\Subscription) {
            $jsonData = json_decode($request->getBodyAsString(), true);

            if ($node->getProperties($jsonData['properties'])) {
                $bookProps = $node->getProperties($jsonData['properties']);
                if (in_array('acl', $jsonData['properties'])) {
                    $bookProps['acl'] = $node->getACL();
                }

                if (isset($bookProps['{http://open-paas.org/contacts}source'])) {
                    $sourcePath = $bookProps['{http://open-paas.org/contacts}source']->getHref();

                    if (!$this->server->tree->nodeExists($sourcePath)) {
                        return null;
                    }

                    $sourceNode = $this->server->tree->getNodeForPath($sourcePath);
                    $bookProps['{http://open-paas.org/contacts}source'] = $this->getAddressBookDetail($sourcePath, $sourceNode, true);
                }

                $this->server->httpResponse->setHeader('Content-Type','application/json; charset=utf-8');
                $this->server->httpResponse->setBody(json_encode($bookProps));
            }


            $this->server->httpResponse->setStatus(200);
            return false;
        }

        return true;
    }

    function acl($request, $response) {
        $acceptHeader = explode(', ', $request->getHeader('Accept'));
        if (!$this->acceptJson($acceptHeader)) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        if ($node instanceof \ESN\CardDAV\AddressBook) {
            $acl = json_decode($request->getBodyAsString());

            if (!isset($acl)) {
                throw new DAV\Exception\BadRequest('JSON body expected in ACL request');
            }

            $supportedPublicRights = $node->getSupportedPublicRights();

            foreach ($acl as $ace) {
                if (!isset($ace->principal)) {
                    throw new DAV\Exception\BadRequest('Authenticated ACE\'s principal is required');
                }

                if (!isset($ace->privilege) || strlen($ace->privilege) === 0) {
                    throw new DAV\Exception\BadRequest('Authenticated ACE\'s privilege is required');
                }

                if (!in_array($ace->privilege, $supportedPublicRights)) {
                    throw new \Sabre\DAVACL\Exception\NotSupportedPrivilege('The privilege you specified (' . $ace->privilege . ') is not recognized by this server');
                }
            }

            $node->setACL($acl);
            $this->send(200, $node->getACL());

            return false;
        }

        return true;
    }

    function post($request, $response) {
        $acceptHeader = explode(', ', $request->getHeader('Accept'));
        if (!$this->acceptJson($acceptHeader)) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);
        $code = null;
        $body = null;

        if ($node instanceof \Sabre\CardDAV\AddressBookHome) {
            $jsonData = json_decode($request->getBodyAsString());
            $issetdef = $this->propertyOrDefault($jsonData);

            if ($issetdef('openpaas:source')) {
                list($code, $body) = $this->createSubscriptionAddressBook($path, $node, $jsonData);
            } else {
                list($code, $body) = $this->createAddressBook($node, $jsonData);
            }
        }

        return $this->send($code, $body);
    }

    function getPublicAddressBooks($nodePath, $node) {
        $addressBooks = $node->getChildren();
        $baseUri = $this->server->getBaseUri();

        $items = [];
        foreach ($addressBooks as $addressBook) {
            if ($addressBook instanceof \Sabre\CardDAV\AddressBook && $addressBook->isPublic()) {
                $items[] = $this->getAddressBookDetail($nodePath . '/' . $addressBook->getName(), $addressBook);
            }
        }

        $requestPath = $baseUri . $nodePath . '.json';
        $result = [
            '_links' => [
                'self' => [ 'href' => $requestPath ]
            ],
            '_embedded' => [ 'dav:addressbook' => $items ]
        ];

        return [200, $result];
    }

    function getAddressBooks($nodePath, $node, $options) {
        $addressBooks = $node->getChildren();
        $baseUri = $this->server->getBaseUri();

        $items = [];
        foreach ($addressBooks as $addressBook) {
            if ($options->personal && $addressBook instanceof \Sabre\CardDAV\AddressBook) {
                $items[] = $this->getAddressBookDetail($nodePath . '/' . $addressBook->getName(), $addressBook);
            } else if ($options->subscribed && $addressBook instanceof \ESN\CardDAV\Subscriptions\Subscription) {
                $items[] = $this->addressBookSubscriptionToJson($nodePath . '/' . $addressBook->getName(), $addressBook);
            }
        }

        $requestPath = $baseUri . $nodePath . '.json';
        $result = [
            '_links' => [
                'self' => [ 'href' => $requestPath ]
            ],
            '_embedded' => [ 'dav:addressbook' => $items ]
        ];

        return [200, $result];
    }

    function addressBookSubscriptionToJson($nodePath, $addressBook) {
        $baseUri = $this->server->getBaseUri();
        $bookProps = $addressBook->getProperties([
          '{http://open-paas.org/contacts}source',
          '{DAV:}displayname',
          '{urn:ietf:params:xml:ns:carddav}addressbook-description',
          '{DAV:}acl'
        ]);

        $subscription = [
            '_links' => [
                'self' => [ 'href' => $baseUri . $nodePath . '.json' ],
            ],
            'dav:name' => $bookProps['{DAV:}displayname'],
            'carddav:description' => $bookProps['{urn:ietf:params:xml:ns:carddav}addressbook-description'],
            'dav:acl' => $bookProps['{DAV:}acl'],
            'acl' => $addressBook->getACL()
        ];

        if (isset($bookProps['{http://open-paas.org/contacts}source'])) {
            $sourcePath = $bookProps['{http://open-paas.org/contacts}source']->getHref();

            if (!$this->server->tree->nodeExists($sourcePath)) {
                return null;
            }

            $sourceNode = $this->server->tree->getNodeForPath($sourcePath);
            $subscription['openpaas:source'] = $this->getAddressBookDetail($sourcePath, $sourceNode, true);
        }

        return $subscription;
    }

    function getAddressBookDetail($nodePath, \Sabre\CardDAV\AddressBook $addressBook) {
        $baseUri = $this->server->getBaseUri();
        $bookProps = $addressBook->getProperties(['{DAV:}displayname', '{DAV:}acl', '{http://open-paas.org/contacts}type', '{urn:ietf:params:xml:ns:carddav}addressbook-description']);

        return [
            '_links' => [
                'self' => [ 'href' => $baseUri . $nodePath . '.json' ],
            ],
            'dav:name' => $bookProps['{DAV:}displayname'],
            'carddav:description' => $bookProps['{urn:ietf:params:xml:ns:carddav}addressbook-description'],
            'dav:acl' => $bookProps['{DAV:}acl'],
            'type' => $bookProps['{http://open-paas.org/contacts}type'],
            'acl' => $addressBook->getACL()
        ];
    }

    function send($code, $body, $setContentType = true) {
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

    function createAddressBook($node, $jsonData) {
        $issetdef = $this->propertyOrDefault($jsonData);

        if (!isset($jsonData->id) || !$jsonData->id) {
            return [400, null];
        }

        $rt = ['{DAV:}collection', '{urn:ietf:params:xml:ns:carddav}addressbook'];
        $props = [
            '{DAV:}displayname' => $issetdef('dav:name'),
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => $issetdef('carddav:description'),
            '{DAV:}acl' => $issetdef('dav:acl'),
            '{http://open-paas.org/contacts}type' => $issetdef('type')
        ];

        $node->createExtendedCollection($jsonData->id, new \Sabre\DAV\MkCol($rt, $props));

        return [201, null];
    }

    function createSubscriptionAddressBook($nodePath, $node, $jsonData) {
        $issetdef = $this->propertyOrDefault($jsonData);

        if (!isset($jsonData->id) || !$jsonData->id) {
            return [400, null];
        }

        $sourcePath = $this->qualifySourcePath(
            $this->server->calculateUri($issetdef('openpaas:source')->_links->self->href)
        );

        $rt = ['{DAV:}collection', '{http://open-paas.org/contacts}subscribed'];
        $props = [
            '{DAV:}displayname' => $issetdef('dav:name'),
            '{DAV:}acl' => $issetdef('dav:acl'),
            '{http://open-paas.org/contacts}source' => new \Sabre\DAV\Xml\Property\Href($sourcePath, false)
        ];

        $node->createExtendedCollection($jsonData->id, new \Sabre\DAV\MkCol($rt, $props));

        return [201, null];
    }

    private function qualifySourcePath($sourcePath) {
        if (substr($sourcePath, -5) == '.json') {
            return substr($sourcePath, 0, -5);
        }

        return $sourcePath;
    }

    function acceptJson($acceptHeader) {
        return in_array('application/vcard+json', $acceptHeader) ||
               in_array('application/json', $acceptHeader);
    }

    private function propertyOrDefault($jsonData) {
        return function($key, $default = null) use ($jsonData) {
            return isset($jsonData->{$key}) ? $jsonData->{$key} : $default;
        };
    }
}
