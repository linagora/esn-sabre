<?php

namespace ESN\DAVACL\PrincipalBackend;

use \ESN\Utils\Utils as Utils;
use \ESN\Utils\TenantType as TenantType;
use \ESN\Utils\AuthTenant as AuthTenant;

#[\AllowDynamicProperties]
class Mongo extends \Sabre\DAVACL\PrincipalBackend\AbstractBackend implements BatchUserPrincipalLookup {
    private const USERS_PREFIX = 'principals/users';
    private const MAILTO = 'mailto:';

    /**
     * Addresses resolved per users query, i.e. at most twice as many '$in' values (the
     * address as given and lower-cased). Not measured yet: a conservative bound that
     * covers the attendee list of most events in a single query.
     */
    private const ADDRESS_LOOKUP_BATCH_SIZE = 50;
    // RFC 5321 section 4.5.3.1.3: a path is at most 256 octets, angle brackets included.
    private const MAX_ADDRESS_LENGTH = 254;

    protected $db;
    protected $collectionMap;
    protected ?AuthTenant $authTenant = null;

    /**
     * Group principals already built during this request, by URI.
     *
     * They deliberately leave out the group members: listing them reads every user of the
     * domain, which only the group address book home needs, and it gets them from the group
     * principal itself (see domainToPrincipal).
     */
    private array $groupPrincipals = [];

    /**
     * While withUserPrincipalsResolved() runs: the user principal URI of each address
     * already looked up, null when there is none, by tenant domain id.
     */
    private array $userPrincipalsByAddress = [];
    private int $userPrincipalResolutionDepth = 0;

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
        if ($type === 'resources') {
            return ['domain' => new \MongoDB\BSON\ObjectId($domainId)];
        }

        return [];
    }

    function getPrincipalByPath($path) {
        $parsed = $this->parsePrincipalPath($path);
        if ($parsed === null) {
            return null;
        }
        list($type, $id) = $parsed;

        try {
            $objectId = new \MongoDB\BSON\ObjectId($id);
        } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
            return null;
        }

        $obj = $this->collectionMap[$type]->findOne([ '_id' => $objectId ]);
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
            $this->assertResourceBelongsToDomain($obj, $domainId);
            $obj = $this->attachResourceDomain($obj);
        } else if ($type == 'team-calendars') {
            $this->assertTeamCalendarBelongsToDomain($obj, $domainId);
        } else if ($type == 'users') {
            $this->assertUserBelongsToDomain($obj, $domainId);

            $domainIds = array_column((array) $obj['domains'], 'domain_id');
            $obj['domains'] = $this->db->domains->find([ '_id' => [ '$in' => $domainIds ]]);
        }

        return $obj;
    }

    private function assertResourceBelongsToDomain($obj, string $domainId): void {
        if (!isset($obj['domain']) || (string)$obj['domain'] !== $domainId) {
            throw new \Sabre\DAV\Exception\Forbidden('Cross-domain resource access is not allowed');
        }
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
            (array)($obj['domains'] ?? [])
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

    private function hasAuthDomain(): bool {
        return (bool) $this->authTenant?->domainId;
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

    function withUserPrincipalsResolved(array $addresses, callable $operation) {
        $this->userPrincipalResolutionDepth++;
        try {
            if ($this->hasAuthDomain()) {
                $this->resolveUserAddresses($addresses);
            }

            return $operation();
        } finally {
            if (--$this->userPrincipalResolutionDepth === 0) {
                $this->userPrincipalsByAddress = [];
            }
        }
    }

    function findByUri($uri, $principalPrefix) {
        if (!$this->isResolvedUserLookup($uri, $principalPrefix)) {
            return parent::findByUri($uri, $principalPrefix);
        }

        $address = substr($uri, strlen(self::MAILTO));
        $this->resolveUserAddresses([$address]);

        return $this->userPrincipalsByAddress[$this->requireAuthDomainId()][$address];
    }

    private function isResolvedUserLookup($uri, $principalPrefix): bool {
        return $this->userPrincipalResolutionDepth > 0
            && $this->hasAuthDomain()
            && $principalPrefix === self::USERS_PREFIX
            && is_string($uri)
            && str_starts_with($uri, self::MAILTO);
    }

    private function resolveUserAddresses(array $addresses): void {
        $domainId = $this->requireAuthDomainId();
        $resolved = $this->userPrincipalsByAddress[$domainId] ?? [];

        $pending = array_values(array_unique(array_filter(
            array_map('strval', $addresses),
            fn(string $address) => !array_key_exists($address, $resolved)
        )));

        $this->userPrincipalsByAddress[$domainId] = array_reduce(
            array_chunk($pending, self::ADDRESS_LOOKUP_BATCH_SIZE),
            fn(array $carry, array $chunk) => $carry + $this->lookUpUserAddresses($chunk, $domainId),
            $resolved
        );
    }

    /**
     * Same lookup as searchUserPrincipals() for each address, each step querying all of them
     * at once: the exact addresses first, then the case-folded regex for the addresses still
     * unresolved, so that a match for one address never skips the fallback of another.
     *
     * @return array the user principal URI of each address, null when there is none
     */
    private function lookUpUserAddresses(array $addresses, string $domainId): array {
        $exactMatches = $this->findUserPrincipalsByAddress(
            $addresses,
            array_unique(array_merge(...array_map(fn(string $address) => $this->exactAddressForms($address), $addresses))),
            $domainId,
            fn(string $address, array $emails) => $this->exactAddressRank($address, $emails)
        );

        $unresolved = array_values(array_filter(
            $addresses,
            fn(string $address) => $exactMatches[$address] === null && $this->canBeAMailbox($address)
        ));
        if (empty($unresolved)) {
            return $exactMatches;
        }

        $caseFoldedMatches = $this->findUserPrincipalsByAddress(
            $unresolved,
            array_map(fn(string $address) => new \MongoDB\BSON\Regex($this->caseFoldedAddressPattern($address)), $unresolved),
            $domainId,
            fn(string $address, array $emails) => $this->caseFoldedAddressRank($address, $emails)
        );

        return $caseFoldedMatches + $exactMatches;
    }

    /**
     * Finds the users of the domain owning any of $values, then maps each address to the
     * best ranked of them: $rank tells how well a user's e-mails match an address, null
     * meaning not at all. Ties keep the query order, as findByUri() picks the first result.
     */
    private function findUserPrincipalsByAddress(array $addresses, array $values, string $domainId, callable $rank): array {
        $query = ['$and' => [
            ['accounts.emails' => ['$in' => array_values($values)]],
            $this->userDomainFilter($domainId)
        ]];
        $users = array_map(
            fn($user) => ['uri' => self::USERS_PREFIX . '/' . $user['_id'], 'emails' => $this->accountEmails($user)],
            iterator_to_array($this->db->users->find($query, ['projection' => ['_id' => 1, 'accounts.emails' => 1]]), false)
        );

        return array_combine(
            $addresses,
            array_map(fn(string $address) => $this->bestRankedUser($users, $address, $rank), $addresses)
        );
    }

    private function bestRankedUser(array $users, string $address, callable $rank): ?string {
        $ranked = array_values(array_filter(
            array_map(fn(array $user) => ['rank' => $rank($address, $user['emails']), 'uri' => $user['uri']], $users),
            fn(array $candidate) => $candidate['rank'] !== null
        ));
        usort($ranked, fn(array $left, array $right) => $left['rank'] <=> $right['rank']);

        return $ranked[0]['uri'] ?? null;
    }

    private function accountEmails($user): array {
        $emails = array_merge(...array_map(
            fn($account) => (array) ($account['emails'] ?? []),
            (array) ($user['accounts'] ?? [])
        ));

        return array_values(array_filter($emails, 'is_string'));
    }

    /**
     * The address as given ranks before its lower-cased form, as in the accounts.emails
     * index order the single-address lookup returns its results in.
     */
    private function exactAddressRank(string $address, array $emails): ?int {
        $matchingForms = array_keys(array_filter(
            $this->exactAddressForms($address),
            fn(string $form) => in_array($form, $emails, true)
        ));

        return $matchingForms[0] ?? null;
    }

    /**
     * PHP equivalent of caseFoldedAddressPattern(): strtolower only folds ASCII letters.
     */
    private function caseFoldedAddressRank(string $address, array $emails): ?int {
        $foldedEmails = array_map('strtolower', $emails);

        return in_array(strtolower($address), $foldedEmails, true) ? 0 : null;
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

        // Domain names are stored lower-case: compare the whole address case-insensitively.
        $email = strtolower($email);
        $projection = ['_id' => 1, 'preferredEmail' => 1, 'emails' => 1, 'accounts' => 1, 'domains' => 1 ];
        $query = ['accounts.emails' => $email];

        $user = $this->db->users->findOne($query, ['projection' => $projection]);

        if (!$user) {
            // Try alternative query patterns
            $altQuery = ['preferredEmail' => $email];
            $user = $this->db->users->findOne($altQuery, ['projection' => $projection]);

            if (!$user) {
                $altQuery2 = ['emails' => $email];
                $user = $this->db->users->findOne($altQuery2, ['projection' => $projection]);

                if (!$user) {
                    return null;
                }
            }
        }
        return $this->authTenantInMailDomain($user, $email, $tenantType);
    }

    /**
     * The user whose primary address is $email: the first address of its first
     * account, or its stored preferredEmail. Unlike getAuthTenantByEmail,
     * secondary addresses and aliases never match.
     *
     * Null when no user holds it, or when several do: an ambiguous address
     * cannot identify anyone.
     */
    function getAuthTenantByPrimaryEmail(string $email): ?AuthTenant {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        // Domain names are stored lower-case: compare the whole address case-insensitively.
        $email = strtolower($email);
        $users = $this->db->users->find(
            [ '$or' => [ [ 'accounts.0.emails.0' => $email ], [ 'preferredEmail' => $email ] ] ],
            [ 'projection' => [ '_id' => 1, 'domains' => 1 ], 'limit' => 2 ]
        )->toArray();

        if (count($users) > 1) {
            error_log("getAuthTenantByPrimaryEmail: several users have '$email' as primary address");
            return null;
        }
        return empty($users) ? null : $this->authTenantInMailDomain($users[0], $email, TenantType::User);
    }

    /**
     * The tenant of $user, provided its domain is the one of $email.
     */
    private function authTenantInMailDomain($user, string $email, TenantType $tenantType): ?AuthTenant {
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
            [ '_id' => $domainObjectId, 'name' => strtolower($domain) ],
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
                [ '_id' => $teamCalendar['domainId'], 'name' => strtolower($domain) ],
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
        ];
    }

    private function groupPrincipalsFor(string $principalUri): array {
        $groupPrincipals = [];

        foreach ($this->getGroupMembership($principalUri) as $groupPrincipal) {
            $groupPrincipals[] = $this->groupPrincipals[$groupPrincipal] ??= [
                'uri' => $groupPrincipal,
                'administrators' => $this->getAdministratorsForGroup($groupPrincipal)
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

    /**
     * Only the caller's own domain can match, like the prefix listing: the
     * names of the other tenants hosted on the server are not searchable.
     */
    private function searchDomainPrincipals($key, $test = 'allof') {
        if (!$key || !in_array($test, ['allof', 'anyof'], true)) {
            return [];
        }

        $query = [
            '$and' => [
                [ 'name' => [ '$regex' => preg_quote($key), '$options' => 'i' ] ],
                [ '_id' => new \MongoDB\BSON\ObjectId($this->requireAuthDomainId()) ]
            ]
        ];

        $principals = [];
        $res = $this->db->domains->find($query, [ 'projection' => [ '_id' => 1 ]]);
        foreach ($res as $obj) {
            $principals[] = 'principals/domains/' . $obj['_id'];
        }

        return $principals;
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
                    if ($groupName === 'team-calendars' || $groupName === 'resources') {
                        $query[] = $this->tenantIdAddressSearchQuery($value);
                        break;
                    }

                    $query[] = [ 'email' => [ '$elemMatch' => $this->exactAddressMatch($value) ] ];
                    break;
            }
        }

        $collection = $this->collectionMap[$groupName];
        if ($groupName === 'team-calendars') {
            return $this->queryTeamCalendarPrincipals($collection, $query, $test);
        }
        if ($groupName === 'resources') {
            return $this->queryResourcePrincipals($collection, $query, $test);
        }

        // A group type without a tenant-scoped query must not search every tenant.
        return [];
    }

    /**
     * Resources and team calendars are addressed as <id>@<tenant domain>: the
     * whole address, domain part included, must match.
     */
    private function tenantIdAddressSearchQuery($value): array {
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return [ '_id' => null ];
        }

        // Twake Calendar always stores emailAddress lower-cased: exact match keeps this an index lookup
        $customAddressQuery = [ 'emailAddress' => strtolower($value) ];
        $idQuery = $this->teamCalendarIdEmailQuery($value);

        return $idQuery ? [ '$or' => [ $customAddressQuery, $idQuery ] ] : $customAddressQuery;
    }

    private function teamCalendarIdEmailQuery(string $value): ?array {
        [$possibleId, $domain] = explode('@', $value, 2);
        try {
            $objectId = new \MongoDB\BSON\ObjectId($possibleId);
        } catch (\MongoDB\Driver\Exception\InvalidArgumentException $e) {
            return null;
        }

        $domainQuery = [
            '_id' => new \MongoDB\BSON\ObjectId($this->requireAuthDomainId()),
            'name' => strtolower($domain)
        ];
        if (!$this->db->domains->findOne($domainQuery, ['projection' => ['_id' => 1]])) {
            return null;
        }

        return [ '_id' => $objectId ];
    }

    private function queryResourcePrincipals($collection, array $query, $test): array {
        if (empty($query) || !in_array($test, ['allof', 'anyof'], true)) {
            return [];
        }

        $domainFilter = [ 'domain' => new \MongoDB\BSON\ObjectId($this->requireAuthDomainId()) ];
        $finalQuery = $test === 'anyof'
            ? [ '$and' => [[ '$or' => $query ], $domainFilter] ]
            : [ '$and' => array_merge($query, [$domainFilter]) ];

        $principals = [];
        $res = $collection->find($finalQuery, [ 'projection' => [ '_id' => 1 ]]);
        foreach ($res as $obj) {
            $principals[] = 'principals/resources/' . $obj['_id'];
        }

        return $principals;
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
        $query = $this->userSearchQuery($searchProperties, fn($value) => $this->caseFoldedAddressMatch($value));

        if (empty($query)) {
            return [];
        }

        $domainId = $this->requireAuthDomainId();

        if ($this->canUseExactAddressLookup($searchProperties, $test)) {
            $exactQuery = $this->userSearchQuery($searchProperties, fn($value) => $this->exactAddressMatch($value));
            $principals = $this->findUserPrincipals($this->withDomainFilter($exactQuery, $domainId, $test));
            if (!empty($principals)) {
                return $principals;
            }
        }

        return $this->findUserPrincipals($this->withDomainFilter($query, $domainId, $test));
    }

    /**
     * The exact lookup can use the accounts.emails index, whereas the case-folded regex
     * walks every user of the domain. Its results are a subset of the regex ones, so it
     * can only stand in for it when every criterion must hold ('allof'): with 'anyof',
     * hits on another criterion would hide the users matching the address in another case.
     */
    private function canUseExactAddressLookup(array $searchProperties, $test): bool {
        return $test === 'allof'
            && isset($searchProperties['{http://sabredav.org/ns}email-address']);
    }

    private function findUserPrincipals(array $query): array {
        $res = $this->db->users->find($query, ['projection' => ['_id' => 1]]);

        return array_map(
            fn($obj) => 'principals/users/' . $obj['_id'],
            iterator_to_array($res, false)
        );
    }

    private function userSearchQuery(array $searchProperties, callable $addressMatch): array {
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
                    $query[] = [ 'accounts.emails' => $addressMatch($value) ];
                    break;
            }
        }

        return $query;
    }

    /**
     * Matches the address as given or lower-cased, so that MongoDB can use the
     * accounts.emails index. strtolower only folds ASCII letters.
     */
    private function exactAddressMatch($value): array {
        return [ '$in' => $this->exactAddressForms((string) $value) ];
    }

    private function exactAddressForms(string $address): array {
        return array_values(array_unique([$address, strtolower($address)]));
    }

    /**
     * Matches an address exactly, ignoring ASCII case only. MongoDB's '$options' => 'i'
     * also folds non-ASCII characters (e.g. 'ſ' matches 's'), so letters are expanded
     * into explicit [xX] classes instead.
     */
    private function caseFoldedAddressMatch($value): array {
        if (!$this->canBeAMailbox((string) $value)) {
            return $this->exactAddressMatch($value);
        }

        return [ '$elemMatch' => [ '$regex' => $this->caseFoldedAddressPattern((string) $value) ] ];
    }

    /**
     * An address longer than any mailbox names nobody, and its case-folded pattern could
     * exceed the regex length MongoDB accepts: it is only ever compared exactly.
     */
    private function canBeAMailbox(string $address): bool {
        return strlen($address) <= self::MAX_ADDRESS_LENGTH;
    }

    private function caseFoldedAddressPattern(string $address): string {
        $pattern = preg_replace_callback(
            '/[a-z]/i',
            fn($letter) => '[' . strtolower($letter[0]) . strtoupper($letter[0]) . ']',
            preg_quote($address)
        );

        return '\\A' . $pattern . '\\z';
    }

    /**
     * Combines the search query with the tenant domain filter, honoring the
     * 'allof' / 'anyof' search semantics.
     */
    private function withDomainFilter(array $query, string $domainId, $test): array {
        $domainFilter = $this->userDomainFilter($domainId);

        if ($test === 'anyof') {
            return ['$and' => [['$or' => $query], $domainFilter]];
        }

        $query[] = $domainFilter;

        return ['$and' => $query];
    }

    private function userDomainFilter(string $domainId): array {
        return ['domains.domain_id' => new \MongoDB\BSON\ObjectId($domainId)];
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
