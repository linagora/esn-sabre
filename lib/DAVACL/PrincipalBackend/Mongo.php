<?php

namespace ESN\DAVACL\PrincipalBackend;

class Mongo extends \Sabre\DAVACL\PrincipalBackend\AbstractBackend {
    function __construct($db) {
        $this->db = $db;
        $this->collectionMap = [
            'users' => $this->db->users,
            'communities' => $this->db->communities
        ];
    }

    function getPrincipalsByPrefix($prefixPath) {
        $parts = explode('/', $prefixPath);
        $principals = [];
        if (count($parts) == 2 && $parts[0] == 'principals' &&
              isset($this->collectionMap[$parts[1]])) {
            $res = $this->collectionMap[$parts[1]]->find();
            foreach ($res as $obj) {
                $principals[] = $this->objectToPrincipal($obj, $parts[1]);
            }
        }
        return $principals;
    }

    function getPrincipalByPath($path) {
        $parts = explode('/', $path);
        if ($parts[0] == 'principals' && isset($this->collectionMap[$parts[1]]) && count($parts) == 3) {
            $collection = $this->collectionMap[$parts[1]];
            $obj = $collection->findOne(['_id' => new \MongoId($parts[2])]);
            return $obj ? $this->objectToPrincipal($obj, $parts[1]) : null;
        } else {
            return null;
        }
    }

    function updatePrincipal($path, \Sabre\DAV\PropPatch $propPatch) {
        // Not handling updates here, this is done through the ESN.
        throw new \Sabre\DAV\Exception\MethodNotAllowed('Changing principals is not permitted');
    }

    function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof') {
        if ($prefixPath == "principals/users") {
            return $this->searchUserPrincipals($searchProperties, $test);
        } else if ($prefixPath == "principals/communities") {
            return $this->searchCommunityPrincipals($searchProperties, $test);
        } else {
            return [];
        }
    }

    function getGroupMemberSet($principal) {
        $parts = explode('/', $principal);
        $principals = [];
        if (count($parts) == 3 && $parts[0] == 'principals' && $parts[1] == 'communities') {
            $community = $parts[2];
            $res = $this->db->communities->findOne([ '_id' => new \MongoId($parts[2])], [ 'members' ]);
            if ($res && isset($res['members'])) {
                foreach ($res['members'] as $member) {
                    $principals[] = 'principals/users/' . $member['user'];
                }
            }
        }
        return $principals;
    }

    function getGroupMembership($principal) {
        $parts = explode('/', $principal);
        $principals = [];
        if (count($parts) == 3 && $parts[0] == 'principals' && $parts[1] == 'users') {
            $query = [ 'members' => [ '$elemMatch' => [ 'user' => new \MongoId($parts[2]) ] ] ];
            $res = $this->db->communities->find($query, ['_id']);

            foreach ($res as $community) {
                $principals[] = 'principals/communities/' . $community['_id'];
            }
        }

        return $principals;
    }

    function setGroupMemberSet($principal, array $members) {
        // Not handling updates here, this is done through the ESN.
        throw new \Sabre\DAV\Exception\MethodNotAllowed('Changing group membership is not permitted');
    }

    private function objectToPrincipal($obj, $type) {
        $principal = null;
        switch($type) {
            case "users":
                $principal = [
                    'uri' => 'principals/users/' . $obj['_id'],
                    'id' => $obj['_id'],
                    '{DAV:}displayname' => $obj['firstname'] . " " . $obj['lastname'],
                    '{http://sabredav.org/ns}email-address' => $obj['emails'][0]
                ];
                break;
            case "communities":
                $principal = [
                    'uri' => 'principals/communities/' . $obj['_id'],
                    'id' => $obj['_id'],
                    '{DAV:}displayname' => $obj['title'],
                ];
                break;
        }

        return $principal;
    }

    private function queryPrincipals($prefix, $collection, $query, $test = 'allof') {
        if (count($query) > 0 && $test == 'allof') {
            $query = [ '$and' => $query ];
        } elseif (count($query) > 0 && $test == 'anyof') {
            $query = [ '$or' => $query ];
        } else {
            return [];
        }

        $principals = [];
        $res = $collection->find($query, [ '_id' ]);
        foreach ($res as $obj) {
            $principals[] = 'principals/' . $prefix . '/' . $obj['_id'];
        }
        return $principals;
    }

    private function searchCommunityPrincipals(array $searchProperties, $test = 'allof') {
        $query = [];
        foreach ($searchProperties as $property => $value) {
            switch ($property) {
                case '{DAV:}displayname':
                    $query[] = [ 'title' => [ '$regex' => $value, '$options' => 'i' ] ];
                    break;
            }
        }

        return $this->queryPrincipals('communities', $this->db->communities, $query, $test);
    }

    private function searchUserPrincipals(array $searchProperties, $test = 'allof') {
        $query = [];
        foreach ($searchProperties as $property => $value) {
            switch ($property) {
                case '{DAV:}displayname':
                    $query[] = [ '$or' => [
                        [ 'firstname' => [ '$regex' => $value, '$options' => 'i' ] ],
                        [ 'lastname' => [ '$regex' => $value, '$options' => 'i' ] ]
                    ] ];
                    break;
                case '{http://sabredav.org/ns}email-address':
                    $query[] = [ 'emails' => [
                        '$elemMatch' => ['$regex' => $value, '$options' => 'i' ]
                    ] ];
                    break;
            }
        }

        return $this->queryPrincipals('users', $this->db->users, $query, $test);
    }
}
