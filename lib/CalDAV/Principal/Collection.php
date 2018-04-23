<?php

namespace ESN\CalDAV\Principal;

/**
 * Principal collection
 *
 */
class Collection extends \Sabre\CalDAV\Principal\Collection {

    /**
     * Returns a child object based on principal information
     *
     * @param array $principalInfo
     * @return User
     */
    function getChildForPrincipal(array $principalInfo) {

        return new PrincipalUser($this->principalBackend, $principalInfo);

    }

}
