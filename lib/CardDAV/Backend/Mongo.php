<?php

namespace ESN\CardDAV\Backend;

class Mongo extends \Sabre\CardDAV\Backend\AbstractBackend implements
    \ESN\CardDAV\Backend\SubscriptionSupport,
    \Sabre\CardDAV\Backend\SyncSupport {

    public $addressBooksTableName = 'addressbooks';
    public $cardsTableName = 'cards';
    public $addressBookChangesTableName = 'addressbookchanges';
    public $addressBookSubscriptionsTableName = 'addressbooksubscriptions';
    public $CharAPI;

    public $subscriptionPropertyMap = [
        '{DAV:}displayname' => 'displayname',
        '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => 'description'
    ];
    public $PUBLIC_RIGHTS = [
        '{DAV:}all',
        '{DAV:}read',
        '{DAV:}write'
    ];

    function __construct(\MongoDB $db) {
        $this->db = $db;
        $this->CharAPI = new \ESN\Utils\CharAPI();
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
            $query = [ '_id' => new \MongoId($addressBookId) ];
            $collection->update($query, [ '$set' => $updates ]);
            $this->addChange($addressBookId, "", 2);

            return true;
        });
    }

    function createAddressBook($principalUri, $url, array $properties) {

        $values = [
            'synctoken' => 1,
            'displayname' => '',
            'description' => '',
            'privilege' => ['dav:read', 'dav:write'],
            'principaluri' => $principalUri,
            'type' => '',
            'uri' => $url,
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
        $collection->insert($values);
        return (string) $values['_id'];
    }

    function deleteAddressBook($addressBookId) {
        $mongoId = new \MongoId($addressBookId);

        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $query = [ '_id' => $mongoId ];
        $row = $collection->findOne($query);

        $collection = $this->db->selectCollection($this->cardsTableName);
        $collection->remove([ 'addressbookid' => $mongoId ]);

        $this->deleteSubscriptions($row['principaluri'], $row['uri']);

        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $collection->remove([ '_id' => $mongoId ]);

        $collection = $this->db->selectCollection($this->addressBookChangesTableName);
        $collection->remove([ '_id' => $mongoId ]);

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

    function saveAddressBookPublicRight($addressBookId, $privilege, $addressbookInfo) {
        $mongoAddressBookId = new \MongoId($addressBookId);
        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $query = ['_id' => $mongoAddressBookId];

        $collection->update($query, ['$set' => ['public_right' => $privilege]]);

        if (!in_array($privilege, $this->PUBLIC_RIGHTS)) {
            $this->deleteSubscriptions($addressbookInfo['principaluri'], $addressbookInfo['uri']);
        }
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
            $query = [ '_id' => new \MongoId($subscriptionId) ];
            $collection->update($query, [ '$set' => $newValues ]);

            return true;
        });
    }

    function deleteSubscription($subscriptionId) {
        $collection = $this->db->selectCollection($this->addressBookSubscriptionsTableName);
        $query = [ '_id' => new \MongoId($subscriptionId) ];
        $collection->remove($query);
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
        $fields[] = '_id';
        $fields[] = 'principaluri';
        $fields[] = 'uri';

        $collection = $this->db->selectCollection($this->addressBookSubscriptionsTableName);
        $query = [ 'source' => $source ];

        $res = $collection->find($query, $fields);

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
}
