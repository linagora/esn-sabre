<?php

namespace ESN\DAV\Auth\Backend;

use \Sabre\DAV;
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
define('OPENPASS_BASIC_AUTH', getenv("OPENPASS_BASIC_AUTH"));
define('SABRE_ADMIN_LOGIN', getenv("SABRE_ADMIN_LOGIN"));
define('SABRE_ADMIN_PASSWORD', getenv("SABRE_ADMIN_PASSWORD"));

class Esn extends \Sabre\DAV\Auth\Backend\AbstractBasic {

    protected $httpClient;
    protected $currentUserId;
    protected $apiroot;

    protected $principalPrefix = 'principals/users/';
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

    private function checkAuthByToken($token) {
        $url = $this->apiroot . '/api/authenticationtoken/' . $token . '/user';
        $request = new HTTP\Request('GET', $url);
        return $this->decodeResponse($this->httpClient->send($request));
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
        $myObjDump = print_r($userpass, true);

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

    private function decodeResponseV2($response) {
        if ($response->getStatus() != 200) {
            return [false, null];
        }

        $decoded = json_decode($response->getBodyAsString());
        if (!$decoded || !is_array($decoded) || count($decoded) === 0) {
            return [false, null];
        }

        $user = $decoded[0];

        if (!$user) {
            return [false, null];
        }


        $type = property_exists($user, 'user_type') ? $user->user_type : 'user';
        $this->currentUserId = $user->_id;
        return [true, $type];
    }

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
        return [true, $type];
    }

    protected function validateUserPass($username, $password) {
        $user = trim($username);

        $adminPrefix = SABRE_ADMIN_LOGIN . '&';
        if (strlen(SABRE_ADMIN_LOGIN) > 0 && strpos($user, $adminPrefix) === 0) {
            $requestedUsername = substr($user, strlen($adminPrefix));
            if ($password != SABRE_ADMIN_PASSWORD) {
                error_log('Bad admin password.');
                return [false, "Bad admin password"];
            }

            # Get OpenPaaS id
            $url = $this->apiroot . "/api/users?email=$requestedUsername";
            $headers = [ 'Accept' => 'application/json', 'Authorization' => 'Basic ' . OPENPASS_BASIC_AUTH ];
            $request = new HTTP\Request('GET', $url, $headers);
            list($response, $type) = $this->decodeResponseV2($this->httpClient->send($request));

            return [$response, $requestedUsername];
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
        ldap_close($ldapCon);

        # Get OpenPaaS id
        $url = $this->apiroot . "/api/users?email=$mail";
        $headers = [ 'Accept' => 'application/json', 'Authorization' => 'Basic ' . OPENPASS_BASIC_AUTH ];
        $request = new HTTP\Request('GET', $url, $headers);
        list($response, $type) = $this->decodeResponseV2($this->httpClient->send($request));

        return [$response, $mail];
    }
    # </Modified>

    function getCurrentPrincipal() {
        $id = $this->currentUserId;
        return $id ? "principals/users/" . $id : null;
    }

    function check(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) {
        $authorizationHeader = $request->getHeader("Authorization");
        $esnToken = $request->getHeader("ESNToken");
        $tCalendarToken = $request->getHeader("TwakeCalendarToken");
        $type = '';

        if ($authorizationHeader && $this->checkJWT($authorizationHeader)) {
            $rv = true;
            $msg = 'jwt';
            $type = 'user';
        }
        elseif ($esnToken) {
            list($rv, $type) = $this->checkAuthByToken($esnToken);
            $msg = "Invalid Token";
        } elseif ($tCalendarToken) {
            list($rv, $type) = $this->checkAuthByTCalendarToken($tCalendarToken);
            $msg = "Invalid Token";
        } else {
            list($rv, $msg) = $this->checkBasicAuth($request, $response);
        }

        if ($rv) {
            $this->eventEmitter->emit("auth:success", [$this->getCurrentPrincipal()]);
            $msg = ($type == $this->technicalUserType) ? $this->technicalPrincipal : $this->getCurrentPrincipal();
        }

        return [$rv, $msg];
    }

    private function checkJWT($authorizationHeader) {
        // No public key = no jwt
        if (!file_exists(ESN_PUBLIC_KEY)) return false;

        if (preg_match('/Bearer\s((.*)\.(.*)\.(.*))/', $authorizationHeader, $matches)) {
            $token = $matches[1];
            // Load esn's public key
            $key = file_get_contents(ESN_PUBLIC_KEY);

            try {
                // Try to decode the token with the public key
                $user = JWT::decode($token, new Key($key, 'RS256'));

                // Get the user Id associated with the identifier of the token ( email in sub field )
                $principleId = $this->principalBackend->getPrincipalIdByEmail($user->sub);
                // No user found by that email
                if (!$principleId) return false;
                // we set the userId to be used as the current principle
                $this->currentUserId = $principleId;
                
                return true;
            } catch(\exception $e) {
                // something wrong happened during decoding the JWT
                // things like unsupported algorithm, expired token...
                $this->server->getLogger()->error(
                    'An unexpected error happened when decoding the JWT',
                    ['error' => $e->getMessage()]
                );
                return false;
            }
        }
        // the JWT format is weird
        return false;
    }
}
