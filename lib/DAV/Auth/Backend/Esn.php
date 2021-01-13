<?php

namespace ESN\DAV\Auth\Backend;

use \Sabre\DAV;
use \Sabre\HTTP;
use Sabre\Event\EventEmitter;

class Esn extends \Sabre\DAV\Auth\Backend\AbstractBasic {

    protected $httpClient;
    protected $currentUserId;
    protected $apiroot;

    protected $principalPrefix = 'principals/users/';
    protected $technicalPrincipal = 'principals/technicalUser';
    protected $technicalUserType = 'technical';

    function __construct($apiroot, $realm = null) {
        $this->apiroot = $apiroot;
        $this->httpClient = new HTTP\Client();
        $this->eventEmitter = new EventEmitter();

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
        $auth = $request->getHeader("ESNToken");
        $type = '';
        if ($auth) {
            list($rv, $type) = $this->checkAuthByToken($auth);
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
}
