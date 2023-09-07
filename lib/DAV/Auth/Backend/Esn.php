<?php

namespace ESN\DAV\Auth\Backend;

use \Sabre\DAV;
use \Sabre\HTTP;
use Sabre\Event\EventEmitter;
use \Firebase\JWT\JWT;

define('ESN_PUBLIC_KEY', __DIR__ . '/../../../../config/esn.key.pub');

define('LDAP_ADMIN_DN', getenv("LDAP_ADMIN_DN"));
define('LDAP_ADMIN_PASSWORD', getenv("LDAP_ADMIN_PASSWORD"));
define('LDAP_BASE', getenv("LDAP_BASE"));
define('LDAP_BASE_WITH_MAIL', getenv("LDAP_BASE_WITH_MAIL"));
define('LDAP_SERVER', getenv("LDAP_SERVER"));
define('OPENPASS_BASIC_AUTH', getenv("OPENPASS_BASIC_AUTH"));

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

        $username = $userpass[0];
        $ldapBase = LDAP_BASE;
        if (strpos($username, '@') != false) {
            $username = explode('@', $username)[0];
            $ldapBase = LDAP_BASE_WITH_MAIL;
        }

        list($result, $mail) = $this->validateUserPassLDAP($username, $ldapBase, $userpass[1]);
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

        $user = json_decode($response->getBodyAsString())[0];

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


    # <Modified by xguimard>
    protected function validateUserPassLDAP($username, $ldapBase, $password) {
        $user = trim($username);

        # Open LDAP connection
        $ldapCon = ldap_connect(LDAP_SERVER);
        if (!$ldapCon) {
            error_log('Unable to connect to LDAP server');
            return [false];
        }
        ldap_set_option($ldapCon, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldapCon, LDAP_OPT_REFERRALS, 0);

        # Try to authenticate
        $ldapBind = ldap_bind($ldapCon,  "uid=$username," . $ldapBase, $password);

        if (!$ldapBind) {
            error_log("Bad credentials");
            return [false];
        }

        $ldapBind2 = ldap_bind($ldapCon, LDAP_ADMIN_DN, LDAP_ADMIN_PASSWORD);

        if (!$ldapBind2) {
            error_log("Bad admin credentials");
            return [false];
        }

        # Get real mail
        $searchResult = ldap_search($ldapCon, $ldapBase, "(uid=$username)");
        $entries = ldap_get_entries($ldapCon, $searchResult);

        if ($entries['count'] == 0) {
            error_log("Unable to find $username which is valid for auth!");
            return [false];
        }
        if ($entries['count'] > 1) {
            error_log("More than one entry for $username");
        }
        if (!$entries[0]['mail']) {
            error_log("$username has no mail attribute");
            return [false];
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

    protected function validateUserPass($username, $password) {
        $user = trim($username);

        # Open LDAP connection
        $ldapCon = ldap_connect(LDAP_SERVER);
        if (!$ldapCon) {
            error_log('Unable to connect to LDAP server');
            return [false];
        }
        ldap_set_option($ldapCon, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($ldapCon, LDAP_OPT_REFERRALS, 0);

        # Try to authenticate
        $ldapBind = ldap_bind($ldapCon, "uid=$username," . LDAP_BASE, $password);

        if (!$ldapBind) {
            error_log("Bad credentials");
            return [false];
        }

        $ldapBind2 = ldap_bind($ldapCon, LDAP_ADMIN_DN, LDAP_ADMIN_PASSWORD);

        if (!$ldapBind2) {
            error_log("Bad admin credentials");
            return [false];
        }

        # Get real mail
        $searchResult = ldap_search($ldapCon, LDAP_BASE, "(uid=$username)");
        $entries = ldap_get_entries($ldapCon, $searchResult);

        if ($entries['count'] == 0) {
            error_log("Unable to find $username which is valid for auth!");
            return [false];
        }
        if ($entries['count'] > 1) {
            error_log("More than one entry for $username");
        }
        if (!$entries[0]['mail']) {
            error_log("$username has no mail attribute");
            return [false];
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
        $type = '';

        if ($authorizationHeader && $this->checkJWT($authorizationHeader)) {
            $rv = true;
            $msg = 'jwt';
            $type = 'user';
        }
        elseif ($esnToken) {
            list($rv, $type) = $this->checkAuthByToken($esnToken);
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
                $user = JWT::decode($token, $key, array('RS256'));

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
