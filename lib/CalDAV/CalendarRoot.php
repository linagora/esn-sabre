<?php

namespace ESN\CalDAV;

class CalendarRoot extends \Sabre\DAV\Collection {

    const USER_PREFIX = 'principals/users';
    const RESOURCES_PREFIX = 'principals/resources';

    protected $principalBackend;
    protected $caldavBackend;
    protected $db;

    function __construct(\Sabre\DAVACL\PrincipalBackend\BackendInterface $principalBackend,\Sabre\CalDAV\Backend\BackendInterface $caldavBackend, \MongoDB\Database $db) {
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
        $res = $this->db->users->find([], [ 'projection' => ['_id' => 1 ]]);
        foreach ($res as $user) {
            $principal = [ 'uri' => self::USER_PREFIX . '/' . $user['_id'] ];
            $homes[] = new CalendarHome($this->caldavBackend, $principal);
        }
        $res = $this->db->resources->find([], [ 'projection' => ['_id' => 1 ]]);
        foreach ($res as $resource) {
            $principal = [ 'uri' => self::RESOURCES_PREFIX . '/' . $resource['_id'] ];
            $homes[] = new CalendarHome($this->caldavBackend, $principal);
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
            $principal = [ 'uri' => self::USER_PREFIX . '/' . $name ];
            return new CalendarHome($this->caldavBackend, $principal);
        }

        $res = $this->db->resources->findOne(['_id' => $mongoName], [ 'projection' => []]);
        if ($res) {
            $principal = [ 'uri' => self::RESOURCES_PREFIX . '/' . $name ];
            return new CalendarHome($this->caldavBackend, $principal);
        }

        throw new \Sabre\DAV\Exception\NotFound('Principal with name ' . $name . ' not found');
    }
}
