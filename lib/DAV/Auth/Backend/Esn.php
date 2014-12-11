<?php

namespace ESN\DAV\Auth\Backend;

use \Sabre\DAV;
use \Sabre\HTTP;

class Esn extends \Sabre\DAV\Auth\Backend\AbstractBasic {

    protected $httpClient;
    protected $currentUserId;
    protected $apiroot;

    private $lastConnectCookies;

    protected $principalPrefix = 'principals/users/';

    function __construct($apiroot) {
        $this->apiroot = $apiroot;
        $this->httpClient = new HTTP\Client();
    }

    private function checkAuthByToken($token) {
        $url = $this->apiroot . '/authenticationtoken/' . $token . '/user';
        $request = new HTTP\Request('GET', $url);
        return $this->decodeResponse($this->httpClient->send($request));
    }

    private function decodeResponse($response) {
        if ($response->getStatus() != 200) {
            return false;
        }

        $user = json_decode($response->getBodyAsString());
        if (!$user) {
            return false;
        }

        $cookies = $response->getHeaderAsArray('Set-Cookie');
        if (count($cookies) > 0) {
            $this->lastConnectCookies = $cookies[0];
        }

        $this->currentUserId = $user->_id;
        return true;
    }

    protected function validateUserPass($username, $password) {
        $user = trim($username);
        $url = $this->apiroot . '/login';
        $headers = [ 'Content-Type' => 'application/json' ];
        $body = json_encode([
            'username' => $username,
            'password' => $password
        ]);
        $request = new HTTP\Request('POST', $url, $headers, $body);
        return $this->decodeResponse($this->httpClient->send($request));
    }

    function getCurrentPrincipal() {
        return "principals/users/" . $this->currentUserId;
    }

    function getAuthCookies() {
        return $this->lastConnectCookies;
    }

    function check(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) {
        $auth = $request->getHeader("ESNToken");
        if ($auth) {
            $rv = $this->checkAuthByToken($auth);
            $msg = "Invalid Token";
        } else {
            list($rv, $msg)  = parent::check($request, $response);
        }

        if ($rv) {
            $msg = $this->getCurrentPrincipal();
        }

        return [$rv, $msg];
    }
}
