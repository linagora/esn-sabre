<?php

namespace ESN\CardDAV\Backend;

class Mongo extends \Sabre\CardDAV\Backend\AbstractBackend implements
    \Sabre\CardDAV\Backend\SyncSupport {

    protected $addressBooksTableName;
    protected $cardsTableName;
    protected $addressBookChangesTableName;

    function __construct(\MongoDB $db, $addressBooksTableName = 'addressbooks', $cardsTableName = 'cards', $addressBookChangesTableName = 'addressbookchanges') {
        $this->db = $db;
        $this->addressBooksTableName = $addressBooksTableName;
        $this->cardsTableName = $cardsTableName;
        $this->addressBookChangesTableName = $addressBookChangesTableName;
    }

    function getAddressBooksForUser($principalUri) {
        $fields = ['_id', 'uri', 'displayname', 'principaluri', 'description', 'synctoken'];
        $query = [ 'principaluri' => $principalUri ];
        $collection = $this->db->selectCollection($this->addressBooksTableName);

        $addressBooks = [];
        foreach ($collection->find($query, $fields) as $row) {
            $addressBooks[] = [
                'id'  => $row['_id'],
                'uri' => $row['uri'],
                'principaluri' => $row['principaluri'],
                '{DAV:}displayname' => $row['displayname'],
                '{' . \Sabre\CardDAV\Plugin::NS_CARDDAV . '}addressbook-description' => $row['description'],
                '{http://calendarserver.org/ns/}getctag' => $row['synctoken'],
                '{http://sabredav.org/ns}sync-token' => $row['synctoken']?$row['synctoken']:'0',
            ];
        }
        return $addressBooks;
    }

    function updateAddressBook($addressBookId, \Sabre\DAV\PropPatch $propPatch) {
        $supportedProperties = [
            '{DAV:}displayname',
            '{' . CardDAV\Plugin::NS_CARDDAV . '}addressbook-description',
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
                }
            }

            $collection = $this->db->selectCollection($this->addressBooksTableName);
            $query = [ '_id' => $addressBookId ];
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
                default :
                    throw new DAV\Exception\BadRequest('Unknown property: ' . $property);
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

    function getCards($addressbookId) {
        $fields = ['_id', 'uri', 'lastmodified', 'etag', 'size'];
        $query = [ 'addressbookid' => $addressbookId ];
        $collection = $this->db->selectCollection($this->cardsTableName);
        return iterator_to_array($collection->find($query, $fields));
    }

    function getCard($addressBookId, $cardUri) {
        $fields = ['_id', 'uri', 'lastmodified', 'etag', 'size'];
        $query = [ 'addressbookid' => $addressbookId, 'uri' => $cardUri ];
        $collection = $this->db->selectCollection($this->cardsTableName);

        return $collection->findOne($query, $fields);
    }

    function getMultipleCards($addressBookId, array $uris) {
        $fields = ['_id', 'uri', 'lastmodified', 'etag', 'size'];
        $query = [
            'addressbookid' => $addressbookId,
            'uri' => [ '$in' => $uris ]
        ];
        $collection = $this->db->selectCollection($this->cardsTableName);
        return iterator_to_array($collection->find($query, $fields));
    }

    function createCard($addressBookId, $cardUri, $cardData) {
        $collection = $this->db->selectCollection($this->cardsTableName);


        $etag = '"' . md5($cardData) . '"';
        $obj = [
            'carddata' => $cardData,
            'uri' => $cardUri,
            'lastmodified' => time(),
            'addressbookid' => $addressBookId,
            'size' => strlen($cardData),
            'etag' => $etag
        ];

        $collection->insert($obj);
        $this->addChange($addressBookId, $cardUri, 1);

        return $etag;
    }

    function updateCard($addressBookId, $cardUri, $cardData) {
        $collection = $this->db->selectCollection($this->cardsTableName);

        $etag = '"' . md5($cardData) . '"';
        $data = [
            'carddata' => $cardData,
            'lastmodified' => time(),
            'size' => strlen($cardData),
            'etag' => $etag
        ];
        $query = [ 'addressbookid' => $addressbookId, 'uri' => $cardUri ];

        $collection->update($query, [ '$set' => $data ]);
        $this->addChange($addressBookId, $cardUri, 2);

        return $etag;
    }

    function deleteCard($addressBookId, $cardUri) {
        $query = [ 'addressbookid' => $addressbookId, 'uri' => $cardUri ];
        $collection = $this->db->selectCollection($this->cardsTableName);
        $res = $collection->remove($query, [ 'w' => 1 ]);
        $this->addChange($addressBookId, $cardUri, 3);
        return $res['n'] === 1;
    }

    function getChangesForAddressBook($addressBookId, $syncToken, $syncLevel, $limit = null) {
        $collection = $this->db->selectCollection($this->addressBooksTableName);
        $res = $collection->findOne([ 'id' => $addressBookId ], ['synctoken']);

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
                'addressbookid' => $addressBookId,
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
            $query = [ 'addressbookid' => $addressBookId ];
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
            'addressbookid' => $addressBookId,
            'operation' => $operation
        ];
        $changecollection->insert($obj);

        $update = [ '$inc' => [ 'synctoken' => 1 ] ];
        $adrcollection->update($query, $update);
    }
}
