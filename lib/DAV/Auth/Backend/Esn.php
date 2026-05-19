<?php

namespace ESN\DAV\Auth\Backend;

use \Sabre\HTTP;
use Sabre\Event\EventEmitter;
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;
use \ESN\Utils\AuthTenant;
use \ESN\Utils\Principal;
use \ESN\Utils\TenantType;

define('ESN_PUBLIC_KEY', __DIR__ . '/../../../../config/esn.key.pub');

define('LDAP_ADMIN_DN', getenv("LDAP_ADMIN_DN"));
define('LDAP_ADMIN_PASSWORD', getenv("LDAP_ADMIN_PASSWORD"));
define('LDAP_BASE', getenv("LDAP_BASE"));
define('LDAP_SERVER', getenv("LDAP_SERVER"));
define('LDAP_FILTER', getenv("LDAP_FILTER"));
define('SABRE_ADMIN_LOGIN', getenv("SABRE_ADMIN_LOGIN"));
define('SABRE_ADMIN_PASSWORD', getenv("SABRE_ADMIN_PASSWORD"));

#[\AllowDynamicProperties]
class Esn implements \Sabre\DAV\Auth\Backend\BackendInterface {

    protected $httpClient;
    protected ?AuthTenant $currentTenant = null;
    protected $apiroot;
    protected $principalBackend;
    protected $server;

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

    function __construct($apiroot, ?string $realm = null, $principalBackend, $server) {
        $this->apiroot = $apiroot;
        $this->httpClient = new HTTP\Client();
        $this->principalBackend = $principalBackend;
        $this->server = $server;

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

        $type = property_exists($user, 'user_type') ? $user->user_type : 'user';
        $tenant = new AuthTenant($user->_id, $type == $this->technicalUserType ? TenantType::Technical : TenantType::User);
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

    private function doImpersonatation(string $impersonationResult) : AuthTenant {
        $principalId = $this->principalBackend->getPrincipalIdByEmail($impersonationResult);
        if (!$principalId) {
            $principalId = $this->principalBackend->getPrincipalIdByResourceEmail($impersonationResult);
            if (!$principalId) {
                error_log("User not found for email: $impersonationResult");
                throw new AuthException("User not found");
            }
            return new AuthTenant($principalId, TenantType::Resources);
        }
        return new AuthTenant($principalId);
    }

    protected function validateUserPass($username, $password): AuthTenant {
        $user = trim($username);
        if ($this->impersonationEnabled()) {
            $impersonationResult = $this->attemptAdminImpersonation($user, $password);
            if ($impersonationResult !== null)
                return $this->doImpersonatation($impersonationResult);
        }

        $env_ldap_username_mode = getenv('LDAP_USERNAME_MODE');
        if ($env_ldap_username_mode == 'username') {
            $user = explode('@', $user);
            $user = $user[0];
        }


        # Open LDAP connection
        $ldapCon = ldap_connect(LDAP_SERVER);
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
                $ldapBind = ldap_bind($ldapCon, "uid=$safeUser," . LDAP_BASE, $password);
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

            $ldapBind2 = ldap_bind($ldapCon, LDAP_ADMIN_DN, LDAP_ADMIN_PASSWORD);
            if (!$ldapBind2) {
                $code = ldap_errno($ldapCon);
                $msg  = ldap_error($ldapCon);
                error_log("Bad admin credentials. LDAP bind failed: [$code] '$msg'");
                throw new AuthException("Bad admin credentials");
            }

            # Get real mail
            $searchResult = null;
            if (LDAP_FILTER != null) {
                $searchResult = ldap_search($ldapCon, LDAP_BASE, "(& (uid=$safeUser) " . LDAP_FILTER . ')');
            } else {
                $searchResult = ldap_search($ldapCon, LDAP_BASE, "(uid=$safeUser)");
            }
            $entries = ldap_get_entries($ldapCon, $searchResult);
        }
        finally {
            ldap_close($ldapCon);
        }
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

        $principalId = $this->principalBackend->getPrincipalIdByEmail($mail);
        if (!$principalId) {
            error_log("User not found for email: $mail");
            throw new  AuthException("User not found");
        }
        return new AuthTenant($principalId);
    }

    private function impersonationEnabled(): bool {
        return filter_var(getenv('SABRE_IMPERSONATION_ENABLED'), FILTER_VALIDATE_BOOLEAN);
    }

    protected function getAdminCredential(): ?array {
        if (!SABRE_ADMIN_LOGIN || !SABRE_ADMIN_PASSWORD) {
            return null;
        }

        return [SABRE_ADMIN_LOGIN, SABRE_ADMIN_PASSWORD];
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
            $authorizationHeader = $request->getHeader("Authorization");
            if ($authorizationHeader) {
                try {
                    $tenant = $this->checkJWT($authorizationHeader);
                    return $this->checkSuccess($tenant);
                } catch(AuthException $e) {
                    // fallback to other authentification
                }
            }

            $tCalendarToken = $request->getHeader("TwakeCalendarToken");
            if ($tCalendarToken) {
                try {
                    $tenant = $this->checkAuthByTCalendarToken($tCalendarToken);
                } catch(AuthException $e) {
                    // clear exception message returned to user
                    throw new AuthException('Invalid Token');
                }
                return $this->checkSuccess($tenant);
            }
            try {
                $tenant = $this->checkBasicAuth($request, $response);
                return $this->checkSuccess($tenant);
            } catch(AuthException $e) {
                // clear exception message returned to user
                throw new AuthException("Username or password was incorrect");
            }
        } catch(AuthException $e) {
            return [false, $e->getMessage()];
        } catch(\Exception $e) {
            $msg = $e->getMessage();
            $this->server->getLogger()->error(
                'An unexpected error happened when check',
                ['error' => $msg]
            );
            return [false, $msg];
        } 
    }

    /*
     * @throw ESN\DAV\Auth\Backend\AuthException in case of authentification failure
     */
    private function checkJWT($authorizationHeader) : AuthTenant {
        // No public key = no jwt
        if (!file_exists(ESN_PUBLIC_KEY))
            throw new AuthException('no public key file used by checkJWT()');

        if (preg_match('/Bearer\s((.*)\.(.*)\.(.*))/', $authorizationHeader, $matches)) {
            $token = $matches[1];
            // Load esn's public key
            $key = file_get_contents(ESN_PUBLIC_KEY);

            try {
                // Try to decode the token with the public key
                $user = JWT::decode($token, new Key($key, 'RS256'));
                // Get the user Id associated with the identifier of the token ( email in sub field )
                if (!isset($user->sub))
                    throw new \UnexpectedValueException("checkJWT: '$user->sub' is not valid");
                $email = $user->sub;
                if(!filter_var($email, FILTER_VALIDATE_EMAIL))
                    throw new \UnexpectedValueException("checkJWT: email '$email' is not a valid mail");
                $principleId = $this->principalBackend->getPrincipalIdByEmail($email);
                // No user found by that email
                if (!$principleId)
                    throw new AuthException('checkJWT: no user found by email');
                return new AuthTenant($principleId);
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
        }
        // the JWT format is weird
        throw new AuthException('checkJWT: weird format');
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
