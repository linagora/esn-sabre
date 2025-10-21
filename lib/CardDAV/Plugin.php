<?php
namespace ESN\CardDAV;

use Sabre\DAV;
use \Sabre\VObject;
use \Sabre\DAV\Exception\Forbidden;
use \ESN\Utils\Utils as Utils;

class Plugin extends \ESN\JSON\BasePlugin {

    function initialize(\Sabre\DAV\Server $server) {
        parent::initialize($server);

        $server->on('beforeUnbind', [$this, 'beforeUnbind']);
        $server->on('beforeWriteContent', [$this, 'beforeWriteContent']);
        $server->on('beforeCreateFile', [$this, 'beforeCreateFile']);
        $server->on('method:GET', [$this, 'httpGet'], 80);
        $server->on('method:PROPFIND', [$this, 'httpPropfind'], 80);
        $server->on('method:PROPPATCH', [$this, 'httpProppatch'], 80);
        $server->on('method:POST', [$this, 'httpPost'], 80);
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
        return 'carddav-json';
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
            'description' => 'Adds JSON support for CardDAV',
            'link'        => 'http://sabre.io/dav/carddav/',
        ];
    }

    protected function getSupportedHeaders() {
        return array('application/json', 'application/vcard+json');
    }

    function beforeUnbind($path) {
        $protectedAddressBook = array(
            \ESN\CardDAV\Backend\Esn::CONTACTS_URI,
            \ESN\CardDAV\Backend\Esn::COLLECTED_URI
        );

        $node = $this->server->tree->getNodeForPath($path);

        if ($node instanceof \Sabre\CardDAV\AddressBook) {
            if (in_array($node->getName(), $protectedAddressBook)) {
                throw new \Sabre\DAV\Exception\Forbidden('Forbidden: You can not delete '.$node->getName().' address book');
            }
        }

        // Check domain-members access for card deletion
        if ($node instanceof \Sabre\CardDAV\Card) {
            $this->checkDomainMembersAccess($path);
        }
    }

    function beforeWriteContent($path, DAV\IFile $node, &$data, &$modified) {
        $this->checkDomainMembersAccess($path);
    }

    function beforeCreateFile($path, &$data, DAV\ICollection $parentNode, &$modified) {
        $this->checkDomainMembersAccess($path);
    }

    private function checkDomainMembersAccess($path) {
        // Check if the path is within domain-members addressbook
        $pathParts = explode('/', trim($path, '/'));

        // Path format: addressbooks/{domainId}/domain-members/{card.vcf}
        if (
            count($pathParts) >= 3 &&
            $pathParts[0] === 'addressbooks' &&
            $pathParts[2] === \ESN\CardDAV\Backend\Esn::DOMAIN_MEMBERS_URI
        ) {
            // Only technical users can modify domain-members addressbook
            $authPlugin = $this->server->getPlugin('auth');
            if (!is_null($authPlugin)) {
                $currentPrincipal = $authPlugin->getCurrentPrincipal();
                $parts = explode('/', $currentPrincipal);
                $type = isset($parts[1]) ? $parts[1] : null;

                if ($type !== 'technicalUser') {
                    throw new Forbidden('Only technical users can modify domain-members addressbook');
                }
            } else {
                throw new Forbidden('Authentication plugin not available');
            }
        }
    }

    function httpGet($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();

        $node = $this->server->tree->getNodeForPath($path);
        $queryParams = $request->getQueryParameters();

        $code = null;
        $body = null;

        if ($node instanceof \ESN\CardDAV\AddressBookRoot) {
            $authPlugin = $this->server->getPlugin('auth');
            if (!is_null($authPlugin)) {
                $currentPrincipal = $authPlugin->getCurrentPrincipal();
                list(, $type) = explode('/', $currentPrincipal);

                if ($type !== 'technicalUser') {
                    throw new Forbidden();
                }
            }

            $children = [];

            foreach($node->getChildren() as $child) {
                $children[] = $child->getName();
            }

            list($code, $body) = [200, $children];
        } else if ($node instanceof \Sabre\CardDAV\AddressBookHome) {
            $options = new \stdClass();
            $options->public = Utils::getArrayValue($queryParams, 'public') === 'true';
            $options->subscribed = Utils::getArrayValue($queryParams, 'subscribed') === 'true';
            $options->personal = Utils::getArrayValue($queryParams, 'personal') === 'true';
            $options->contactsCount = Utils::getArrayValue($queryParams, 'contactsCount') === 'true';

            // TODO: these should be in sharing plugin but we still do not find a effective way to do it
            $options->shared = Utils::getArrayValue($queryParams, 'shared') === 'true';
            $options->inviteStatus = Utils::getArrayValue($queryParams, 'inviteStatus', null);
            $options->shareOwner = Utils::getArrayValue($queryParams, 'shareOwner', null);

            if ($options->inviteStatus !== null) {
                $options->inviteStatus = (int)$options->inviteStatus;
            }

            if ($options->shareOwner !== null) {
                $options->shareOwner = 'principals/users/' . $options->shareOwner;
            }

            list($code, $body) = $this->getAddressBooks($path, $node, $options);
        } else if ($node instanceof \Sabre\CardDAV\AddressBook) {
            list($code, $body) = $this->getContacts($request, $response, $path, $node);
        }

        return $this->send($code, $body);
    }

    function httpPropfind($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);
        $code = null;
        $body = null;

        if ($node instanceof AddressBook) {
            $jsonData = json_decode($request->getBodyAsString(), true);
            $properties = $node->getProperties($jsonData['properties']);

            if (isset($properties['acl'])) {
                $acl = [];

                $authPlugin = $this->server->getPlugin('auth');
                $currentPrincipal = $authPlugin->getCurrentPrincipal();

                // only return acl which contains principal of requester and authenticated users
                foreach($properties['acl'] as $ace) {
                    if (in_array($ace['principal'], [$currentPrincipal, '{DAV:}authenticated'])) {
                        $acl[] = $ace;
                    }
                }

                $properties['acl'] = $acl;
            }

            $body = $properties;

            $code = 200;
        }

        return $this->send($code, $body);
    }

    function httpProppatch($request, $response) {
        if (!$this->acceptJson()) {
            return true;
        }

        $path = $request->getPath();
        $node = $this->server->tree->getNodeForPath($path);

        $code = null;
        $body = null;

        if ($node instanceof \Sabre\CardDAV\AddressBook) {
            list($code, $body) = $this->changeAddressBookProperties(
                $path,
                $node,
                json_decode($request->getBodyAsString())
            );
        }

        return $this->send($code, $body);
    }

    function httpPost($request, $response) {
        if (!$this->acceptJson()) {
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
                list($code, $body) = $this->createAddressBook($path, $jsonData);
            }
        }

        return $this->send($code, $body);
    }

    private function getAddressBooks($nodePath, $node, $options) {
        $addressBooks = $node->getChildren();
        $baseUri = $this->server->getBaseUri();

        $items = [];
        foreach ($addressBooks as $addressBook) {
            $shouldInclude = false;

            if ($addressBook instanceof Group\GroupAddressBook) {
                if ($addressBook->isDisabled()) continue;

                if ($options->personal) {
                    $shouldInclude = true;
                }

                if ($options->public) {
                    $shouldInclude = $addressBook->isPublic();
                }
            } else if ($addressBook instanceof AddressBook) {
                if ($options->personal) {
                    $shouldInclude = true;
                }

                if ($options->public) {
                    $shouldInclude = $addressBook->isPublic();
                }
            } else if ($addressBook instanceof Subscriptions\Subscription) {
                $shouldInclude = $options->subscribed;
            } else if ($addressBook instanceof Sharing\SharedAddressBook) {
                $shouldInclude = $options->shared;

                if ($options->inviteStatus !== null) {
                    $shouldInclude =  $shouldInclude && $addressBook->getInviteStatus() === $options->inviteStatus;
                }

                if ($options->shareOwner !== null) {
                    $shouldInclude =  $shouldInclude && $addressBook->getShareOwner() === $options->shareOwner;
                }
            }

            if ($shouldInclude) {
                $items[] = $this->getAddressBookDetail($nodePath . '/' . $addressBook->getName(), $addressBook, $options);
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

    function getAddressBookDetail($nodePath, \Sabre\DAV\Collection $addressBook, $options = null) {
        $baseUri = $this->server->getBaseUri();
        $properties = [
            '{urn:ietf:params:xml:ns:carddav}addressbook-description',
            '{DAV:}displayname',
            '{DAV:}acl',
            '{DAV:}share-access',
            '{DAV:}group',
            '{http://open-paas.org/contacts}subscription-type',
            '{http://open-paas.org/contacts}source',
            '{http://open-paas.org/contacts}type',
            '{http://open-paas.org/contacts}state',
            'acl'
        ];

        if (isset($options->contactsCount) && $options->contactsCount) {
            $properties[] = '{http://open-paas.org/contacts}numberOfContacts';
        }

        $bookProps = $addressBook->getProperties($properties);

        $addressBookHref = $baseUri . $nodePath . '.json';

        // If there is a group address book, we need to ensure address book href contains group ID
        if (!Utils::isUserPrincipal($addressBook->getOwner())) {
            $ownerPrincipalExploded = explode('/', $addressBook->getOwner());
            $nodePathExploded = explode('/', $nodePath);

            $addressBookHref = $baseUri . $nodePathExploded[0] . '/' . $ownerPrincipalExploded[2] . '/' . $nodePathExploded[2] . '.json';
        }

        $output = [
            '_links' => [
                'self' => [ 'href' => $addressBookHref ],
            ],
            'dav:name' => Utils::getArrayValue($bookProps, '{DAV:}displayname'),
            'carddav:description' => Utils::getArrayValue($bookProps, '{urn:ietf:params:xml:ns:carddav}addressbook-description'),
            'dav:acl' => Utils::getArrayValue($bookProps, '{DAV:}acl'),
            'dav:share-access' => Utils::getArrayValue($bookProps, '{DAV:}share-access'),
            'openpaas:subscription-type' => Utils::getArrayValue($bookProps, '{http://open-paas.org/contacts}subscription-type'),
            'type' => Utils::getArrayValue($bookProps, '{http://open-paas.org/contacts}type'),
            'state' => Utils::getArrayValue($bookProps, '{http://open-paas.org/contacts}state'),
            'numberOfContacts' => Utils::getArrayValue($bookProps, '{http://open-paas.org/contacts}numberOfContacts'),
            'acl' => Utils::getArrayValue($bookProps, 'acl'),
            'dav:group' => Utils::getArrayValue($bookProps, '{DAV:}group')
        ];

        if (isset($bookProps['{http://open-paas.org/contacts}source'])) {
            $sourcePath = $bookProps['{http://open-paas.org/contacts}source']->getHref();
            $output['openpaas:source'] = $baseUri . $sourcePath . '.json';
        }

        return $output;
    }

    function createAddressBook($homePath, $jsonData) {
        if (!isset($jsonData->id) || !$jsonData->id) {
            return [400, null];
        }

        $issetdef = $this->propertyOrDefault($jsonData);

        $rt = ['{DAV:}collection', '{urn:ietf:params:xml:ns:carddav}addressbook'];
        $props = [
            '{DAV:}displayname' => $issetdef('dav:name'),
            '{urn:ietf:params:xml:ns:carddav}addressbook-description' => $issetdef('carddav:description'),
            '{DAV:}acl' => $issetdef('dav:acl'),
            '{http://open-paas.org/contacts}type' => $issetdef('type'),
            '{http://open-paas.org/contacts}state' => $issetdef('state')
        ];

        $this->server->createCollection($homePath . '/' . $jsonData->id, new \Sabre\DAV\MkCol($rt, $props));

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

    private function changeAddressBookProperties($nodePath, $node, $jsonData) {
        $protectedAddressBook = array(
            \ESN\CardDAV\Backend\Esn::CONTACTS_URI,
            \ESN\CardDAV\Backend\Esn::COLLECTED_URI
        );

        if (in_array($node->getName(), $protectedAddressBook)) {
            return [403, [
                'status' => 403,
                'message' => 'Forbidden: You can not update '.$node->getName().' address book'
            ]];
        }

        $propnameMap = [
            'dav:name' => '{DAV:}displayname',
            'carddav:description' => '{urn:ietf:params:xml:ns:carddav}addressbook-description'
        ];

        // state concept only apply for group address books
        if ($node instanceof \ESN\CardDAV\Group\GroupAddressBook) {
            $propnameMap['state'] = '{http://open-paas.org/contacts}state';
        }

        $davProps = [];
        foreach ($jsonData as $jsonProp => $value) {
            if (isset($propnameMap[$jsonProp])) {
                $davProps[$propnameMap[$jsonProp]] = $value;
            }
        }

        $result = $this->server->updateProperties($nodePath, $davProps);

        $returncode = 204;
        foreach ($result as $prop => $code) {
            if ((int)$code > 299) {
                $returncode = (int)$code;
                break;
            }
        }

        return [$returncode, null];
    }

    private function getContacts($request, $response, $nodePath, $node) {
        $queryParams = $request->getQueryParameters();
        $offset = isset($queryParams['offset']) ? $queryParams['offset'] : 0;
        $limit = isset($queryParams['limit']) ? $queryParams['limit'] : 0;
        $search = isset($queryParams['search']) ? $queryParams['search'] : null;
        $sort = isset($queryParams['sort']) ? $queryParams['sort'] : null;
        $modifiedBefore = isset($queryParams['modifiedBefore']) ? (int)$queryParams['modifiedBefore'] : 0;

        $filters = null;
        if ($modifiedBefore > 0) {
            $filters = [
                'modifiedBefore' => $modifiedBefore
            ];
        }
        if (!($search === null)) {
            $filters = [
                'search' => $search
            ];
        }

        $cards = $node->getChildren($offset, $limit, $sort, $filters);
        $count = $node->getChildCount();

        $items = [];
        $baseUri = $this->server->getBaseUri();
        foreach ($cards as $card) {
            $vobj = VObject\Reader::read($card->get());
            $cardItem = [
                '_links' => [
                  'self' => [ 'href' =>  $baseUri . $nodePath . '/' . $card->getName() ]
                ],
                'etag' => $card->getETag(),
                'data' => $vobj->jsonSerialize()
            ];
            $items[] = $cardItem;
        }

        $requestPath = $baseUri . $request->getPath() . '.json';

        $result = [
            '_links' => [
                'self' => [ 'href' => $requestPath ]
            ],
            'dav:syncToken' => $node->getSyncToken(),
            '_embedded' => [ 'dav:item' => $items ]
        ];

        if ($limit > 0 && ($offset + $limit < $count)) {
            $queryParams['offset'] = $offset + $limit;
            $href = $requestPath . '?' . http_build_query($queryParams);
            $result['_links']['next'] = [ 'href' => $href ];
        }

        return [200, $result];
    }

    private function qualifySourcePath($sourcePath) {
        if (substr($sourcePath, -5) == '.json') {
            return substr($sourcePath, 0, -5);
        }

        return $sourcePath;
    }

    private function propertyOrDefault($jsonData) {
        return function($key, $default = null) use ($jsonData) {
            return isset($jsonData->{$key}) ? $jsonData->{$key} : $default;
        };
    }
}
