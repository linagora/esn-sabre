<?php

namespace ESN\CardDAV\Backend;

use Sabre\Event\EventEmitter;
use ESN\DAV\Sharing\Plugin as SPlugin;

class Mongo extends \Sabre\CardDAV\Backend\AbstractBackend implements
    \Sabre\CardDAV\Backend\SyncSupport,
    SubscriptionSupport,
    SharingSupport {

    protected $eventEmitter;

    public $addressBooksTableName = 'addressbooks';
    public $sharedAddressBooksTableName = 'sharedaddressbooks';
    public $cardsTableName = 'cards';
    public $addressBookChangesTableName = 'addressbookchanges';
    public $addressBookSubscriptionsTableName = 'addressbooksubscriptions';
    public $CharAPI;

    public $subscriptionPropertyMap = [
        '{DAV:}displayname' => 'displayname',
        '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'description'
    ];
    public $sharedAddressBookPropertyMap = [
        '{DAV:}displayname' => 'displayname',
        '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'description'
    ];

    public $PUBLIC_RIGHTS = [
        '{DAV:}all',
        '{DAV:}read',
        '{DAV:}write'
    ];

    const MINIMAL_ADDRESSBOOK_FIELDS = ['_id', 'principaluri', 'uri'];

    function __construct(\MongoDB $db) {
        $this->db = $db;
        $this->eventEmitter = new EventEmitter();
        $this->CharAPI = new \ESN\Utils\CharAPI();
        $this->ensureIndex();
    }

    function getEventEmitter() {
        return $this->eventEmitter;
    }

    function getAddressBooksForUser($principalUri) {
        $fields = ['_id', 'uri', 'displayname', 'principaluri', 'privilege', 'type', 'description', 'synctoken'];
        $query = [ 'principaluri' => $principalUri ];
        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $addressBooks = [];
        foreach ($collection->find($query, $fields) as $row) {
            $addressBooks[] = [
                'id'  => (string)$row['_id'],
                'uri' => $row['uri'],
                'principaluri' => $row['principaluri'],
                '{DAV:}displayname' => $this->getValue($row, 'displayname', ''),
                '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => $this->getValue($row, 'description', ''),
                '{DAV:}acl' => $this->getValue($row, 'privilege', ['dav:read', 'dav:write']),
                '{http://open-paas.org/contacts}type' => $this->getValue($row, 'type', ''),
                '{http://calendarserver.org/ns/}getctag' => $row['synctoken'],
                '{http://sabredav.org/ns}sync-token' => $this->getValue($row, 'synctoken', '0'),
            ];
        }
        return $addressBooks;
    }

    function addressBookExists($principalUri, $uri) {
        $fields = ['_id', 'uri'];
        $query = [ 'principaluri' => $principalUri, 'uri' => $uri];
        $collection = $this->db->selectCollection($this->addressBooksTableName);

        $doc = $collection->findOne($query, $fields);

        return !empty($doc);
    }

    function updateAddressBook($addressBookId, \Sabre\DAV\PropPatch $propPatch) {
        $supportedProperties = [
            '{DAV:}displayname',
            '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description',
            '{DAV:}acl'
        ];

        $propPatch->handle($supportedProperties, function($mutations) use ($addressBookId) {

            $updates = [];
            foreach($mutations as $property=>$newValue) {

                switch($property) {
                    case '{DAV:}displayname' :
                        $updates['displayname'] = $newValue;
                        break;
                    case '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' :
                        $updates['description'] = $newValue;
                        break;
                    case '{DAV:}acl' :
                        $updates['privilege'] = $newValue;
                        break;
                }
            }

            $collection = $this->db->selectCollection($this->addressBooksTableName);
            $updatedAddressBook = $collection->findAndModify(
                [ '_id'  => new \MongoId($addressBookId) ],
                [ '$set' => $updates ],
                [ 'principaluri' => 1, 'uri' => 1],
                [ 'new'  => true ]
            );
            $this->eventEmitter->emit('sabre:addressBookUpdated', [
                [
                    'path' => $this->buildAddressBookPath($updatedAddressBook['principaluri'], $updatedAddressBook['uri'])
                ]
            ]);
            $this->addChange($addressBookId, "", 2);

            return true;
        });
    }

    function createAddressBook($principalUri, $uri, array $properties) {
        $values = [
            'synctoken' => 1,
            'displayname' => '',
            'description' => '',
            'privilege' => ['dav:read', 'dav:write'],
            'principaluri' => $principalUri,
            'type' => '',
            'uri' => $uri,
        ];

        foreach($properties as $property=>$newValue) {

            switch($property) {
                case '{DAV:}displayname' :
                    $values['displayname'] = $newValue;
                    break;
                case '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' :
                    $values['description'] = $newValue;
                    break;
                case '{DAV:}acl' :
                    $values['privilege'] = $newValue;
                    break;
                case '{http://open-paas.org/contacts}type' :
                    $values['type'] = $newValue;
                    break;
                default :
                    throw new \Sabre\DAV\Exception\BadRequest('Unknown property: ' . $property);
            }

        }

        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $modified = $collection->findAndModify(
            array('principaluri' => $principalUri, 'uri' => $uri),
            $values,
            array('_id'),
            array('upsert' => true, 'new' => true)
        );

        $this->eventEmitter->emit('sabre:addressBookCreated', [
            [
                'principaluri' => $principalUri,
                'path' => $this->buildAddressBookPath($principalUri, $uri)
            ]
        ]);

        return (string) $modified['_id'];
    }

    function deleteAddressBook($addressBookId) {
        $mongoId = new \MongoId($addressBookId);

        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $query = [ '_id' => $mongoId ];
        $addressBook = $collection->findOne($query);
        $collection->remove([ '_id' => $mongoId ]);

        $this->eventEmitter->emit('sabre:addressBookDeleted', [
            [
                'addressbookid' => $addressBook['_id'],
                'principaluri' => $addressBook['principaluri'],
                'path' => $this->buildAddressBookPath($addressBook['principaluri'], $addressBook['uri'])
            ]
        ]);

        $collection = $this->db->selectCollection($this->cardsTableName);
        $collection->remove([ 'addressbookid' => $mongoId ]);

        $this->deleteSubscriptions($addressBook['principaluri'], $addressBook['uri']);

        $collection = $this->db->selectCollection($this->addressBookChangesTableName);
        $collection->remove([ '_id' => $mongoId ]);
    }

    private function buildAddressBookPath($principalUri, $addressBookUri) {
        $uriExploded = explode('/', $principalUri);

        return 'addressbooks/' . $uriExploded[2] . '/' . $addressBookUri;
    }

    function getCards($addressBookId, $offset = 0, $limit = 0, $sort = null, $filters = null) {
        $fields = ['_id', 'uri', 'lastmodified', 'etag', 'size'];
        $query = [ 'addressbookid' => new \MongoId($addressBookId) ];
        $collection = $this->db->selectCollection($this->cardsTableName);
        $cards = [];

        if ($filters) {
            if (isset($filters['modifiedBefore'])) {
                $query['lastmodified'] = [
                    '$lt' => (int)$filters['modifiedBefore']
                ];
            }
        }

        $cardscursor = $collection->find($query, $fields);
        if ($sort != null) {
            $cardscursor->sort([ $sort => 1]);
        }
        $cardscursor->skip($offset);
        if ($limit > 0) {
            $cardscursor->limit($limit);
        }

        foreach ($cardscursor as $card) {
            $card['id'] = (string)$card['_id'];
            unset($card['_id']);
            $cards[] = $card;
        }
        return $cards;
    }

    function getCardCount($addressBookId) {
        $query = [ 'addressbookid' => new \MongoId($addressBookId) ];
        $collection = $this->db->selectCollection($this->cardsTableName);
        return $collection->count($query);
    }

    function getCard($addressBookId, $cardUri) {
        $fields = ['_id', 'uri', 'lastmodified', 'carddata', 'etag', 'size'];
        $query = [ 'addressbookid' => new \MongoId($addressBookId), 'uri' => $cardUri ];
        $collection = $this->db->selectCollection($this->cardsTableName);

        $card = $collection->findOne($query, $fields);
        if ($card) {
            $card['id'] = (string) $card['_id'];
            unset($card['_id']);
            return $card;
        } else {
            return false;
        }
    }

    function getMultipleCards($addressBookId, array $uris) {
        $fields = ['_id', 'uri', 'lastmodified', 'carddata', 'etag', 'size'];
        $query = [
            'addressbookid' => new \MongoId($addressBookId),
            'uri' => [ '$in' => $uris ]
        ];
        $collection = $this->db->selectCollection($this->cardsTableName);
        foreach ($collection->find($query, $fields) as $card) {
            $card['id'] = (string)$card['_id'];
            unset($card['_id']);
            $cards[] = $card;
        }
        return $cards;
    }

    function createCard($addressBookId, $cardUri, $cardData) {
        $extraData = $this->getDenormalizedData($cardData);

        $collection = $this->db->selectCollection($this->cardsTableName);
        $obj = [
            'carddata' => $cardData,
            'uri' => $cardUri,
            'lastmodified' => time(),
            'addressbookid' => new \MongoId($addressBookId),
            'size' => $extraData['size'],
            'etag' => $extraData['etag'],
            'fn' => $extraData['fn']
        ];

        $collection->insert($obj);
        $this->addChange($addressBookId, $cardUri, 1);

        return $extraData['etag'];
    }

    function updateCard($addressBookId, $cardUri, $cardData) {
        $extraData = $this->getDenormalizedData($cardData);
        $collection = $this->db->selectCollection($this->cardsTableName);

        $etag = '"' . md5($cardData) . '"';
        $data = [
            'carddata' => $cardData,
            'lastmodified' => time(),
            'size' => $extraData['size'],
            'etag' => $extraData['etag'],
            'fn' => $extraData['fn']
        ];
        $query = [ 'addressbookid' => new \MongoId($addressBookId), 'uri' => $cardUri ];

        $collection->update($query, [ '$set' => $data ]);
        $this->addChange($addressBookId, $cardUri, 2);

        return $extraData['etag'];
    }

    function deleteCard($addressBookId, $cardUri) {
        $query = [ 'addressbookid' => new \MongoId($addressBookId), 'uri' => $cardUri ];
        $collection = $this->db->selectCollection($this->cardsTableName);
        $res = $collection->remove($query, [ 'w' => 1 ]);
        $this->addChange($addressBookId, $cardUri, 3);
        return $res['n'] === 1;
    }

    function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = null) {
        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $res = $collection->findOne([ '_id' => new \MongoId($addressBookId) ], ['synctoken']);

        if (!$res || is_null($res['synctoken'])) return null;
        $currentToken = $res['synctoken'];

        $result = [
            'syncToken' => $currentToken,
            'added'     => [],
            'modified'  => [],
            'deleted'   => [],
        ];

        if ($syncToken) {
            $query = "SELECT uri, operation FROM " .
            $collection = $this->db->selectCollection($this->addressBookChangesTableName);
            $query = [
                'addressbookid' => new \MongoId($addressBookId),
                'synctoken' => [ '$gt' => $syncToken, '$lt' => $currentToken ]
            ];

            $res = $collection->find($query, ['uri', 'operation']);
            $res->sort([ 'synctoken' => 1 ]);
            if ($limit > 0) $res->limit((int)$limit);

            // This loop ensures that any duplicates are overwritten, only the
            // last change on a node is relevant.
            $changes = [];
            foreach ($res as $row) {
                $changes[$row['uri']] = $row['operation'];
            }

            foreach($changes as $uri => $operation) {
                switch($operation) {
                    case 1:
                        $result['added'][] = $uri;
                        break;
                    case 2:
                        $result['modified'][] = $uri;
                        break;
                    case 3:
                        $result['deleted'][] = $uri;
                        break;
                }
            }
        } else {
            // No synctoken supplied, this is the initial sync.
            $collection = $this->db->selectCollection($this->cardsTableName);
            $query = [ 'addressbookid' => new \MongoId($addressBookId) ];
            $fields = ['uri'];

            $added = [];
            foreach ($collection->find($query, $fields) as $row) {
                $added[] = $row['uri'];
            }
            $result['added'] = $added;
        }
        return $result;
    }

    function getSubscriptionsForUser($principalUri) {
        $fields[] = '_id';
        $fields[] = 'displayname';
        $fields[] = 'description';
        $fields[] = 'uri';
        $fields[] = 'source';
        $fields[] = 'principaluri';
        $fields[] = 'lastmodified';
        $fields[] = 'privilege';

        // Making fields a comma-delimited list
        $collection = $this->db->selectCollection($this->addressBookSubscriptionsTableName);
        $query = [ 'principaluri' => $principalUri ];
        $res = $collection->find($query, $fields);

        $subscriptions = [];
        foreach ($res as $row) {
            $subscription = [
                'id'           => (string)$row['_id'],
                '{DAV:}displayname' => $row['displayname'],
                'uri'          => $row['uri'],
                'principaluri' => $row['principaluri'],
                '{http://open-paas.org/contacts}subscription-type' => 'public',
                '{http://open-paas.org/contacts}source' => $row['source'],
                'lastmodified' => $row['lastmodified'],
                '{DAV:}acl'    => $this->getValue($row, 'privilege', ['dav:read', 'dav:write']),
                '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => $this->getValue($row, 'description', '')
            ];

            $subscriptions[] = $subscription;
        }

        return $subscriptions;
    }

    function createSubscription($principalUri, $uri, array $properties) {
        if (!isset($properties['{http://open-paas.org/contacts}source'])) {
            throw new \Sabre\DAV\Exception\Forbidden('The {http://open-paas.org/contacts}source property is required when creating subscriptions');
        }

        $obj = [
            'displayname'  => '',
            'description'  => '',
            'principaluri' => $principalUri,
            'uri'          => $uri,
            'privilege' => ['dav:read', 'dav:write'],
            'source'       => $properties['{http://open-paas.org/contacts}source']->getHref(),
            'lastmodified' => time(),
        ];

        foreach($properties as $property=>$newValue) {
            switch($property) {
                case '{DAV:}displayname' :
                    $obj['displayname'] = $newValue;
                    break;
                case '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' :
                    $obj['description'] = $newValue;
                    break;
                case '{DAV:}acl' :
                    $obj['privilege'] = $newValue;
                    break;
            }
        }

        $collection = $this->db->selectCollection($this->addressBookSubscriptionsTableName);
        $collection->insert($obj);

        $this->eventEmitter->emit('sabre:addressBookSubscriptionCreated', [
            [
                'path' => $this->buildAddressBookPath($principalUri, $uri)
            ]
        ]);

        return (string)$obj['_id'];
    }

    function updateSubscription($subscriptionId, \Sabre\DAV\PropPatch $propPatch) {
        $supportedProperties = array_keys($this->subscriptionPropertyMap);
        $supportedProperties[] = '{http://open-paas.org/contacts}source';

        $propPatch->handle($supportedProperties, function($mutations) use ($subscriptionId) {
            $newValues = [];
            $newValues['lastmodified'] = time();

            foreach($mutations as $propertyName=>$propertyValue) {
                if ($propertyName === '{http://open-paas.org/contacts}source') {
                    $newValues['source'] = $propertyValue->getHref();
                } else {
                    $fieldName = $this->subscriptionPropertyMap[$propertyName];
                    $newValues[$fieldName] = $propertyValue;
                }
            }

            $collection = $this->db->selectCollection($this->addressBookSubscriptionsTableName);
            $updatedAddressBook = $collection->findAndModify(
                [ '_id'  => new \MongoId($subscriptionId) ],
                [ '$set' => $newValues ],
                [ 'principaluri' => 1, 'uri' => 1],
                [ 'new'  => true ]
            );
            $this->eventEmitter->emit('sabre:addressBookSubscriptionUpdated', [
                [
                    'path' => $this->buildAddressBookPath($updatedAddressBook['principaluri'], $updatedAddressBook['uri'])
                ]
            ]);

            return true;
        });
    }

    function deleteSubscription($subscriptionId) {
        $collection = $this->db->selectCollection($this->addressBookSubscriptionsTableName);
        $query = [ '_id' => new \MongoId($subscriptionId) ];
        $addressBook = $collection->findOne($query);
        $collection->remove($query);

        $this->eventEmitter->emit('sabre:addressBookSubscriptionDeleted', [
            [
                'addressbookid' => $addressBook['_id'],
                'principaluri' => $addressBook['principaluri'],
                'path' => $this->buildAddressBookPath($addressBook['principaluri'], $addressBook['uri'])
            ]
        ]);
    }

    function deleteSubscriptions($principaluri, $uri) {
        $principalUriExploded = explode('/', $principaluri);
        $source = 'addressbooks/' . $principalUriExploded[2] . '/' . $uri;

        $subscriptions = $this->getSubscriptionsBySource($source);
        foreach($subscriptions as $subscription) {
            $this->deleteSubscription($subscription['_id']);
        }
    }

    function getSubscriptionsBySource($source) {
        $collection = $this->db->selectCollection($this->addressBookSubscriptionsTableName);
        $query = [ 'source' => $source ];

        $res = $collection->find($query, self::MINIMAL_ADDRESSBOOK_FIELDS);

        $result = [];
        foreach ($res as $row) {
            $result[] = [
                '_id' => $row['_id'],
                'principaluri' => $row['principaluri'],
                'uri' => $row['uri']
            ];
        }

        return $result;
    }

    function getAddressBookPublicRight($addressBookId) {
        $addressBookId = new \MongoId($addressBookId);

        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $query = ['_id' => $addressBookId];

        $res = $collection->findOne($query, ['public_right']);

        return isset($res['public_right']) ? $res['public_right'] : null;
    }

    /**
     *
     * @param  string $principalUri
     * @return array
     */
    function getSharedAddressBooksForUser($principalUri) {
        $fields[] = '_id';
        $fields[] = 'displayname';
        $fields[] = 'description';
        $fields[] = 'uri';
        $fields[] = 'principaluri';
        $fields[] = 'addressbookid';
        $fields[] = 'lastmodified';
        $fields[] = 'privilege';
        $fields[] = 'share_access';
        $fields[] = 'share_invitestatus';
        $fields[] = 'share_href';
        $fields[] = 'share_displayname';

        $collection = $this->db->selectCollection($this->sharedAddressBooksTableName);
        $query = [ 'principaluri' => $principalUri ];
        $res = $collection->find($query, $fields);

        $addressBooks = [];
        foreach ($res as $row) {
            $collection = $this->db->selectCollection($this->addressBooksTableName);
            $query = [ '_id' => new \MongoId((string)$row['addressbookid'])];
            $fields = [ 'principaluri', 'uri', 'synctoken', 'type' ];
            $addressBookInstance = $collection->findOne($query, $fields);

            $addressBook = [
                'id'           => (string)$row['_id'],
                '{DAV:}displayname' => $row['displayname'],
                'uri'          => $row['uri'],
                'principaluri' => $row['principaluri'],
                'addressbookid' => $row['addressbookid'],
                'lastmodified' => $this->getValue($row, 'lastmodified', ''),
                '{DAV:}acl'    => $this->getValue($row, 'privilege', ['dav:read', 'dav:write']),
                '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => $this->getValue($row, 'description', ''),
                '{http://open-paas.org/contacts}subscription-type' => 'delegation',
                '{http://open-paas.org/contacts}type' => $this->getValue($addressBookInstance, 'type', ''),
                '{http://calendarserver.org/ns/}getctag' => $this->getValue($addressBookInstance, 'synctoken', '0'),
                '{http://sabredav.org/ns}sync-token' => $this->getValue($addressBookInstance, 'synctoken', '0'),
                'share_access' => $this->getValue($row, 'share_access', SPlugin::ACCESS_NOACCESS),
                'share_invitestatus' => $this->getValue($row, 'share_invitestatus', SPlugin::INVITE_INVALID),
                'share_href' => $this->getValue($row, 'share_href', \Sabre\HTTP\encodePath($row['principaluri'])),
                'share_displayname' => $this->getValue($row, 'share_displayname', ''),
                'share_owner' => $this->getValue($addressBookInstance, 'principaluri'),
                'share_resource_uri' => $this->getValue($addressBookInstance, 'uri')
            ];

            $addressBooks[] = $addressBook;
        }

        return $addressBooks;
    }

    function getSharedAddressBooksBySource($sourceAddressBookId) {
        $collection = $this->db->selectCollection($this->sharedAddressBooksTableName);

        $query = [
            'addressbookid' => new \MongoId($sourceAddressBookId),
            'share_invitestatus' => SPlugin::INVITE_ACCEPTED
        ];
        $res = $collection->find($query, self::MINIMAL_ADDRESSBOOK_FIELDS);

        $addressBooks = [];
        foreach ($res as $row) {
            $addressBooks[] = $row;
        }

        return $addressBooks;
    }

    function updateSharedAddressBook($addressBookId, \Sabre\DAV\PropPatch $propPatch) {
        $supportedProperties = array_keys($this->sharedAddressBookPropertyMap);

        $propPatch->handle($supportedProperties, function($mutations) use ($addressBookId) {
            $newValues = [];
            $newValues['lastmodified'] = time();

            foreach($mutations as $propertyName=>$propertyValue) {
                $fieldName = $this->sharedAddressBookPropertyMap[$propertyName];
                $newValues[$fieldName] = $propertyValue;
            }

            $collection = $this->db->selectCollection($this->sharedAddressBooksTableName);
            $updatedAddressBook = $collection->findAndModify(
                [ '_id'  => new \MongoId($addressBookId) ],
                [ '$set' => $newValues ],
                [ 'principaluri' => 1, 'uri' => 1],
                [ 'new'  => true ]
            );
            $this->eventEmitter->emit('sabre:addressBookSubscriptionUpdated', [
                [
                    'path' => $this->buildAddressBookPath($updatedAddressBook['principaluri'], $updatedAddressBook['uri'])
                ]
            ]);

            return true;
        });
    }

    function deleteSharedAddressBook($addressBookId) {
        $collection = $this->db->selectCollection($this->sharedAddressBooksTableName);
        $query = [ '_id' => new \MongoId($addressBookId) ];
        $addressBook = $collection->findOne($query);
        $collection->remove($query);

        $this->eventEmitter->emit('sabre:addressBookSubscriptionDeleted', [
            [
                'addressbookid' => $addressBook['_id'],
                'principaluri' => $addressBook['principaluri'],
                'path' => $this->buildAddressBookPath($addressBook['principaluri'], $addressBook['uri'])
            ]
        ]);
    }

    function deleteAddressBooksSharedFrom($addressBookId, $options = null) {
        $addressBooks = [];
        $collection = $this->db->selectCollection($this->sharedAddressBooksTableName);
        $query = [ 'addressbookid' => new \MongoId($addressBookId) ];

        if (isset($options->sharees)) {
            $shareeHrefs = [];
            foreach($options->sharees as $sharee) {
                $shareeHrefs[] = $sharee->href;
            }

            $query['share_href'] = [ '$in' => $shareeHrefs ];
        }

        $cursor = $collection->find($query);
        foreach($cursor as $doc) {
            $addressBooks[] = $doc;
        }

        $collection->remove($query);

        foreach($addressBooks as $addressBook) {
            $this->eventEmitter->emit('sabre:addressBookSubscriptionDeleted', [
                [
                    'addressbookid' => $addressBook['_id'],
                    'principaluri' => $addressBook['principaluri'],
                    'path' => $this->buildAddressBookPath($addressBook['principaluri'], $addressBook['uri'])
                ]
            ]);
        }
    }

    /**
     * Updates the list of shares.
     *
     * @param string $addressBookId
     * @param \Sabre\DAV\Xml\Element\Sharee[] $sharees
     * @return void
     */
    function updateInvites($addressBookId, array $sharees) {
        $currentInvites = $this->getInvites($addressBookId);
        $mongoAddressBookId = new \MongoId($addressBookId);

        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $sharerAddressBook = $collection->findOne([ '_id' => $mongoAddressBookId ]);

        $shareeCollection = $this->db->selectCollection($this->sharedAddressBooksTableName);

        $shareesToCreate = [];
        $shareesToUpdate = [];
        $shareesToRemove = [];

        foreach($sharees as $sharee) {
            if ($sharee->access === SPlugin::ACCESS_NOACCESS) {
                $shareesToRemove[] = $sharee;
                continue;
            }

            // restrict on available accesses
            if ($sharee->access !== SPlugin::ACCESS_READ &&
                $sharee->access !== SPlugin::ACCESS_READWRITE &&
                $sharee->access !== SPlugin::ACCESS_ADMINISTRATION) {
                continue;
            }

            // you cannot share to no one
            if (is_null($sharee->principal)) {
                continue;
            }

            // you can not share to yourself
            if ($sharee->principal === $sharerAddressBook['principaluri']) {
                continue;
            }

            $isShareeExisting = false;
            foreach($currentInvites as $oldSharee) {
                if ($oldSharee->href === $sharee->href) {
                    $isShareeExisting = true;
                    break;
                }
            }

            if ($isShareeExisting) {
                $shareesToUpdate[] = $sharee;
            } else {
                $shareesToCreate[] = $sharee;
            }
        }

        $removeShareesOptions = new \stdClass();
        $removeShareesOptions->sharees = $shareesToRemove;
        $this->deleteAddressBooksSharedFrom($mongoAddressBookId, $removeShareesOptions);

        foreach ($shareesToUpdate as $sharee) {
            $shareeCollection->update([
                'addressbookid' => $mongoAddressBookId,
                'share_href' => $sharee->href
            ], [ '$set' => [
                'share_access' => $sharee->access,
                'share_displayname' => isset($sharee->properties['{DAV:}displayname']) ? $sharee->properties['{DAV:}displayname'] : null
            ]]);
        }

        foreach ($shareesToCreate as $sharee) {
            $newShareeAddressBook = [
                'addressbookid' => $mongoAddressBookId,
                'principaluri' => $sharee->principal,
                'uri' => \Sabre\DAV\UUIDUtil::getUUID(),
                'displayname' => $sharerAddressBook['displayname'],
                'description' => $sharerAddressBook['description'],
                'share_access' => $sharee->access,
                'share_href' => $sharee->href,
                'share_invitestatus' => $sharee->inviteStatus ?: SPlugin::INVITE_NORESPONSE,
                'share_displayname' => $this->getValue($sharee->properties, '{DAV:}displayname')
            ];
            $shareeCollection->insert($newShareeAddressBook);
        }
    }

    /**
     * Returns the list of people whom this address book is shared with.
     *
     * Every item in the returned list must be a Sharee object with at
     * least the following properties set:
     *   $href
     *   $shareAccess
     *   $inviteStatus
     *
     * and optionally:
     *   $properties
     *
     * @param mixed $addressBookId
     * @return \Sabre\DAV\Xml\Element\Sharee[]
     */
    function getInvites($addressBookId) {
        $fields[] = 'principaluri';
        $fields[] = 'share_access';
        $fields[] = 'share_href';
        $fields[] = 'share_invitestatus';
        $fields[] = 'share_displayname';

        $query = [ 'addressbookid' => new \MongoId($addressBookId) ];
        $collection = $this->db->selectCollection($this->sharedAddressBooksTableName);

        $res = $collection->find($query, $fields);
        $result = [];
        foreach ($res as $row) {
            if ($row['share_invitestatus'] === SPlugin::INVITE_INVALID) {
                continue;
            }

            $result[] = new \Sabre\DAV\Xml\Element\Sharee([
                'href' => $this->getValue($row, 'share_href', \Sabre\HTTP\encodePath($row['principaluri'])),
                'access' => (int)$row['share_access'],
                'inviteStatus' => (int)$row['share_invitestatus'],
                'properties' => !empty($row['share_displayname']) ? [ '{DAV:}displayname' => $row['share_displayname'] ] : [],
                'principal' => $row['principaluri']
            ]);
        }

        return $result;
    }

    /**
     * Set publish status on an address book
     *
     * @param mixed   $addressbookInfo  Information of the target addressbook
     * @param mixed   $value            Value of privilege on published address book
     *                                  false for unpublishing address book
     * @return void
    */
    function setPublishStatus($addressbookInfo, $value) {
        $mongoAddressBookId = new \MongoId($addressbookInfo['id']);
        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $query = ['_id' => $mongoAddressBookId];

        $collection->update($query, ['$set' => ['public_right' => $value]]);

        if (!$value) {
            $this->deleteSubscriptions($addressbookInfo['principaluri'], $addressbookInfo['uri']);
        }
    }

    function replyInvite($addressBookId, $status, $options) {
        $mongoAddressBookId = new \MongoId($addressBookId);

        $collection =$this->db->selectCollection($this->sharedAddressBooksTableName);
        $query = ['_id' => $mongoAddressBookId];
        $set = ['share_invitestatus' => $status];

        if ($slug = $this->getValue($options, 'dav:slug')) {
            $set['displayname'] = $slug;
        }

        if ($status === SPlugin::INVITE_ACCEPTED) {
            $updatedAddressBook = $collection->findAndModify(
                [ '_id' => $mongoAddressBookId ],
                [ '$set' => $set ],
                [ 'principaluri' => 1, 'uri' => 1 ],
                [ 'new'  => true ]
            );
    
            $this->eventEmitter->emit('sabre:addressBookSubscriptionCreated', [
                [
                    'path' => $this->buildAddressBookPath($updatedAddressBook['principaluri'], $updatedAddressBook['uri'])
                ]
            ]);
        } else {
            $collection->update($query, [ '$set' => $set ]);
        }
    }

    protected function addChange($addressBookId, $objectUri, $operation) {
        $adrcollection = $this->db->selectCollection($this->addressBooksTableName);
        $fields = ['synctoken'];
        $query = [ '_id' => new \MongoId($addressBookId) ];
        $res = $adrcollection->findOne($query, $fields);

        $changecollection = $this->db->selectCollection($this->addressBookChangesTableName);

        $obj = [
            'uri' => $objectUri,
            'synctoken' => $res['synctoken'],
            'addressbookid' => new \MongoId($addressBookId),
            'operation' => $operation
        ];
        $changecollection->insert($obj);

        $update = [ '$inc' => [ 'synctoken' => 1 ] ];
        $adrcollection->update($query, $update);
    }

    protected function getValue($array, $key, $default=null){
        return isset($array[$key]) ? $array[$key] : $default;
    }

    protected function getDenormalizedData($cardData) {
        $vcard = \Sabre\VObject\Reader::read($cardData);
        $fn = (string)$vcard->FN;
        $convertedFn = $this->CharAPI->getAsciiUpperCase($fn);
        $storedFn = ctype_alpha($convertedFn[0]) ? strtolower($convertedFn[0]) : '#';
        return [
            'fn'   => $storedFn,
            'size' => strlen($cardData),
            'etag' => '"' . md5($cardData) . '"'
        ];
    }

    private function ensureIndex() {
        // create a unique compound index on 'principaluri' and 'uri' for address book collection
        $addressBookCollection = $this->db->selectCollection($this->addressBooksTableName);
        $addressBookCollection->createIndex(
            array('principaluri' => 1, 'uri' => 1),
            array('unique' => true)
        );
    }
}
