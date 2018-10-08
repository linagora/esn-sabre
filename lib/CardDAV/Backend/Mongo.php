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

    const MINIMAL_ADDRESSBOOK_FIELDS = [
        '_id' => 1,
        'principaluri' => 1,
        'uri' => 1
    ];

    function __construct(\MongoDB\Database $db) {
        $this->db = $db;
        $this->eventEmitter = new EventEmitter();
        $this->CharAPI = new \ESN\Utils\CharAPI();
        $this->ensureIndex();
    }

    function getEventEmitter() {
        return $this->eventEmitter;
    }

    function getAddressBooksForUser($principalUri) {
        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $projection = [
            '_id' => 1,
            'uri' => 1,
            'displayname' => 1,
            'principaluri' => 1,
            'privilege' => 1,
            'type' => 1,
            'description' => 1,
            'synctoken' => 1
        ];
        $query = [ 'principaluri' => $principalUri ];
        $addressBooks = [];

        foreach ($collection->find($query, [ 'projection' => $projection ]) as $row) {
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
        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $projection = [
            '_id' => 1,
            'uri' => 1
        ];
        $query = [ 'principaluri' => $principalUri, 'uri' => $uri];
        $doc = $collection->findOne($query, [ 'projection' => $projection ]);

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
            $updatedAddressBook = $collection->findOneAndUpdate(
                [
                    '_id'  => new \MongoDB\BSON\ObjectId($addressBookId)
                ],
                [
                    '$set' => $updates
                ],
                [
                    'projection' => [ 'principaluri' => 1, 'uri' => 1 ],
                    'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
                ]
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
        $modified = $collection->findOneAndUpdate(
            [
                'principaluri' => $principalUri,
                'uri' => $uri
            ],
            [
                '$set' => $values
            ],
            [
                'projection' => [ '_id' => 1 ],
                'upsert' => true,
                'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
            ]
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
        $mongoId = new \MongoDB\BSON\ObjectId($addressBookId);

        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $query = [ '_id' => $mongoId ];
        $addressBook = $collection->findOne($query);
        $collection->deleteMany([ '_id' => $mongoId ]);

        $this->eventEmitter->emit('sabre:addressBookDeleted', [
            [
                'addressbookid' => $addressBook['_id'],
                'principaluri' => $addressBook['principaluri'],
                'path' => $this->buildAddressBookPath($addressBook['principaluri'], $addressBook['uri'])
            ]
        ]);

        $collection = $this->db->selectCollection($this->cardsTableName);
        $collection->deleteMany([ 'addressbookid' => $mongoId ]);

        $this->deleteSubscriptions($addressBook['principaluri'], $addressBook['uri']);

        $collection = $this->db->selectCollection($this->addressBookChangesTableName);
        $collection->deleteMany([ '_id' => $mongoId ]);
    }

    private function buildAddressBookPath($principalUri, $addressBookUri) {
        $uriExploded = explode('/', $principalUri);

        return 'addressbooks/' . $uriExploded[2] . '/' . $addressBookUri;
    }

    function getCards($addressBookId, $offset = 0, $limit = 0, $sort = null, $filters = null) {
        $projection = [
            '_id' => 1,
            'uri' => 1,
            'lastmodified' => 1,
            'etag' => 1,
            'size' => 1
        ];
        $query = [ 'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId) ];
        $collection = $this->db->selectCollection($this->cardsTableName);
        $cards = [];

        if ($filters) {
            if (isset($filters['modifiedBefore'])) {
                $query['lastmodified'] = [
                    '$lt' => (int)$filters['modifiedBefore']
                ];
            }
        }

        $options = [
            'projection' => $projection,
            'skip' => (int) $offset
        ];
        if ($limit > 0) $options['limit'] = (int) $limit;
        if ($sort != null) $options['sort'] = ([ $sort => 1]);

        $cardscursor = $collection->find($query, $options);

        foreach ($cardscursor as $card) {
            $card = $card->getArrayCopy();

            $card['id'] = (string)$card['_id'];
            unset($card['_id']);
            $cards[] = $card;
        }
        return $cards;
    }

    function getCardCount($addressBookId) {
        $query = [ 'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId) ];
        $collection = $this->db->selectCollection($this->cardsTableName);
        return $collection->count($query);
    }

    function getCard($addressBookId, $cardUri) {
        $collection = $this->db->selectCollection($this->cardsTableName);
        $projection = [
            '_id' => 1,
            'uri' => 1,
            'lastmodified' => 1,
            'carddata' => 1,
            'etag' => 1,
            'size' => 1
        ];
        $query = [ 'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId), 'uri' => $cardUri ];
        $card = $collection->findOne($query, ['projection' => $projection]);
        if ($card) {
            $card['id'] = (string) $card['_id'];
            unset($card['_id']);
            return $card;
        } else {
            return false;
        }
    }

    function getMultipleCards($addressBookId, array $uris) {
        $collection = $this->db->selectCollection($this->cardsTableName);
        $projection = [
            '_id' => 1,
            'uri' => 1,
            'lastmodified' => 1,
            'carddata' => 1,
            'etag' => 1,
            'size' => 1];
        $query = [
            'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId),
            'uri' => [ '$in' => $uris ]
        ];
        foreach ($collection->find($query, [ 'projection' => $projection ]) as $card) {
            $card = $card->getArrayCopy();

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
            'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId),
            'size' => $extraData['size'],
            'etag' => $extraData['etag'],
            'fn' => $extraData['fn']
        ];

        $collection->insertOne($obj);
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
        $query = [ 'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId), 'uri' => $cardUri ];

        $collection->updateMany($query, [ '$set' => $data ]);
        $this->addChange($addressBookId, $cardUri, 2);

        return $extraData['etag'];
    }

    function deleteCard($addressBookId, $cardUri) {
        $query = [ 'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId), 'uri' => $cardUri ];
        $collection = $this->db->selectCollection($this->cardsTableName);
        $res = $collection->deleteOne($query, [ 'writeConcern' => new \MongoDB\Driver\WriteConcern(1)]);
        $this->addChange($addressBookId, $cardUri, 3);
        return $res->getDeletedCount() === 1;
    }

    function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = null) {
        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $res = $collection->findOne([ '_id' => new \MongoDB\BSON\ObjectId($addressBookId) ], [ 'projection' => [ 'synctoken' => 1 ] ] );

        if (!$res || is_null($res['synctoken'])) return null;
        $currentToken = $res['synctoken'];

        $result = [
            'syncToken' => $currentToken,
            'added'     => [],
            'modified'  => [],
            'deleted'   => [],
        ];

        if ($syncToken) {
            $collection = $this->db->selectCollection($this->addressBookChangesTableName);
            $query = [
                'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId),
                'synctoken' => [ '$gt' => $syncToken, '$lt' => $currentToken ]
            ];

            $projection = [
                'uri' => 1,
                'operation' => 1
            ];

            $options = [
                'projection' => $projection,
                'sort' => [ 'synctoken' => 1 ]
            ];

            if ($limit > 0) $options['limit'] = $limit;

            $res = $collection->find($query, $options);

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
            $query = [ 'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId) ];

            $added = [];
            foreach ($collection->find($query, [ 'projection' => [ 'uri' => 1 ] ]) as $row) {
                $added[] = $row['uri'];
            }
            $result['added'] = $added;
        }
        return $result;
    }

    function getSubscriptionsForUser($principalUri) {
        // Making fields a comma-delimited list
        $collection = $this->db->selectCollection($this->addressBookSubscriptionsTableName);
        $projection = [
            '_id' => 1,
            'displayname' => 1,
            'description' => 1,
            'uri' => 1,
            'source' => 1,
            'principaluri' => 1,
            'lastmodified' => 1,
            'privilege' => 1
        ];
        $query = [ 'principaluri' => $principalUri ];
        $res = $collection->find($query, [ 'projection' => $projection ]);

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
        $insertResult = $collection->insertOne($obj);

        $this->eventEmitter->emit('sabre:addressBookSubscriptionCreated', [
            [
                'path' => $this->buildAddressBookPath($principalUri, $uri)
            ]
        ]);

        return (string) $insertResult->getInsertedId();
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
            $updatedAddressBook = $collection->findOneAndUpdate(
                [
                    '_id'  => new \MongoDB\BSON\ObjectId($subscriptionId)
                ],
                [
                    '$set' => $newValues
                ],
                [
                    'projection' => [ 'principaluri' => 1, 'uri' => 1 ],
                    'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
                ]
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
        $query = [ '_id' => new \MongoDB\BSON\ObjectId($subscriptionId) ];
        $addressBook = $collection->findOne($query);
        $collection->deleteMany($query);

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

        $res = $collection->find($query, [ 'projection' => self::MINIMAL_ADDRESSBOOK_FIELDS ]);

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
        $addressBookId = new \MongoDB\BSON\ObjectId($addressBookId);

        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $query = ['_id' => $addressBookId];

        $res = $collection->findOne($query, [ 'projection' => [ 'public_right' => 1 ] ]);

        return isset($res['public_right']) ? $res['public_right'] : null;
    }

    /**
     *
     * @param  string $principalUri
     * @return array
     */
    function getSharedAddressBooksForUser($principalUri) {
        $collection = $this->db->selectCollection($this->sharedAddressBooksTableName);
        $projection = [
            '_id' => 1,
            'displayname' => 1,
            'description' => 1,
            'uri' => 1,
            'principaluri' => 1,
            'addressbookid' => 1,
            'lastmodified' => 1,
            'privilege' => 1,
            'share_access' => 1,
            'share_invitestatus' => 1,
            'share_href' => 1,
            'share_displayname' => 1
        ];
        $query = [ 'principaluri' => $principalUri ];
        $res = $collection->find($query, [ 'projection' => $projection ]);

        $addressBooks = [];
        foreach ($res as $row) {
            $collection = $this->db->selectCollection($this->addressBooksTableName);
            $query = [ '_id' => new \MongoDB\BSON\ObjectId((string)$row['addressbookid'])];
            $projection = [
                'principaluri' => 1,
                'uri' => 1,
                'synctoken' => 1,
                'type' => 1
            ];
            $addressBookInstance = $collection->findOne($query, [ 'projection' => $projection ]);

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
            'addressbookid' => new \MongoDB\BSON\ObjectId($sourceAddressBookId),
            'share_invitestatus' => SPlugin::INVITE_ACCEPTED
        ];
        $res = $collection->find($query, [ 'projection' => self::MINIMAL_ADDRESSBOOK_FIELDS ]);

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
            $updatedAddressBook = $collection->findOneAndUpdate(
                [
                    '_id'  => new \MongoDB\BSON\ObjectId($addressBookId)
                ],
                [
                    '$set' => $newValues
                ],
                [
                    'projection' => [ 'principaluri' => 1, 'uri' => 1 ],
                    'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
                ]
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
        $query = [ '_id' => new \MongoDB\BSON\ObjectId($addressBookId) ];
        $addressBook = $collection->findOne($query);
        $collection->deleteMany($query);

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
        $query = [ 'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId) ];

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

        $collection->deleteMany($query);

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
        $mongoAddressBookId = new \MongoDB\BSON\ObjectId($addressBookId);

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
            $shareeCollection->updateMany([
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
            $shareeCollection->insertOne($newShareeAddressBook);
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
        $collection = $this->db->selectCollection($this->sharedAddressBooksTableName);
        $projection = [
            'principaluri' => 1,
            'share_access' => 1,
            'share_href' => 1,
            'share_invitestatus' => 1,
            'share_displayname' => 1
        ];
        $query = [ 'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId) ];
        $res = $collection->find($query, [ 'projection' => $projection ]);

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
        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $query = [ '_id' => new \MongoDB\BSON\ObjectId($addressbookInfo['id']) ];

        $collection->updateMany($query, ['$set' => ['public_right' => $value]]);

        if (!$value) {
            $this->deleteSubscriptions($addressbookInfo['principaluri'], $addressbookInfo['uri']);
        }
    }

    function replyInvite($addressBookId, $status, $options) {
        $collection =$this->db->selectCollection($this->sharedAddressBooksTableName);
        $query = [ '_id' => new \MongoDB\BSON\ObjectId($addressBookId) ];
        $set = [ 'share_invitestatus' => $status ];

        if ($slug = $this->getValue($options, 'dav:slug')) {
            $set['displayname'] = $slug;
        }

        if ($status === SPlugin::INVITE_ACCEPTED) {

            $updatedAddressBook = $collection->findOneAndUpdate(
                $query,
                [
                    '$set' => $set
                ],
                [
                    'projection' => [ 'principaluri' => 1, 'uri' => 1 ],
                    'returnDocument' => \MongoDB\Operation\FindOneAndUpdate::RETURN_DOCUMENT_AFTER
                ]
            );

            $this->eventEmitter->emit('sabre:addressBookSubscriptionCreated', [
                [
                    'path' => $this->buildAddressBookPath($updatedAddressBook['principaluri'], $updatedAddressBook['uri'])
                ]
            ]);
        } else {
            $collection->updateMany($query, [ '$set' => $set ]);
        }
    }

    protected function addChange($addressBookId, $objectUri, $operation) {
        $adrcollection = $this->db->selectCollection($this->addressBooksTableName);
        $query = [ '_id' => new \MongoDB\BSON\ObjectId($addressBookId) ];
        $res = $adrcollection->findOne($query, [ 'projection' => [ 'synctoken' => 1 ] ]);

        $changecollection = $this->db->selectCollection($this->addressBookChangesTableName);

        $obj = [
            'uri' => $objectUri,
            'synctoken' => $res['synctoken'],
            'addressbookid' => new \MongoDB\BSON\ObjectId($addressBookId),
            'operation' => $operation
        ];
        $changecollection->insertOne($obj);

        $update = [ '$inc' => [ 'synctoken' => 1 ] ];
        $adrcollection->updateMany($query, $update);
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
