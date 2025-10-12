<?php

namespace ESN\DAV\Auth\Backend;

#[\AllowDynamicProperties]
class Mongo extends \Sabre\DAV\Auth\Backend\AbstractBasic {

    protected $db;
    protected $currentUserId;

    protected $principalPrefix = 'principals/users/';

    function __construct($database) {
        $this->db = $database;
    }

    protected function validateUserPass($username, $password) {
        $projection = [
            'password' => 1,
            '_id' => 1
        ];
        $query = array( 'accounts.emails' => trim(strtolower($username)));
        $rec = $this->db->users->findOne($query, [ 'projection' => $projection ] );

        $authenticated = false;
        if ($rec) {
            $hash = $rec['password'];
            $authenticated = crypt($password, $hash) === $hash;
        }

        if ($authenticated) {
            $this->currentUserId = $rec['_id'];
        }
        return $authenticated;
    }

    function getCurrentPrincipal() {
        $id = $this->currentUserId;
        return $id ? "principals/users/" . $id : null;
    }
}
