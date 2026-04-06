<?php

namespace ESN\DAVACL;

use Sabre\DAV\Exception\NotAuthenticated;
use Sabre\DAVACL\Exception\NeedPrivileges;

class ACLPlugin extends \Sabre\DAVACL\Plugin {

    /**
     * @var \ESN\DAVACL\DAO\ResourceDAO|null
     */
    protected $resourceDAO;

    public function __construct($resourceDAO = null) {
        $this->resourceDAO = $resourceDAO;
    }

    public function checkPrivileges($uri, $privileges, $recursion = self::R_PARENT, $throwExceptions = true) {
        if (!is_array($privileges)) {
            $privileges = [$privileges];
        }

        if ($this->isResourceAdmin($uri)) {
            return true;
        }

        $acl = $this->getCurrentUserPrivilegeSet($uri);

        $failed = [];
        foreach ($privileges as $priv) {
            if (!in_array($priv, $acl)) {
                $failed[] = $priv;
            }
        }

        if ($failed) {
            if ($this->allowUnauthenticatedAccess && is_null($this->getCurrentUserPrincipal())) {
                // We are not authenticated. Kicking in the Auth plugin.
                $authPlugin = $this->server->getPlugin('auth');
                $reasons = $authPlugin->getLoginFailedReasons();
                $authPlugin->challenge(
                    $this->server->httpRequest,
                    $this->server->httpResponse
                );
                throw new NotAuthenticated(implode(', ', $reasons).'. Login was needed for privilege: '.implode(', ', $failed).' on '.$uri);
            }
            if ($throwExceptions) {
                throw new NeedPrivileges($uri, $failed);
            } else {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks if the current authenticated user is an administrator of the resource
     * identified by the principal ID embedded in $uri.
     *
     * URI format: calendars/{principalId}/{calendarUri}/{objectName}
     * Resource administrators are stored as [{id: "..."}] in the resources collection.
     *
     * @param string $uri
     * @return bool
     */
    protected function isResourceAdmin($uri) {
        if (!$this->resourceDAO) {
            return false;
        }

        $currentUser = $this->getCurrentUserPrincipal();
        if (!$currentUser) {
            return false;
        }

        $parts = explode('/', ltrim($uri, '/'));
        if (count($parts) < 2 || $parts[0] !== 'calendars') {
            return false;
        }

        $principalId = $parts[1];
        $resource = $this->resourceDAO->findById($principalId);
        if (!$resource) {
            return false;
        }

        // Extract user ID from principal path (e.g. principals/users/{userId})
        $userId = basename($currentUser);

        $administrators = $resource['administrators'] ?? [];
        $adminIds = array_column((array) $administrators, 'id');

        return in_array($userId, $adminIds);
    }
}
