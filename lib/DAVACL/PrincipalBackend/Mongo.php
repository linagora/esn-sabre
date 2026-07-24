<?php

namespace ESN\DAVACL\PrincipalBackend;

use \ESN\Utils\Utils as Utils;
use \ESN\Utils\TenantType as TenantType;
use \ESN\Utils\AuthTenant as AuthTenant;

#[\AllowDynamicProperties]
class Mongo extends \Sabre\DAVACL\PrincipalBackend\AbstractBackend {
    protected $db;
    protected $collectionMap;
    protected ?AuthTenant $authTenant = null;

    function setAuthTenant(AuthTenant $authTenant) {
        $this->authTenant = $authTenant;
    }

    function __construct($db, ?AuthTenant $authTenant = null) {
        $this->db = $db;
        $this->authTenant = $authTenant;
        $this->collectionMap = [
            'users' => $this->db->users,
            'resources' => $this->db->resources,
            'team-calendars' => $this->db->team_calendars,
            'domains' => $this->db->domains
        ];
    }

    function getPrincipalsByPrefix($prefixPath) {
        $type = $this->parsePrincipalPrefix($prefixPath);
        if ($type === null) {
            return [];
        }

        $principals = [];
        $res = $this->collectionMap[$type]->find($this->principalsByPrefixQuery($type));
        foreach ($res as $obj) {
            $principals[] = $this->objectToPrincipal($obj, $type);
        }

        return $principals;
    }

    private function principalsByPrefixQuery(string $type): array {
        $domainId = $this->requireAuthDomainId();

        if ($type === 'users') {
            return ['domains.domain_id' => new \MongoDB\BSON\ObjectId($domainId)];
        }
        if ($type === 'domains') {
            return ['_id' => new \MongoDB\BSON\ObjectId($domainId)];
        }
        if ($type === 'team-calendars') {
            return ['domainId' => new \MongoDB\BSON\ObjectId($domainId)];
        }

        return [];
    }

    function getPrincipalByPath($path) {
        $parsed = $this->parsePrincipalPath($path);
        if ($parsed === null) {
            return null;
        }
        list($type, $id) = $parsed;

        $obj = $this->collectionMap[$type]->findOne([ '_id' => new \MongoDB\BSON\ObjectId($id) ]);
        if (!$obj) {
            return null;
        }

        $obj = $this->enrichPrincipalObject($obj, $type, $id);

        return $this->objectToPrincipal($obj, $type);
    }

    /**
     * Enforces tenant isolation for the principal and loads the related
     * domain document(s) depending on the principal type.
     */
    private function enrichPrincipalObject($obj, string $type, string $id) {
        $domainId = $this->requireAuthDomainId();

        if ($type == 'domains') {
            $this->assertSameDomain($id, $domainId);
        } else if ($type == 'resources') {
            $obj = $this->attachResourceDomain($obj);
        } else if ($type == 'team-calendars') {
            $this->assertTeamCalendarBelongsToDomain($obj, $domainId);
        } else if ($type == 'users' && !empty($obj['domains'])) {
            $this->assertUserBelongsToDomain($obj, $domainId);

            $domainIds = array_column((array) $obj['domains'], 'domain_id');
            $obj['domains'] = $this->db->domains->find([ '_id' => [ '$in' => $domainIds ]]);
        }

        return $obj;
    }

    private function assertSameDomain(string $id, string $domainId): void {
        if ($id !== $domainId) {
            throw new \Sabre\DAV\Exception\Forbidden('Cross-domain principal access is not allowed');
        }
    }

    private function attachResourceDomain($obj) {
        if (isset($obj['domain'])) {
            $obj['domain'] = $this->db->domains->findOne([ '_id' => $obj['domain'] ]);
        }

        return $obj;
    }

    private function assertUserBelongsToDomain($obj, string $domainId): void {
        $userDomainIds = array_map(
            fn($d) => (string)$d['domain_id'],
            (array)$obj['domains']
        );

        if (!in_array($domainId, $userDomainIds, true)) {
            throw new \Sabre\DAV\Exception\Forbidden('Cross-domain principal access is not allowed');
        }
    }

    private function assertTeamCalendarBelongsToDomain($obj, string $domainId): void {
        if (!isset($obj['domainId']) || (string)$obj['domainId'] !== $domainId) {
            throw new \Sabre\DAV\Exception\Forbidden('Cross-domain team calendar access is not allowed');
        }
    }

    private function requireAuthDomainId(): string {
        $domainId = $this->authTenant?->domainId;
        if (!$domainId) {
            throw new \Sabre\DAV\Exception\Forbidden('Cross-domain calendar access is not allowed: null $authTenant');
        }

        return $domainId;
    }

    /**
     * Splits a 'principals/{type}/{id}' path into [$type, $id], or returns
     * null when the path is malformed or the type is unknown.
     */
    private function parsePrincipalPath($path): ?array {
        $parts = explode('/', $path);

        $isValid = count($parts) == 3
            && $parts[0] == 'principals'
            && isset($this->collectionMap[$parts[1]]);

        return $isValid ? [$parts[1], $parts[2]] : null;
    }

    /**
     * Extracts the principal type from a 'principals/{type}' prefix path, or
     * returns null when the prefix is malformed or the type is unknown.
     */
    private function parsePrincipalPrefix($prefixPath): ?string {
        $parts = explode('/', $prefixPath);

        $isValid = count($parts) == 2
            && $parts[0] == 'principals'
            && isset($this->collectionMap[$parts[1]]);

        return $isValid ? $parts[1] : null;
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
        } else if ($prefixPath == "principals/team-calendars") {
            return $this->searchGroupPrincipals('team-calendars', $searchProperties, $test);
        } else if ($prefixPath == "principals/domains" && isset($searchProperties['{DAV:}displayname'])) {
            return $this->searchDomainPrincipals($searchProperties['{DAV:}displayname'], $test);
        } else {
            return [];
        }
    }

    function getGroupMemberSet($principal) {
        $parsed = $this->parsePrincipalPath($principal);
        if ($parsed === null) {
            return [];
        }
        list($type, $id) = $parsed;

        if ($type === 'domains') {
            return $this->domainMemberPrincipals($id);
        }

        return $this->collectionMemberPrincipals($type, $id);
    }

    private function domainMemberPrincipals(string $domainId): array {
        $users = $this->db->users->find(
            [ 'domains' => [ '$elemMatch' => [ 'domain_id' => new \MongoDB\BSON\ObjectId($domainId) ] ] ],
            [ 'projection' => [ '_id' => 1 ]]
        );

        $principals = [];
        foreach ($users as $user) {
            $principals[] = 'principals/users/' . (string)$user['_id'];
        }

        return $principals;
    }

    private function collectionMemberPrincipals(string $type, string $id): array {
        $res = $this->collectionMap[$type]->findOne([ '_id' => new \MongoDB\BSON\ObjectId($id)], [ 'projection' => [ 'members.member.id' => 1 ]]);

        if (!$res || !isset($res['members'])) {
            return [];
        }

        $principals = [];
        foreach ($res['members'] as $member) {
            $principals[] = 'principals/users/' . $member['member']['id'];
        }

        return $principals;
    }

    function getGroupMembership($principal) {
        $parsed = $this->parsePrincipalPath($principal);
        if ($parsed === null) {
            return [];
        }
        list($type, $id) = $parsed;

        if ($type != 'users') {
            return [];
        }

        $user = $this->db->users->findOne(
            [ '_id' => new \MongoDB\BSON\ObjectId($id) ],
            [ 'projection' => [ 'domains' => 1 ]]
        );

        $principals = [];
        foreach ($user['domains'] as $domain) {
            $principals[] = 'principals/domains/' . (string)$domain['domain_id'];
        }

        return array_merge($principals, $this->administeredResourcePrincipals($id));
    }

    /**
     * Resource principals the given user administers.
     *
     * A resource administrator is a member of the resource principal, so the
     * resource calendar's owner privileges (read/write) apply to them and they
     * can update participation on behalf of the resource (issue #441). The
     * `administrators.id` field may be stored either as an ObjectId or as its
     * string representation depending on the provisioning path, so both forms
     * are matched.
     */
    private function administeredResourcePrincipals(string $userId): array {
        $adminId = [ $userId ];
        try {
            $adminId[] = new \MongoDB\BSON\ObjectId($userId);
        } catch (\MongoDB\Driver\Exception\Exception $e) {
            // Not an ObjectId-shaped id: only match the string form.
        }

        $resources = $this->db->resources->find(
            [ 'administrators.id' => [ '$in' => $adminId ] ],
            [ 'projection' => [ '_id' => 1 ] ]
        );

        $principals = [];
        foreach ($resources as $resource) {
            $principals[] = 'principals/resources/' . (string)$resource['_id'];
        }

        return $principals;
    }

    function setGroupMemberSet($principal, array $members) {
        // Not handling updates here, this is done through the ESN.
        throw new \Sabre\DAV\Exception\MethodNotAllowed('Changing group membership is not permitted');
    }


    // Nullable + in-body default: the CodeScene parser chokes on enum constants
    // used as parameter defaults, which corrupts the whole file analysis.
    function getAuthTenantByEmail(string $email, ?TenantType $tenantType = null): ?AuthTenant {
        $tenantType = $tenantType ?? TenantType::User;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $projection = ['_id' => 1, 'preferredEmail' => 1, 'emails' => 1, 'accounts' => 1, 'domains' => 1 ];
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
        if (!isset($user['domains'][0]['domain_id'])) {
            return null;
        }
        $domainObjectId = $user['domains'][0]['domain_id'];
        $domainFromMail = explode("@", $email)[1];
        $domain = $this->db->domains->findOne(
            [ '_id' => $domainObjectId, 'name' => $domainFromMail ],
            [ 'projection' => [ '_id' => 1 ] ]
        );
        if (!$domain) {
            return null;
        }
        return new AuthTenant($user['_id'], (string) $domainObjectId, $tenantType);
    }

    /**
     * Auto-provision a user in the `users` collection for the given email.
     *
     * The firstname and lastname are taken from the LDAP entry that backs the
     * user (see the auth backend). The domain part of the email must match an
     * existing domain, otherwise the user cannot be attached to a tenant and
     * null is returned. The created document follows the format used by
     * twake-calendar-side-service so that both services share the same data
     * model.
     */
    function provisionUser(string $email, string $firstname = '', string $lastname = '', ?TenantType $tenantType = null): ?AuthTenant {
        $tenantType = $tenantType ?? TenantType::User;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $email = strtolower($email);
        $domainName = explode('@', $email)[1];

        $domain = $this->db->domains->findOne(
            [ 'name' => $domainName ],
            [ 'projection' => [ '_id' => 1 ] ]
        );
        if (!$domain) {
            error_log("provisionUser: no domain '$domainName' found, cannot auto-provision '$email'");
            return null;
        }
        $domainObjectId = $domain['_id'];

        $userId = new \MongoDB\BSON\ObjectId();
        $document = [
            '_id' => $userId,
            'firstname' => $firstname,
            'lastname' => $lastname,
            'firstnames' => [],
            'password' => 'secret',
            // not part of the OpenPaaS data model but helps solve concurrency
            'email' => $email,
            'domains' => [
                [ 'domain_id' => $domainObjectId ]
            ],
            'accounts' => [
                [ 'type' => 'email', 'emails' => [ $email ] ]
            ]
        ];

        try {
            $this->db->users->insertOne($document);
        } catch (\MongoDB\Driver\Exception\BulkWriteException $e) {
            // A concurrent request likely provisioned the same user; re-read it.
            error_log("provisionUser: concurrent insert for '$email', re-reading: " . $e->getMessage());
            return $this->getAuthTenantByEmail($email, $tenantType);
        }

        return new AuthTenant($userId, (string) $domainObjectId, $tenantType);
    }

    function getAuthTenantByResourceEmail($email, ?TenantType $tenantType = null) {
        $tenantType = $tenantType ?? TenantType::Resources;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        [$possibleId, $domain] = explode('@', $email, 2);

        try {
            $objectId = new \MongoDB\BSON\ObjectId($possibleId);
        } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
            return null;
        }

        $resource = $this->db->resources->findOne(
            ['_id' => $objectId],
            ['projection' => ['_id' => 1, 'domain' => 1]]
        );
        if(!$resource)
            return null;
        if (!isset($resource['domain'])) {
            return null;
        }
        $domainObjectId = $resource['domain'];
        $domain = $this->db->domains->findOne(
            [ '_id' => $domainObjectId, 'name' => $domain ],
            [ 'projection' => [ '_id' => 1 ] ]
        );
        if (!$domain) {
            return null;
        }
        return new AuthTenant($resource['_id'], (string) $domainObjectId, $tenantType);
    }

    function getAuthTenantByTeamCalendarEmail($email, ?TenantType $tenantType = null) {
        $tenantType = $tenantType ?? TenantType::TeamCalendars;

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        [$possibleId, $domain] = explode('@', $email, 2);

        try {
            $objectId = new \MongoDB\BSON\ObjectId($possibleId);
        } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
            return null;
        }

        $teamCalendar = $this->db->team_calendars->findOne(
            ['_id' => $objectId],
            ['projection' => ['_id' => 1, 'domainId' => 1, 'domainName' => 1]]
        );
        if (!$teamCalendar || !isset($teamCalendar['domainId'])) {
            return null;
        }

        $domainMatches = isset($teamCalendar['domainName']) && strcasecmp($teamCalendar['domainName'], $domain) === 0;
        if (!$domainMatches) {
            $domainDocument = $this->db->domains->findOne(
                [ '_id' => $teamCalendar['domainId'], 'name' => $domain ],
                [ 'projection' => [ '_id' => 1 ] ]
            );
            if (!$domainDocument) {
                return null;
            }
        }

        return new AuthTenant($teamCalendar['_id'], (string) $teamCalendar['domainId'], $tenantType);
    }

    private function objectToPrincipal($obj, $type) {
        $principal = null;
        $principalUri = 'principals/' . $type . '/' . $obj['_id'];

        switch($type) {
            case "users":
                $principal = $this->userToPrincipal($obj);
                break;
            case "resources":
                $principal = $this->resourceToPrincipal($obj);
                break;
            case "team-calendars":
                $principal = $this->teamCalendarToPrincipal($obj);
                break;
            case "domains":
                $principal = $this->domainToPrincipal($obj, $principalUri);
                break;
        }

        $principal['uri'] = $principalUri;
        $principal['groupPrincipals'] = $this->groupPrincipalsFor($principalUri);

        return $principal;
    }

    private function userToPrincipal($obj): array {
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

        return $principal;
    }

    private function resourceToPrincipal($obj): array {
        $principal = [
            'id' => (string)$obj['_id'],
            '{DAV:}displayname' => isset($obj['name']) ? $obj['name'] : ""
        ];

        if (isset($obj['domain']) && $obj['domain'] instanceof \MongoDB\Model\BSONDocument) {
            $principal['{http://sabredav.org/ns}email-address'] = $obj['_id'] . '@' . $obj['domain']['name'];
        }

        return $principal;
    }

    private function teamCalendarToPrincipal($obj): array {
        $principal = [
            'id' => (string)$obj['_id'],
            '{DAV:}displayname' => $obj['displayName'] ?? $obj['name'] ?? ""
        ];

        if (isset($obj['emailAddress'])) {
            $principal['{http://sabredav.org/ns}email-address'] = $obj['emailAddress'];
        } else if (isset($obj['domainName'])) {
            $principal['{http://sabredav.org/ns}email-address'] = $obj['_id'] . '@' . $obj['domainName'];
        }

        return $principal;
    }

    private function domainToPrincipal($obj, string $principalUri): array {
        return [
            'id' => (string)$obj['_id'],
            '{DAV:}displayname' => isset($obj['name']) ? $obj['name'] : "",
            'administrators' => $this->getAdministratorsForGroup($principalUri),
            'members' => $this->getGroupMemberSet($principalUri)
        ];
    }

    private function groupPrincipalsFor(string $principalUri): array {
        $groupPrincipals = [];

        foreach ($this->getGroupMembership($principalUri) as $groupPrincipal) {
            $groupPrincipals[] = [
                'uri' => $groupPrincipal,
                'administrators' => $this->getAdministratorsForGroup($groupPrincipal),
                'members' => $this->getGroupMemberSet($groupPrincipal)
            ];
        }

        return $groupPrincipals;
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
                    if ($groupName === 'team-calendars') {
                        break;
                    } else {
                        $query[] = [ 'title' => [ '$regex' => preg_quote($value), '$options' => 'i' ] ];
                    }
                    break;
                case '{http://sabredav.org/ns}email-address':
                    list($possibleId) = explode('@', $value);

                    if ($groupName === 'team-calendars') {
                        $query[] = $this->teamCalendarEmailSearchQuery($value);
                        break;
                    }

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
        if ($groupName === 'team-calendars') {
            return $this->queryTeamCalendarPrincipals($collection, $query, $test);
        }
        return $this->queryPrincipals($groupName, $collection, $query, $test);
    }

    private function teamCalendarEmailSearchQuery($value): array {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return [ '_id' => null ];
        }

        [$possibleId, $domain] = explode('@', $value, 2);
        $domain = strtolower($domain);
        try {
            $objectId = new \MongoDB\BSON\ObjectId($possibleId);
        } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
            return [ '_id' => null ];
        }

        $domainQuery = [
            '_id' => new \MongoDB\BSON\ObjectId($this->requireAuthDomainId()),
            'name' => $domain
        ];
        if (!$this->db->domains->findOne($domainQuery, ['projection' => ['_id' => 1]])) {
            return [ '_id' => null ];
        }

        return [ '_id' => $objectId ];
    }

    private function queryTeamCalendarPrincipals($collection, array $query, $test): array {
        if (empty($query) || !in_array($test, ['allof', 'anyof'], true)) {
            return [];
        }

        $domainFilter = [ 'domainId' => new \MongoDB\BSON\ObjectId($this->requireAuthDomainId()) ];
        $finalQuery = $test === 'anyof'
            ? [ '$and' => [[ '$or' => $query ], $domainFilter] ]
            : [ '$and' => array_merge($query, [$domainFilter]) ];

        $principals = [];
        $res = $collection->find($finalQuery, [ 'projection' => [ '_id' => 1 ]]);
        foreach ($res as $obj) {
            $principals[] = 'principals/team-calendars/' . $obj['_id'];
        }

        return $principals;
    }

    private function searchUserPrincipals(array $searchProperties, $test = 'allof') {
        $query = $this->userSearchQuery($searchProperties);

        $domainId = $this->requireAuthDomainId();

        if (empty($query)) {
            return [];
        }

        $finalQuery = $this->withDomainFilter($query, $domainId, $test);

        $principals = [];
        $res = $this->db->users->find($finalQuery, ['projection' => ['_id' => 1]]);
        foreach ($res as $obj) {
            $principals[] = 'principals/users/' . $obj['_id'];
        }
        return $principals;
    }

    private function userSearchQuery(array $searchProperties): array {
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

        return $query;
    }

    /**
     * Combines the search query with the tenant domain filter, honoring the
     * 'allof' / 'anyof' search semantics.
     */
    private function withDomainFilter(array $query, string $domainId, $test): array {
        $domainFilter = ['domains.domain_id' => new \MongoDB\BSON\ObjectId($domainId)];

        if ($test === 'anyof') {
            return ['$and' => [['$or' => $query], $domainFilter]];
        }

        $query[] = $domainFilter;

        return ['$and' => $query];
    }

    private function getAdministratorsForGroup($principal) {
        $parts = explode('/', $principal);
        $administrators = [];

        if ($parts[1] === 'domains') {
            $domain = $this->db->domains->findOne(
                [ '_id' => new \MongoDB\BSON\ObjectId($parts[2]) ],
                [ 'projection' => [ 'administrators' => 1 ]]
            );

            if ($domain && isset($domain['administrators'])) {
                foreach ($domain['administrators'] as $administrator) {
                    $administrators[] = 'principals/users/' . (string)$administrator['user_id'];
                }
            }
        }

        return $administrators;
    }
}
