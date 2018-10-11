<?php

namespace ESN\CardDAV;

class AddressBookRoot extends \Sabre\DAV\Collection {

    const USER_PREFIX = 'principals/users';
    const COMMUNITY_PREFIX = 'principals/communities';
    const PROJECT_PREFIX = 'principals/projects';
    const DOMAIN_PREFIX = 'principals/domains';

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
        $res = $this->db->users->find([], [ 'projection' => ['_id' => 1 ]]);
        foreach ($res as $user) {
            $uri = self::USER_PREFIX . '/' . $user['_id'];
            $homes[] = new \ESN\CardDAV\AddressBookHome($this->addrbookBackend, $uri);
        }

        //Reactive the fetch for communities
        /*$res = $this->db->communities->find([], [ 'projection' => ['_id' => 1 ]]);
        foreach ($res as $community) {
            $uri = self::COMMUNITY_PREFIX . '/' . $community['_id'];
            $homes[] = new \ESN\CardDAV\AddressBookHome($this->addrbookBackend, $uri);
        }*/

        $res = $this->db->projects->find([], [ 'projection' => ['_id' => 1 ]]);
        foreach ($res as $project) {
            $uri = self::PROJECT_PREFIX . '/' . $project['_id'];
            $homes[] = new \ESN\CardDAV\AddressBookHome($this->addrbookBackend, $uri);
        }

        $res = $this->db->domains->find([], [ 'projection' => ['_id' => 1 ]]);
        foreach ($res as $domain) {
            $uri = self::DOMAIN_PREFIX . '/' . $domain['_id'];
            $homes[] = new \ESN\CardDAV\AddressBookHome($this->addrbookBackend, $uri);
        }

        return $homes;
    }

    public function getChild($name) {
        try {
            $mongoName = new \MongoDB\BSON\ObjectId($name);
        } catch (\MongoDB\Driver\Exception\Exception $e) {
            return null;
        }

        $res = $this->db->users->findOne(['_id' => $mongoName]);
        if ($res) {
            $uri = self::USER_PREFIX . '/' . $name;
            return new \ESN\CardDAV\AddressBookHome($this->addrbookBackend, $uri);
        }

        //Reactive the fetch for communities
        /*$res = $this->db->communities->findOne([ '_id' => $mongoName ], [ 'projection' => []]);
        if ($res) {
            $uri = self::COMMUNITY_PREFIX . '/' . $name;
            return new \ESN\CardDAV\AddressBookHome($this->addrbookBackend, $uri);
        }*/

        $res = $this->db->projects->findOne([ '_id' => $mongoName ], [ 'projection' => []]);
        if ($res) {
            $uri = self::PROJECT_PREFIX . '/' . $name;
            return new \ESN\CardDAV\AddressBookHome($this->addrbookBackend, $uri);
        }

        $res = $this->db->domains->findOne(['_id' => $mongoName], [ 'projection' => []]);
        if ($res) {
            $uri = self::DOMAIN_PREFIX . '/' . $name;
            return new AddressBookHome($this->addrbookBackend, $uri);
        }

        throw new \Sabre\DAV\Exception\NotFound('Principal with name ' . $name . ' not found');
    }
}
