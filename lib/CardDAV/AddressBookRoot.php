<?php

namespace ESN\CardDAV;

class AddressBookRoot extends \Sabre\DAV\Collection {

    const USER_PREFIX = 'principals/users';
    const COMMUNITY_PREFIX = 'principals/communities';

    function __construct(\Sabre\DAVACL\PrincipalBackend\BackendInterface $principalBackend,\Sabre\CardDAV\Backend\BackendInterface $addrbookBackend, \MongoDB $db) {
        $this->principalBackend = $principalBackend;
        $this->addrbookBackend = $addrbookBackend;
        $this->db = $db;
    }

    public function getName() {
        return \Sabre\CardDAV\Plugin::ADDRESSBOOK_ROOT;
    }

    public function getChildren() {
        //throw new \Sabre\DAV\Exception\MethodNotAllowed('Listing children in this collection has been disabled');
        $homes = [];
        $res = $this->db->users->find(array(), array("_id"));
        foreach ($res as $user) {
            $uri = self::USER_PREFIX . '/' . $user['_id'];
            $homes[] = new \Sabre\CardDAV\UserAddressBooks($this->addrbookBackend, $uri);
        }
        $res = $this->db->communities->find(array(), array("_id"));
        foreach ($res as $community) {
            $uri = self::COMMUNITY_PREFIX . '/' . $community['_id'];
            $homes[] = new \Sabre\CardDAV\UserAddressBooks($this->addrbookBackend, $uri);
        }

        return $homes;
    }

    public function getChild($name) {
        $res = $this->db->users->findOne(['_id' => new \MongoId($name)]);
        if ($res) {
            $uri = self::USER_PREFIX . '/' . $name;
            return new \Sabre\CardDAV\UserAddressBooks($this->addrbookBackend, $uri);
        }

        $res = $this->db->communities->findOne(array('_id' => new \MongoId($name)), array());
        if ($res) {
            $uri = self::COMMUNITY_PREFIX . '/' . $name;
            return new \Sabre\CardDAV\UserAddressBooks($this->addrbookBackend, $uri);
        }

        throw new \Sabre\DAV\Exception\NotFound('Principal with name ' . $name . ' not found');
    }
}