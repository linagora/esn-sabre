<?php

namespace ESN\CardDAV;

class AddressBookRoot extends \Sabre\DAV\Collection {

    const principalSupportedSet = [
        [
            'collectionName' => 'users',
            'prefix' => 'principals/users'
        ],
        /* Uncomment to reactive the fetch for communities
        [
            'collectionName' => 'communities',
            'prefix' => 'principals/communities'
        ],
        */
        [
            'collectionName' => 'projects',
            'prefix' => 'principals/projects'
        ],
        [
            'collectionName' => 'domains',
            'prefix' => 'principals/domains'
        ]
    ];

    function __construct(\Sabre\DAVACL\PrincipalBackend\BackendInterface $principalBackend,\Sabre\CardDAV\Backend\BackendInterface $addrbookBackend, \MongoDB\Database $db) {
        $this->principalBackend = $principalBackend;
        $this->addrbookBackend = $addrbookBackend;
        $this->db = $db;
    }

    public function getName() {
        return \Sabre\CardDAV\Plugin::ADDRESSBOOK_ROOT;
    }

    public function getChildren() {
        $homes = [];

        foreach(self::principalSupportedSet as $principalType) {
            $res = $this->db->selectCollection($principalType['collectionName'])->find([], [ 'projection' => ['_id' => 1 ]]);

            foreach ($res as $principal) {
                $uri = $principalType['prefix'] . '/' . $principal['_id'];
                $homes[] = new \ESN\CardDAV\AddressBookHome($this->addrbookBackend, $uri);
            }
        }

        return $homes;
    }

    public function getChild($name) {
        try {
            $mongoName = new \MongoDB\BSON\ObjectId($name);
        } catch (\MongoDB\Driver\Exception\Exception $e) {
            return null;
        }

        foreach(self::principalSupportedSet as $principalType) {
            $res = $this->db->selectCollection($principalType['collectionName'])->findOne(['_id' => $mongoName]);

            if ($res) {
                $uri = $principalType['prefix'] . '/' . $name;

                return new \ESN\CardDAV\AddressBookHome($this->addrbookBackend, $uri);
            }
        }

        throw new \Sabre\DAV\Exception\NotFound('Principal with name ' . $name . ' not found');
    }
}
