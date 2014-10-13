<?php

namespace ESN\CalDAV;

class CalendarRoot extends \Sabre\DAV\Collection {

    const USER_PREFIX = 'principals/users';
    const COMMUNITY_PREFIX = 'principals/communities';

    function __construct(\Sabre\DAVACL\PrincipalBackend\BackendInterface $principalBackend,\Sabre\CalDAV\Backend\BackendInterface $caldavBackend, \MongoDB $db) {
        $this->principalBackend = $principalBackend;
        $this->caldavBackend = $caldavBackend;
        $this->db = $db;
    }

    public function getName() {
        return \Sabre\CalDAV\Plugin::CALENDAR_ROOT;
    }

    public function getChildren() {
        //throw new \Sabre\DAV\Exception\MethodNotAllowed('Listing children in this collection has been disabled');
        $homes = [];
        $res = $this->db->users->find(array(), array("_id"));
        foreach ($res as $user) {
            $principal = [ 'uri' => self::USER_PREFIX . '/' . $user['_id'] ];
            $homes[] = new \Sabre\CalDAV\CalendarHome($this->caldavBackend, $principal);
        }
        $res = $this->db->communities->find(array(), array("_id"));
        foreach ($res as $community) {
            $principal = [ 'uri' => self::COMMUNITY_PREFIX . '/' . $community['_id'] ];
            $homes[] = new \Sabre\CalDAV\CalendarHome($this->caldavBackend, $principal);
        }

        return $homes;
    }

    public function getChild($name) {
        $res = $this->db->users->findOne(['_id' => new \MongoId($name)]);
        if ($res) {
            $principal = [ 'uri' => self::USER_PREFIX . '/' . $name ];
            return new \Sabre\CalDAV\CalendarHome($this->caldavBackend, $principal);
        }

        $res = $this->db->communities->findOne(array('_id' => new \MongoId($name)), array());
        if ($res) {
            $principal = [ 'uri' => self::COMMUNITY_PREFIX . '/' . $name ];
            return new \Sabre\CalDAV\CalendarHome($this->caldavBackend, $principal);
        }

        throw new \Sabre\DAV\Exception\NotFound('Principal with name ' . $name . ' not found');
    }
}
