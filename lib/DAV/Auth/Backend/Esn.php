<?php

namespace ESN\DAV\Auth\Backend;

use \Sabre\DAV;
use \Sabre\HTTP;
use Sabre\Event\EventEmitter;
use \Firebase\JWT\JWT;

define('ESN_PUBLIC_KEY', __DIR__ . '/../../../../config/esn.key.pub');

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
        $url = $this->apiroot . '/api/login';
        $headers = [ 'Content-Type' => 'application/json' ];
        $body = json_encode([
            'username' => $username,
            'password' => $password
        ]);
        $request = new HTTP\Request('POST', $url, $headers, $body);
        list($response, $type) = $this->decodeResponse($this->httpClient->send($request));
        return $response;
    }

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
            list($rv, $msg) = parent::check($request, $response);
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
