<?php

namespace ESN\DAVACL\PrincipalBackend;

// Keep Mongo as the DAO and apply principal privacy only when this backend is selected in esn.php.
use ESN\Utils\AuthTenant;
use ESN\Utils\TenantType;
use Sabre\DAV\PropPatch;
use Sabre\DAVACL\PrincipalBackend\AbstractBackend;

class PrivatePrincipalBackend extends AbstractBackend {
    const TECHNICAL_PRINCIPAL = 'principals/technicalUser';
    const DISPLAYNAME = '{DAV:}displayname';
    const EMAIL_ADDRESS = '{http://sabredav.org/ns}email-address';

    protected $principalBackend;
    protected $currentPrincipalProvider;

    function __construct(Mongo $principalBackend, $currentPrincipalProvider) {
        $this->principalBackend = $principalBackend;
        $this->currentPrincipalProvider = $currentPrincipalProvider;
    }

    function getPrincipalByPath($path) {
        return $this->principalBackend->getPrincipalByPath($path);
    }

    function findByUri($uri, $principalPrefix) {
        return $this->principalBackend->findByUri($uri, $principalPrefix);
    }

    function updatePrincipal($path, PropPatch $propPatch) {
        return $this->principalBackend->updatePrincipal($path, $propPatch);
    }

    function getGroupMemberSet($principal) {
        return $this->principalBackend->getGroupMemberSet($principal);
    }

    function getGroupMembership($principal) {
        return $this->principalBackend->getGroupMembership($principal);
    }

    function setGroupMemberSet($principal, array $members) {
        return $this->principalBackend->setGroupMemberSet($principal, $members);
    }

    function setAuthTenant(AuthTenant $authTenant) {
        return $this->principalBackend->setAuthTenant($authTenant);
    }

    // Nullable + in-body default: the CodeScene parser chokes on enum constants
    // used as parameter defaults, which corrupts the whole file analysis.
    function getAuthTenantByEmail(string $email, ?TenantType $tenantType = null): ?AuthTenant {
        return $this->principalBackend->getAuthTenantByEmail($email, $tenantType ?? TenantType::User);
    }

    function getAuthTenantByResourceEmail($email, ?TenantType $tenantType = null): ?AuthTenant {
        return $this->principalBackend->getAuthTenantByResourceEmail($email, $tenantType ?? TenantType::Resources);
    }

    function provisionUser(string $email, string $firstname = '', string $lastname = '', ?TenantType $tenantType = null): ?AuthTenant {
        return $this->principalBackend->provisionUser($email, $firstname, $lastname, $tenantType ?? TenantType::User);
    }

    function getAuthTenantByTeamCalendarEmail($email, ?TenantType $tenantType = null): ?AuthTenant {
        return $this->principalBackend->getAuthTenantByTeamCalendarEmail($email, $tenantType ?? TenantType::TeamCalendars);
    }

    function getPrincipalsByPrefix($prefixPath) {
        if ($this->isTechnicalPrincipal()) {
            return $this->principalBackend->getPrincipalsByPrefix($prefixPath);
        }

        $principals = [];

        foreach ($this->visiblePrincipalPaths($prefixPath) as $principalPath) {
            $principal = $this->principalBackend->getPrincipalByPath($principalPath);

            if ($principal) {
                $principals[] = $principal;
            }
        }

        return $principals;
    }

    function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof') {
        if ($this->isTechnicalPrincipal()) {
            return $this->principalBackend->searchPrincipals($prefixPath, $searchProperties, $test);
        }

        $principals = [];

        foreach ($this->visiblePrincipalPaths($prefixPath) as $principalPath) {
            $principal = $this->principalBackend->getPrincipalByPath($principalPath);

            if ($principal && $this->principalMatches($principal, $searchProperties, $test)) {
                $principals[$principal['uri']] = $principal['uri'];
            }
        }

        return array_values($principals);
    }

    private function visiblePrincipalPaths($prefixPath) {
        $prefixPath = $this->normalizePrincipalUri($prefixPath);
        $currentPrincipal = $this->currentPrincipalPath();

        if (!$currentPrincipal) {
            return [];
        }

        if (str_starts_with($currentPrincipal, $prefixPath . '/')) {
            return [$currentPrincipal];
        }

        if ($prefixPath === 'principals/domains' && $this->isUserPrincipalPath($currentPrincipal)) {
            return $this->principalBackend->getGroupMembership($currentPrincipal);
        }
        return [];
    }

    private function isUserPrincipalPath(string $principalPath): bool {
        $parts = explode('/', $principalPath);

        return count($parts) === 3 && $parts[1] === 'users';
    }

    private function principalMatches($principal, array $searchProperties, $test) {
        // RFC3744 principal-property-search supports "allof" and "anyof". Treat
        // unknown test modes as non-matching instead of falling back to a broader
        // result set.
        if (!in_array($test, ['allof', 'anyof'], true)) {
            return false;
        }

        $matches = [];

        foreach ($searchProperties as $property => $value) {
            $match = $this->propertyMatches($principal, $property, $value);

            if ($match !== null) {
                $matches[] = $match;
            }
        }

        if (empty($matches)) {
            return false;
        }

        if ($test === 'anyof') {
            return in_array(true, $matches, true);
        }

        return !in_array(false, $matches, true);
    }

    private function propertyMatches($principal, $property, $value) {
        if ($property === self::DISPLAYNAME && isset($principal[$property])) {
            return stripos($principal[$property], $value) !== false;
        }

        if ($property === self::EMAIL_ADDRESS && isset($principal[$property])) {
            [$possiblePrincipalId] = explode('@', $value);
            return strcasecmp($principal[$property], $value) === 0 ||
                $principal['uri'] === 'principals/resources/' . $possiblePrincipalId ||
                $principal['uri'] === 'principals/team-calendars/' . $possiblePrincipalId;
        }

        return null;
    }

    private function isTechnicalPrincipal() {
        return $this->currentPrincipalPath() === self::TECHNICAL_PRINCIPAL;
    }

    private function currentPrincipalPath() {
        if (!$this->currentPrincipalProvider) {
            return null;
        }

        $principal = call_user_func($this->currentPrincipalProvider);

        return $principal ? $this->normalizePrincipalUri($principal) : null;
    }

    private function normalizePrincipalUri($principalUri) {
        return trim($principalUri, '/');
    }
}
