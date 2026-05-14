<?php

namespace ESN\DAV\Auth\Backend;

use \Sabre\HTTP;
use Sabre\Event\EventEmitter;
use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;

define('ESN_PUBLIC_KEY', __DIR__ . '/../../../../config/esn.key.pub');

define('LDAP_ADMIN_DN', getenv("LDAP_ADMIN_DN"));
define('LDAP_ADMIN_PASSWORD', getenv("LDAP_ADMIN_PASSWORD"));
define('LDAP_BASE', getenv("LDAP_BASE"));
define('LDAP_SERVER', getenv("LDAP_SERVER"));
define('LDAP_FILTER', getenv("LDAP_FILTER"));
define('SABRE_ADMIN_LOGIN', getenv("SABRE_ADMIN_LOGIN"));
define('SABRE_ADMIN_PASSWORD', getenv("SABRE_ADMIN_PASSWORD"));

#[\AllowDynamicProperties]
class Esn extends \Sabre\DAV\Auth\Backend\AbstractBasic {

    protected $httpClient;
    protected $currentUserId;
    protected $apiroot;
    protected $eventEmitter;
    protected $principalBackend;
    protected $server;

    protected $principalPrefix = 'principals/users/';
    protected $currentPrincipalPrefix = 'principals/users/';
    protected $technicalPrincipal = 'principals/technicalUser';
    protected $technicalUserType = 'technical';

    function __construct($apiroot, $realm = null, $principalBackend, $server) {
        $this->apiroot = $apiroot;
        $this->httpClient = new HTTP\Client();
        $this->eventEmitter = new EventEmitter();
        $this->principalBackend = $principalBackend;
        $this->server = $server;

        if (!is_null($realm)) {
            $this->realm = $realm;
        }
    }

    public function getEventEmitter() {
        return $this->eventEmitter;
    }

    private function checkAuthByTCalendarToken($token) {
        $url = $this->apiroot . '/api/technicalToken/introspect';
        $headers = ['X-TECHNICAL-TOKEN' => $token];
        $request = new HTTP\Request('GET', $url, $headers);
        return $this->decodeResponse($this->httpClient->send($request));
    }

    # <Added by xguimard>
    #  * copied from \Sabre\DAV\Auth\Backend\AbstractBasic
    #  * changes:
    #    + get mail from validateUserPass instead of using $userpass[0]
    function checkBasicAuth(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) {
        $auth = new HTTP\Auth\Basic(
            $this->realm,
            $request,
            $response
        );

        $userpass = $auth->getCredentials();

        if (!$userpass) {
            return [false, "No 'Authorization: Basic' header found. Either the client didn't send one, or the server is misconfigured"];
        }

        $user = trim($userpass[0]);

        list($result, $mail) = $this->validateUserPass($user, $userpass[1]);
        if (!$result) {
            return [false, "Username or password was incorrect"];
        }
        return [true, $this->principalPrefix . $mail];

    }
    # </Added>

    private function decodeResponse($response) {
        if ($response->getStatus() != 200) {
            return [false, null];
        }

        $user = json_decode($response->getBodyAsString());
        if (!$user) {
            return [false, null];
        }

        $type = property_exists($user, 'user_type') ? $user->user_type : 'user';
        $this->currentUserId = $user->_id;
        if (isset($user->domain)) {
            if (!filter_var($user->domain, FILTER_VALIDATE_DOMAIN)) {
                error_log("decodeResponse: invalid domain '$user->domain' for user '$user->_id'");
                return [false, null];
            }
        } else if (isset($user->email)) {
            if (!filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                error_log("decodeResponse: invalid email '$user->email' for user '$user->_id'");
                return [false, null];
            }
        } else {
            error_log("decodeResponse: no email and no domain property for user '$user->_id'");
        }
        return [true, $type];
    }

    protected function validateUserPass($username, $password) {
        $user = trim($username);
        if ($this->impersonationEnabled()) {
            $impersonationResult = $this->attemptAdminImpersonation($user, $password);
            if ($impersonationResult !== null) {
                [$success, $value] = $impersonationResult;
                if (!$success) {
                    return [false, $value];
                }
                $principalId = $this->principalBackend->getPrincipalIdByEmail($value);
                if (!$principalId) {
                    $principalId = $this->principalBackend->getPrincipalIdByResourceEmail($value);
                    if (!$principalId) {
                        error_log("User not found for email: $value");
                        return [false, "User not found"];
                    }
                    $this->currentPrincipalPrefix = 'principals/resources/';
                } else {
                    $this->currentPrincipalPrefix = 'principals/users/';
                }
                $this->currentUserId = $principalId;
                return [true, $value];
            }
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
            return [false, "Unable to connect to LDAP server"];
        }
        try {
            ldap_set_option($ldapCon, LDAP_OPT_PROTOCOL_VERSION, 3);
            ldap_set_option($ldapCon, LDAP_OPT_REFERRALS, 0);

            # Try to authenticate
            $safeUser = ldap_escape($user, '', 0);

            try {
                $ldapBind = ldap_bind($ldapCon, "uid=$safeUser," . LDAP_BASE, $password);
                if (!$ldapBind) {
                    error_log("Bad credentials for $user");
                    return [false, "Bad credentials"];
                }
            } catch (\ErrorException $e) {
                error_log("LDAP bind user failed for $user: " . $e->getMessage());
                return [false, "Bad credentials"];
            }

            $ldapBind2 = ldap_bind($ldapCon, LDAP_ADMIN_DN, LDAP_ADMIN_PASSWORD);

            if (!$ldapBind2) {
                error_log("Bad admin credentials");
                return [false, "Bad admin credentials"];
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
            return [false, "Unable to find $username which is valid for auth"];
        }
        if ($entries['count'] > 1) {
            error_log("More than one entry for $user");
        }
        if (!$entries[0]['mail']) {
            error_log("$user has no mail attribute");
            return [false, "$user has no mail attribute"];
        }
        $mail = $entries[0]['mail'][0];
        if(!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            error_log("validateUserPass: $user has incorrect mail attribute $mail");
            return [false, "$user has incorrect mail attribute"];
        }

        $principalId = $this->principalBackend->getPrincipalIdByEmail($mail);
        if (!$principalId) {
            error_log("User not found for email: $mail");
            return [false, "User not found"];
        }
        $this->currentUserId = $principalId;
        return [true, $mail];
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

    private function attemptAdminImpersonation(string $username, string $password): ?array {
        $adminCredential = $this->getAdminCredential();
        if ($adminCredential === null) {
            return null;
        }

        [$adminLogin, $adminPassword] = $adminCredential;

        $adminPrefix = $adminLogin . '&';
        if (!str_starts_with($username, $adminPrefix)) {
            return null;
        }

        if ($password !== $adminPassword) {
            error_log('Bad admin password.');
            return [false, 'Bad admin password'];
        }

        $impersonatedEmail = substr($username, strlen($adminPrefix));
        return [true, $impersonatedEmail];
    }

    function getCurrentPrincipal() {
        $id = $this->currentUserId;
        return $id ? $this->currentPrincipalPrefix . $id : null;
    }

    private function checkSuccess(string $type) {
        $this->eventEmitter->emit("auth:success", [$this->getCurrentPrincipal()]);
        $msg = ($type == $this->technicalUserType) ? $this->technicalPrincipal : $this->getCurrentPrincipal();
        return [true, $msg];
    }

    function check(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) {
        try {
            $authorizationHeader = $request->getHeader("Authorization");
            $tCalendarToken = $request->getHeader("TwakeCalendarToken");
            $type = '';
            $msg = '';
            $rv = false;

            if ($authorizationHeader) {
                try {
                    $this->checkJWT($authorizationHeader);
                    return $this->checkSuccess('user');
                } catch(AuthException $e) {
                    // fallback to other authentification
                }
            }
            if ($tCalendarToken) {
                list($rv, $type) = $this->checkAuthByTCalendarToken($tCalendarToken);
                $msg = "Invalid Token";
            } else {
                list($rv, $msg) = $this->checkBasicAuth($request, $response);
            }
            if($rv === false)
                throw new AuthException($msg);
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
        return $this->checkSuccess($type);
    }

    /*
     * @throw ESN\DAV\Auth\Backend\AuthException in case of authentification failure
     */
    private function checkJWT($authorizationHeader) {
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
                // we set the userId to be used as the current principle
                $this->currentUserId = $principleId;
                return true;
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
}
