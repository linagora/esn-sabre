<?php

namespace ESN\DAVACL\PrincipalBackend;

use \ESN\Utils\Utils as Utils;

class Mongo extends \Sabre\DAVACL\PrincipalBackend\AbstractBackend {
    private $principalCache = [];

    function __construct($db) {
        $this->db = $db;
        $this->collectionMap = [
            'users' => $this->db->users,
            'resources' => $this->db->resources,
            'domains' => $this->db->domains
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
        // Check cache first (use array_key_exists to properly handle cached null values)
        if (array_key_exists($path, $this->principalCache)) {
            return $this->principalCache[$path];
        }

        $parts = explode('/', $path);
        if ($parts[0] == 'principals' && isset($this->collectionMap[$parts[1]]) && count($parts) == 3) {
            $collection = $this->collectionMap[$parts[1]];
            $obj = $collection->findOne([ '_id' => new \MongoDB\BSON\ObjectId($parts[2]) ]);

            if (!$obj) {
                // Cache null result to avoid repeated lookups of non-existent principals
                $this->principalCache[$path] = null;
                return null;
            }

            if ($parts[1] == 'resources') {
                if (isset($obj['domain'])) {
                    $domain = $this->db->domains->findOne([ '_id' => $obj[ 'domain' ]]);
                    $obj['domain'] = $domain;
                }
            } else if ($parts[1] == 'users' && !empty($obj[ 'domains' ])) {
                $domainIds = array_column((array) $obj[ 'domains' ], 'domain_id');

                $domains = $this->db->domains->find([ '_id' => [ '$in' => $domainIds ]]);
                $obj['domains'] = $domains;
            }

            $principal = $this->objectToPrincipal($obj, $parts[1]);
            $this->principalCache[$path] = $principal;
            return $principal;
        } else {
            // Cache null result for invalid paths
            $this->principalCache[$path] = null;
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
        } else if ($prefixPath == "principals/resources") {
            return $this->searchGroupPrincipals('resources', $searchProperties, $test);
        } else if ($prefixPath == "principals/domains" && isset($searchProperties['{DAV:}displayname'])) {
            return $this->searchDomainPrincipals($searchProperties['{DAV:}displayname'], $test);
        } else {
            return [];
        }
    }

    function getGroupMemberSet($principal) {
        $parts = explode('/', $principal);
        $principals = [];
        if (count($parts) == 3 && $parts[0] == 'principals' && isset($this->collectionMap[$parts[1]])) {
            if ($parts[1] === 'domains') {
                $users = $this->db->users->find(
                    [ 'domains' => [ '$elemMatch' => [ 'domain_id' => new \MongoDB\BSON\ObjectId($parts[2]) ] ] ],
                    [ 'projection' => [ '_id' => 1 ]]
                );

                foreach ($users as $user) {
                    $principals[] = 'principals/users/' . (string)$user['_id'];
                }
            } else {
                $collection = $this->collectionMap[$parts[1]];
                $res = $collection->findOne([ '_id' => new \MongoDB\BSON\ObjectId($parts[2])], [ 'projection' => [ 'members.member.id' => 1 ]]);

                if ($res && isset($res['members'])) {
                    foreach ($res['members'] as $member) {
                        $principals[] = 'principals/users/' . $member['member']['id'];
                    }
                }
            }
        }

        return $principals;
    }

    function getGroupMembership($principal) {
        $parts = explode('/', $principal);
        $principals = [];
        if (count($parts) == 3 && $parts[0] == 'principals' && $parts[1] == 'users') {
            $collaborationQuery = [ 'members' => [ '$elemMatch' => [ 'member.id' => new \MongoDB\BSON\ObjectId($parts[2]) ] ] ];

            $user = $this->db->users->findOne(
                [ '_id' => new \MongoDB\BSON\ObjectId($parts[2]) ],
                [ 'projection' => [ 'domains' => 1 ]]
            );

            foreach ($user['domains'] as $domain) {
                $principals[] = 'principals/domains/' . (string)$domain['domain_id'];
            }
        }

        return $principals;
    }

    function setGroupMemberSet($principal, array $members) {
        // Not handling updates here, this is done through the ESN.
        throw new \Sabre\DAV\Exception\MethodNotAllowed('Changing group membership is not permitted');
    }

    function getPrincipalIdByEmail($email) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $projection = ['_id' => 1, 'preferredEmail' => 1, 'emails' => 1, 'accounts' => 1];
        $query = ['accounts.emails' => strtolower($email)];

        $user = $this->db->users->findOne($query, ['projection' => $projection]);

        if (!$user) {
            // Try alternative query patterns
            $altQuery = ['preferredEmail' => strtolower($email)];
            $user = $this->db->users->findOne($altQuery, ['projection' => $projection]);

            if (!$user) {
                $altQuery2 = ['emails' => strtolower($email)];
                $user = $this->db->users->findOne($altQuery2, ['projection' => $projection]);

                if (!$user) {
                    return null;
                }
            }
        }

        return $user['_id'];
    }

    private function objectToPrincipal($obj, $type) {
        $principal = null;
        $principalUri = 'principals/' . $type . '/' . $obj['_id'];

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

                if (!empty($obj['domains'])) {
                    $adminForDomains = $this->getDomainsUserIsAdminOf($obj['_id'], $obj['domains']);

                    if (!empty($adminForDomains)) {
                        $principal['adminForDomains'] = $adminForDomains;
                    }
                }

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
            case "domains":
                $displayname = "";
                if (isset($obj['name'])) {
                    $displayname = $obj['name'];
                }

                $principal = [
                    'id' => (string)$obj['_id'],
                    '{DAV:}displayname' => $displayname,
                    'administrators' => $this->getAdministratorsForGroup($principalUri),
                    'members' => $this->getGroupMemberSet($principalUri)
                ];
                break;
        }

        $principal['uri'] = $principalUri;
        $groupPrincipals = [];

        foreach ($this->getGroupMembership($principal['uri']) as $groupPrincipal) {
            $groupPrincipals[] = [
                'uri' => $groupPrincipal,
                'administrators' => $this->getAdministratorsForGroup($groupPrincipal),
                'members' => $this->getGroupMemberSet($groupPrincipal)
            ];
        }

        $principal['groupPrincipals'] = $groupPrincipals;

        return $principal;
    }

    private function getDomainsUserIsAdminOf($userId, $domains) {
        $adminForDomains = [];

        foreach($domains as $domain) {
            if (!empty($domain['administrators'])) {
                $domainAdmins = array_column((array) $domain['administrators'], 'user_id');

                if (in_array($userId, $domainAdmins)) {
                    $adminForDomains[] = (string) $domain['_id'];
                }
            }
        }

        return $adminForDomains;
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

    private function searchDomainPrincipals($key, $test = 'allof') {
        $query = [];

        if ($key) {
            $query[] = array('name' => [ '$regex' => preg_quote($key), '$options' => 'i' ]);
        }

        return $this->queryPrincipals(
            'domains',
            $this->db->domains,
            $query,
            $test
        );
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

    private function getAdministratorsForGroup($principal) {
        $parts = explode('/', $principal);
        $administrators = [];

        if ($parts[1] === 'domains') {
            $domain = $this->db->domains->findOne(
                [ '_id' => new \MongoDB\BSON\ObjectId($parts[2]) ],
                [ 'projection' => [ 'administrators' => 1 ]]
            );

            foreach ($domain['administrators'] as $administrator) {
                $administrators[] = 'principals/users/' . (string)$administrator['user_id'];
            }
        }

        return $administrators;
    }
}
