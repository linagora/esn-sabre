<?php

namespace ESN\DAVACL\PrincipalBackend;

use \ESN\Utils\Utils as Utils;

class Mongo extends \Sabre\DAVACL\PrincipalBackend\AbstractBackend {
    function __construct($db) {
        $this->db = $db;
        $this->collectionMap = [
            'users' => $this->db->users,
            'communities' => $this->db->communities,
            'projects' => $this->db->projects,
            'resources' => $this->db->resources
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
            $obj = $collection->findOne([ '_id' => new \MongoDB\BSON\ObjectId($parts[2]) ]);

            if ($parts[1] == 'resources') {
                $domain = $this->db->domains->findOne([ '_id' => $obj[ 'domain' ]]);
                $obj['domain'] = $domain;
            }
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
            return $this->searchGroupPrincipals('communities', $searchProperties, $test);
        } else if ($prefixPath == "principals/projects") {
            return $this->searchGroupPrincipals('projects', $searchProperties, $test);
        } else if ($prefixPath == "principals/resources") {
            return $this->searchGroupPrincipals('resources', $searchProperties, $test);
        } else {
            return [];
        }
    }

    function getGroupMemberSet($principal) {
        $parts = explode('/', $principal);
        $principals = [];
        if (count($parts) == 3 && $parts[0] == 'principals' && isset($this->collectionMap[$parts[1]])) {
            $collection = $this->collectionMap[$parts[1]];
            $res = $collection->findOne([ '_id' => new \MongoDB\BSON\ObjectId($parts[2])], [ 'projection' => [ 'members.member.id' => 1 ]]);
            if ($res && isset($res['members'])) {
                foreach ($res['members'] as $member) {
                    $principals[] = 'principals/users/' . $member['member']['id'];
                }
            }
        }

        return $principals;
    }

    function getGroupMembership($principal) {
        $parts = explode('/', $principal);
        $principals = [];
        if (count($parts) == 3 && $parts[0] == 'principals' && $parts[1] == 'users') {
            $query = [ 'members' => [ '$elemMatch' => [ 'member.id' => new \MongoDB\BSON\ObjectId($parts[2]) ] ] ];

            foreach ($this->db->communities->find($query, [ 'projection' => [ '_id' => 1 ]]) as $community) {
                $principals[] = 'principals/communities/' . $community['_id'];
            }

            foreach ($this->db->projects->find($query, [ 'projection' => [ '_id' => 1 ]]) as $project) {
                $principals[] = 'principals/projects/' . $project['_id'];
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
                $displayname = "";
                if (isset($obj['firstname'])) {
                    $displayname = $displayname . $obj['firstname'];
                }
                if (isset($obj['lastname'])) {
                    $displayname = $displayname . " " .  $obj['lastname'];
                }

                $principal = [
                    'id' => (string)$obj['_id'],
                    '{DAV:}displayname' => $displayname,
                    '{http://sabredav.org/ns}email-address' => Utils::firstEmailAddress($obj)
                ];
                break;
            case "communities":
            case "projects":
                $principal = [
                    'id' => (string)$obj['_id'],
                    '{DAV:}displayname' => $obj['title'],
                ];
                break;
            case "resources":
                $displayname = "";
                if (isset($obj['name'])) {
                    $displayname = $obj['name'];
                }

                $principal = [
                    'id' => (string)$obj['_id'],
                    '{DAV:}displayname' => $displayname
                ];

                if (isset($obj['domain']) && $obj['domain'] instanceof \MongoDB\Model\BSONDocument) {
                    $principal['{http://sabredav.org/ns}email-address'] = $obj['_id'] . '@' . $obj['domain']['name'];
                }
                break;
        }

        $principal['uri'] = 'principals/' . $type . '/' . $obj['_id'];

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
        $res = $collection->find($query, [ 'projection' => [ '_id' => 1 ]]);
        foreach ($res as $obj) {
            $principals[] = 'principals/' . $prefix . '/' . $obj['_id'];
        }
        return $principals;
    }

    private function searchGroupPrincipals($groupName, array $searchProperties, $test = 'allof') {
        $query = [];
        foreach ($searchProperties as $property => $value) {
            switch ($property) {
                case '{DAV:}displayname':
                    $query[] = [ 'title' => [ '$regex' => preg_quote($value), '$options' => 'i' ] ];
                    break;
                case '{http://sabredav.org/ns}email-address':
                    list($possibleId) = explode('@', $value);

                    try {
                        if($groupName === 'resources') {
                            $query[] = [ '_id' =>  new \MongoDB\BSON\ObjectId($possibleId) ];
                            break;
                        }
                    } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
                        //Resource email has not the default format {resourceId}@{domain}, skipping custom query
                    }

                    $query[] = [ 'email' => [
                        '$elemMatch' => ['$regex' => '^' . preg_quote($value) . '$', '$options' => 'i' ]
                    ] ];
                    break;
            }
        }

        $collection = $this->collectionMap[$groupName];
        return $this->queryPrincipals($groupName, $collection, $query, $test);
    }

    private function searchUserPrincipals(array $searchProperties, $test = 'allof') {
        $query = [];
        foreach ($searchProperties as $property => $value) {
            switch ($property) {
                case '{DAV:}displayname':
                    $query[] = [ '$or' => [
                        [ 'firstname' => [ '$regex' => preg_quote($value), '$options' => 'i' ] ],
                        [ 'lastname' => [ '$regex' => preg_quote($value), '$options' => 'i' ] ]
                    ] ];
                    break;
                case '{http://sabredav.org/ns}email-address':
                    $query[] = [ 'accounts.emails' => [
                        '$elemMatch' => ['$regex' => '^' . preg_quote($value) . '$', '$options' => 'i' ]
                    ] ];
                    break;
            }
        }

        return $this->queryPrincipals('users', $this->db->users, $query, $test);
    }
}
