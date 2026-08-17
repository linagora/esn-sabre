<?php

namespace ESN\DAV\Auth\Backend;

use \Sabre\HTTP;
use Sabre\Event\EventEmitter;
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;
use \ESN\Utils\AuthTenant;
use \ESN\Utils\Env;
use \ESN\Utils\Principal;
use \ESN\Utils\TenantType;

define('ESN_PUBLIC_KEY', __DIR__ . '/../../../../config/esn.key.pub');

#[\AllowDynamicProperties]
class Esn implements \Sabre\DAV\Auth\Backend\BackendInterface {

    protected $httpClient;
    protected ?AuthTenant $currentTenant = null;
    protected $apiroot;
    protected $principalBackend;
    protected $server;
    protected bool $debug;

    /**
     * Authentication Realm.
     *
     * The realm is often displayed by browser clients when showing the
     * authentication dialog.
     *
     * @var string
     */
    protected string $realm = 'sabre/dav';

    protected string $technicalPrincipal = 'principals/technicalUser';
    protected string $technicalUserType = 'technical';

    function __construct($apiroot, ?string $realm = null, $principalBackend, $server, bool $debug = false) {
        $this->apiroot = $apiroot;
        $this->httpClient = new HTTP\Client();
        $this->principalBackend = $principalBackend;
        $this->server = $server;
        $this->debug = $debug;

        if (!is_null($realm)) {
            $this->realm = $realm;
        }
    }

    /**
     * Sets the authentication realm for this backend.
     *
     * @param string $realm
     */
    public function setRealm(string $realm)
    {
        $this->realm = $realm;
    }

    # <Added by xguimard>
    #  * copied from \Sabre\DAV\Auth\Backend\AbstractBasic
    #  * changes:
    #    + get mail from validateUserPass instead of using $userpass[0]
    protected function checkBasicAuth(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response): AuthTenant {
        $auth = new HTTP\Auth\Basic(
            $this->realm,
            $request,
            $response
        );

        $userpass = $auth->getCredentials();
        if (!$userpass)
            throw new AuthException("No 'Authorization: Basic' header found. Either the client didn't send one, or the server is misconfigured");
        return $this->validateUserPass($userpass[0], $userpass[1]);
    }
    # </Added>

    private function decodeResponse($response): AuthTenant {
        if ($response->getStatus() != 200)
            throw new AuthException('decodeResponse(): bad status code');

        $user = json_decode($response->getBodyAsString());
        if (!$user)
            throw new AuthException('decodeResponse(): no user found');

        $type = $user->user_type ?? 'user';
        $domainId =  $user->domainId ?? null;
        if(!$domainId)
            throw new AuthException('decodeResponse(): unknown domainId');
        $tenant = new AuthTenant($user->_id, $domainId, $type == $this->technicalUserType ? TenantType::Technical : TenantType::User);

        return $this->validateResponseIdentity($user, $tenant);
    }

    private function validateResponseIdentity($user, $tenant) {
        if (isset($user->domain)) {
            if (!filter_var($user->domain, FILTER_VALIDATE_DOMAIN)) {
                error_log("decodeResponse: invalid domain '$user->domain' for user '$user->_id'");
                throw new AuthException('decodeResponse(): invalid domain');
            }
            return $tenant;
        }
        if (isset($user->email)) {
            if (!filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                error_log("decodeResponse: invalid email '$user->email' for user '$user->_id'");
                throw new AuthException('decodeResponse(): no user found');
            }
            return $tenant;
        }
        error_log("decodeResponse: no email and no domain property for user '$user->_id'");
        throw new AuthException('decodeResponse(): no email and domain property');
    }

    private function checkAuthByTCalendarToken($token): AuthTenant {
        $url = $this->apiroot . '/api/technicalToken/introspect';
        $headers = ['X-TECHNICAL-TOKEN' => $token];
        $request = new HTTP\Request('GET', $url, $headers);
        return $this->decodeResponse($this->httpClient->send($request));
    }

    private function doImpersonation(string $impersonationResult) : AuthTenant {
        $tenant = $this->principalBackend->getAuthTenantByEmail($impersonationResult);
        if ($tenant)
            return $tenant;

        $tenant = $this->principalBackend->getAuthTenantByResourceEmail($impersonationResult);
        if($tenant)
            return $tenant;

        $tenant = $this->principalBackend->getAuthTenantByTeamCalendarEmail($impersonationResult);
        if($tenant)
            return $tenant;

        $tenant = $this->autoProvisionUser($impersonationResult);
        if($tenant)
            return $tenant;

        error_log("User not found for email: $impersonationResult");
        throw new AuthException("User not found");
    }

    protected function validateUserPass($username, $password): AuthTenant {
        $user = trim($username);
        if ($this->impersonationEnabled()) {
            $impersonationResult = $this->attemptAdminImpersonation($user, $password);
            if ($impersonationResult !== null)
                return $this->doImpersonation($impersonationResult);
        }

        if (Env::getString('LDAP_USERNAME_MODE') === 'username') {
            $user = explode('@', $user);
            $user = $user[0];
        }

        $entries = $this->authenticateLdapUser($user, $password);
        $mail = $this->getMailFromLdapEntries($entries, $username, $user);

        return $this->getAuthTenantByEmail($mail);
    }

    private function authenticateLdapUser($user, $password) {
        $ldapBase = Env::getString('LDAP_BASE');
        $ldapFilter = Env::getString('LDAP_FILTER');

        # Open LDAP connection
        $ldapCon = ldap_connect(Env::getString('LDAP_SERVER'));
        if (!$ldapCon) {
            error_log('Unable to connect to LDAP server');
            throw new AuthException('Unable to connect to LDAP server');
        }
        try {
            ldap_set_option($ldapCon, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldapCon, LDAP_OPT_REFERRALS, 0);

            # Try to authenticate
            $safeUser = ldap_escape($user, '', 0);

            try {
                $ldapBind = ldap_bind($ldapCon, "uid=$safeUser," . $ldapBase, $password);
            } catch (\ErrorException $e) {
                error_log("LDAP bind user failed for $user: " . $e->getMessage());
                throw new  AuthException("Bad credentials");
            }
            if (!$ldapBind) {
                $code = ldap_errno($ldapCon);
                $msg  = ldap_error($ldapCon);
                error_log("Bad credentials for '$user'. LDAP bind failed: [$code] '$msg'");
                throw new AuthException("Bad credentials");
            }

            $ldapBind2 = ldap_bind($ldapCon, Env::getString('LDAP_ADMIN_DN'), Env::getString('LDAP_ADMIN_PASSWORD'));
            if (!$ldapBind2) {
                $code = ldap_errno($ldapCon);
                $msg  = ldap_error($ldapCon);
                error_log("Bad admin credentials. LDAP bind failed: [$code] '$msg'");
                throw new AuthException("Bad admin credentials");
            }

            # Get real mail
            $searchResult = null;
            if ($ldapFilter !== null) {
                $searchResult = ldap_search($ldapCon, $ldapBase, "(& (uid=$safeUser) " . $ldapFilter . ')');
            } else {
                $searchResult = ldap_search($ldapCon, $ldapBase, "(uid=$safeUser)");
            }
            $entries = ldap_get_entries($ldapCon, $searchResult);
        }
        finally {
            ldap_close($ldapCon);
        }

        return $entries;
    }

    private function getMailFromLdapEntries($entries, $username, $user) {
        if ($entries['count'] == 0) {
            error_log("Unable to find $username which is valid for auth!");
            throw new  AuthException("Unable to find $username which is valid for auth");
        }
        if ($entries['count'] > 1) {
            error_log("More than one entry for $user");
        }
        if (!$entries[0]['mail']) {
            error_log("$user has no mail attribute");
            throw new  AuthException("$user has no mail attribute");
        }
        $mail = $entries[0]['mail'][0];
        if(!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            error_log("validateUserPass: $user has incorrect mail attribute $mail");
            throw new  AuthException("$user has incorrect mail attribute");
        }

        return $mail;
    }

    private function getAuthTenantByEmail($mail): AuthTenant {
        $tenant = $this->principalBackend->getAuthTenantByEmail($mail);
        if (!$tenant) {
            $tenant = $this->autoProvisionUser($mail);
        }
        if (!$tenant) {
            error_log("User not found for email: $mail");
            throw new  AuthException("User not found");
        }
        return $tenant;
    }

    /**
     * Auto-provision a user upon a DAV request when it does not yet exist.
     *
     * Needed upon migrations: instead of returning a 401 for a legitimate LDAP
     * or impersonated user that is not yet in the `users` collection, the entry
     * is created on the fly. Gated by the AUTO_PROVISION env var (default true).
     *
     * The user is only provisioned when it actually exists in the LDAP
     * directory, and its firstname/lastname are taken from the LDAP entry so
     * both services share the same content.
     */
    private function autoProvisionUser(string $email): ?AuthTenant {
        if (!$this->autoProvisionEnabled()) {
            return null;
        }

        $entry = $this->findLdapEntryByEmail($email);
        if ($entry === null) {
            error_log("autoProvisionUser: no LDAP entry for '$email', skipping auto-provision");
            return null;
        }

        $firstname = $entry['givenname'][0] ?? '';
        $lastname = $entry['sn'][0] ?? '';

        return $this->principalBackend->provisionUser($email, $firstname, $lastname);
    }

    /**
     * Look up an LDAP entry by mail, returning the raw entry or null when absent.
     *
     * Backs auto-provisioning: it lets us both confirm the user exists in the
     * LDAP directory and read its content (firstname, lastname).
     */
    private function findLdapEntryByEmail(string $email): ?array {
        $ldapBase = Env::getString('LDAP_BASE');
        $ldapFilter = Env::getString('LDAP_FILTER');

        $ldapCon = ldap_connect(Env::getString('LDAP_SERVER'));
        if (!$ldapCon) {
            error_log('findLdapEntryByEmail: unable to connect to LDAP server');
            return null;
        }
        try {
            ldap_set_option($ldapCon, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldapCon, LDAP_OPT_REFERRALS, 0);

            $ldapBind = ldap_bind($ldapCon, Env::getString('LDAP_ADMIN_DN'), Env::getString('LDAP_ADMIN_PASSWORD'));
            if (!$ldapBind) {
                $code = ldap_errno($ldapCon);
                $msg  = ldap_error($ldapCon);
                error_log("findLdapEntryByEmail: bad admin credentials. LDAP bind failed: [$code] '$msg'");
                return null;
            }

            $safeEmail = ldap_escape($email, '', 0);
            if ($ldapFilter !== null) {
                $searchResult = ldap_search($ldapCon, $ldapBase, "(& (mail=$safeEmail) " . $ldapFilter . ')');
            } else {
                $searchResult = ldap_search($ldapCon, $ldapBase, "(mail=$safeEmail)");
            }
            $entries = ldap_get_entries($ldapCon, $searchResult);
        } finally {
            ldap_close($ldapCon);
        }

        if ($entries['count'] == 0) {
            return null;
        }
        return $entries[0];
    }

    private function autoProvisionEnabled(): bool {
        return Env::getBoolean('AUTO_PROVISION', true);
    }

    private function impersonationEnabled(): bool {
        return Env::getBoolean('SABRE_IMPERSONATION_ENABLED', false);
    }

    protected function getAdminCredential(): ?array {
        $adminLogin = Env::getString('SABRE_ADMIN_LOGIN');
        $adminPassword = Env::getString('SABRE_ADMIN_PASSWORD');

        if (!$adminLogin || !$adminPassword) {
            return null;
        }

        return [$adminLogin, $adminPassword];
    }

    private function attemptAdminImpersonation(string $username, string $password): ?string {
        $adminCredential = $this->getAdminCredential();
        if ($adminCredential === null)
            return null;

        [$adminLogin, $adminPassword] = $adminCredential;

        $adminPrefix = $adminLogin . '&';
        if (!str_starts_with($username, $adminPrefix))
            return null;

        if ($password !== $adminPassword) {
            error_log('Bad admin password.');
            throw new AuthException('Bad admin password');
        }

        return substr($username, strlen($adminPrefix));
    }

    function getCurrentPrincipal() : ?string {
        return $this->currentTenant === null ? null : (string) $this->currentTenant->getPrincipal();
    }

    function getCurrentTenant(): ?AuthTenant {
        return $this->currentTenant;
    }

    private function checkSuccess(AuthTenant $tenant) {
        $this->currentTenant = $tenant;
        $principal = $tenant->getPrincipal();
        $this->server->emit("auth:success", [$tenant]);
        $msg = $tenant->tenantType === TenantType::Technical ? $this->technicalPrincipal : (string) $principal;
        return [true, $msg];
    }

    /**
     * When this method is called, the backend must check if authentication was
     * successful.
     *
     * The returned value must be one of the following
     *
     * [true, "principals/username"]
     * [false, "reason for failure"]
     *
     * If authentication was successful, it's expected that the authentication
     * backend returns a so-called principal url.
     *
     * Examples of a principal url:
     *
     * principals/admin
     * principals/user1
     * principals/users/joe
     * principals/uid/123457
     *
     * If you don't use WebDAV ACL (RFC3744) we recommend that you simply
     * return a string such as:
     *
     * principals/users/[username]
     *
     * @return array
     */
    function check(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response): array {
        try {
            $tenant = $this->authenticateRequest($request, $response);
            return $this->checkSuccess($tenant);
        } catch(AuthException $e) {
            if($this->debug)
                return [false, $e->getMessage()];
            // clear exception message returned to user
            return [false, "Authentification failure"];
        } catch(\Exception $e) {
            $msg = $e->getMessage();
            $this->server->getLogger()->error(
                'An unexpected error happened when check',
                ['error' => $msg]
            );
            return [false, $msg];
        } 
    }

    private function authenticateRequest(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response): AuthTenant {
        $bearer = $this->getBearerToken($request);
        if ($bearer) {
            return $this->checkJWT($bearer);
        }

        $tCalendarToken = $request->getHeader("TwakeCalendarToken");
        if ($tCalendarToken) {
            return $this->checkAuthByTCalendarToken($tCalendarToken);
        }

        return $this->checkBasicAuth($request, $response);
    }

    private function getBearerToken(\Sabre\HTTP\RequestInterface $request) {
        $authorizationHeader = $request->getHeader("Authorization");
        $bearer = null;
        if ($authorizationHeader)
            if(preg_match('/^Bearer\s+(\S+)$/', $authorizationHeader, $matches))
                  $bearer = $matches[1];

        return $bearer;
    }

    /*
     * @throw ESN\DAV\Auth\Backend\AuthException in case of authentification failure
     */
    private function checkJWT(string $token) : AuthTenant {
        $this->validateJWTPreconditions($token);
        try {
            $tenant = $this->resolveJWTAuthTenant($token);
        } catch(AuthException $e) {
            throw $e;
        } catch(\Exception $e) {
            // something wrong happened during decoding the JWT
            // things like unsupported algorithm, expired token...
            $this->server->getLogger()->error(
                'An unexpected error happened when decoding the JWT',
                ['error' => $e->getMessage()]
            );
            throw new AuthException($e->getMessage());
        }
        return $tenant;
    }

    private function validateJWTPreconditions(string $token) {
        // No public key = no jwt
        if (!file_exists(ESN_PUBLIC_KEY))
            throw new AuthException('no public key file used by checkJWT()');

        $matchtoken = preg_match('/^[A-Za-z0-9_-]{2,}(?:\.[A-Za-z0-9_-]{2,}){2}$/', $token);
        if (!$matchtoken)
            throw new AuthException('checkJWT: weird format');
    }

    private function resolveJWTAuthTenant(string $token) {
        // Load esn's public key and decode the token with the expected algorithm.
        $key = file_get_contents(ESN_PUBLIC_KEY);
        $user = JWT::decode($token, new Key($key, 'RS256'));
        $email = $this->getJWTEmail($user);
        $tenant = $this->principalBackend->getAuthTenantByEmail($email);
        // No user found by that email
        if (!$tenant)
            throw new AuthException('checkJWT: no user found by email');

        return $tenant;
    }

    private function getJWTEmail($user) {
        // The user identifier carried by ESN tokens is an email in the sub field.
        if (!isset($user->sub))
            throw new \UnexpectedValueException("checkJWT: '$user->sub' is not valid");
        $email = $user->sub;
        if(!filter_var($email, FILTER_VALIDATE_EMAIL))
            throw new \UnexpectedValueException("checkJWT: email '$email' is not a valid mail");

        return $email;
    }


    /**
     * This method is called when a user could not be authenticated, and
     * authentication was required for the current request.
     *
     * This gives you the opportunity to set authentication headers. The 401
     * status code will already be set.
     *
     * In this case of Basic Auth, this would for example mean that the
     * following header needs to be set:
     *
     * $response->addHeader('WWW-Authenticate', 'Basic realm=SabreDAV');
     *
     * Keep in mind that in the case of multiple authentication backends, other
     * WWW-Authenticate headers may already have been set, and you'll want to
     * append your own WWW-Authenticate header instead of overwriting the
     * existing one.
     */
    public function challenge(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response)
    {
        $auth = new HTTP\Auth\Basic(
            $this->realm,
            $request,
            $response
        );
        $auth->requireLogin();
    }
}
