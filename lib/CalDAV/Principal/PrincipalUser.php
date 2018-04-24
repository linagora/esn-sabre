<?php

namespace ESN\CalDAV\Principal;

class PrincipalUser extends \Sabre\CalDAV\Principal\User {

    function getACL() {
        $acl = parent::getACL();

        $acl[] = [
            'privilege' => '{DAV:}read',
            'principal' => '{DAV:}authenticated',
            'protected' => true,
        ];

        return $acl;
    }
}
