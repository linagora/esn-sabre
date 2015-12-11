<?php

namespace ESN\CardDAV\Backend;

class Mongo extends \Sabre\CardDAV\Backend\AbstractBackend implements
    \Sabre\CardDAV\Backend\SyncSupport {

    public $addressBooksTableName = 'addressbooks';
    public $cardsTableName = 'cards';
    public $addressBookChangesTableName = 'addressbookchanges';

    function __construct(\MongoDB $db) {
        $this->db = $db;
    }

    function getAddressBooksForUser($principalUri) {
        $fields = ['_id', 'uri', 'displayname', 'principaluri', 'privilege', 'description', 'synctoken'];
        $query = [ 'principaluri' => $principalUri ];
        $collection = $this->db->selectCollection($this->addressBooksTableName);

        $addressBooks = [];
        foreach ($collection->find($query, $fields) as $row) {
            $addressBooks[] = [
                'id'  => (string)$row['_id'],
                'uri' => $row['uri'],
                'principaluri' => $row['principaluri'],
                '{DAV:}displayname' => $row['displayname'],
                '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => $row['description'],
                '{DAV:}acl' => isset($row['privilege']) ? $row['privilege'] : ['dav:read', 'dav:write'],
                '{http://calendarserver.org/ns/}getctag' => $row['synctoken'],
                '{http://sabredav.org/ns}sync-token' => $row['synctoken']?$row['synctoken']:'0',
            ];
        }
        return $addressBooks;
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
            'displayname' => null,
            'description' => null,
            'privilege' => ['dav:read', 'dav:write'],
            'principaluri' => $principalUri,
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
        $collection = $this->db->selectCollection($this->cardsTableName);
        $collection->remove([ 'addressbookid' => $mongoId ]);

        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $collection->remove([ '_id' => $mongoId ]);

        $collection = $this->db->selectCollection($this->addressBookChangesTableName);
        $collection->remove([ '_id' => $mongoId ]);

    }

    function getCards($addressBookId, $offset = 0, $limit = 0, $sort = null) {
        $fields = ['_id', 'uri', 'lastmodified', 'etag', 'size'];
        $query = [ 'addressbookid' => new \MongoId($addressBookId) ];
        $collection = $this->db->selectCollection($this->cardsTableName);
        $cards = [];

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

    protected function getDenormalizedData($cardData) {
        $vcard = \Sabre\VObject\Reader::read($cardData);
        $fn = (string)$vcard->FN;

        return [
            'fn' => strtolower($fn),
            'size' => strlen($cardData),
            'etag' => '"' . md5($cardData) . '"'
        ];
    }
}
