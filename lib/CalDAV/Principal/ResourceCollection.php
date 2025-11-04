<?php

namespace ESN\CalDAV\Principal;

/**
 * Principal collection for resources
 *
 */
class ResourceCollection extends \Sabre\CalDAV\Principal\Collection {

    /**
     * Returns a child object based on principal information
     *
     * @param array $principalInfo
     * @return PrincipalResource
     */
    function getChildForPrincipal(array $principalInfo) {

        return new PrincipalResource($this->principalBackend, $principalInfo);

    }

}
