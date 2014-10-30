<?php

namespace ESN\DAV\Auth\Backend;

use Sabre\DAV;
use \Sabre\HTTP;

class Esn extends \Sabre\DAV\Auth\Backend\AbstractBasic {

    const AFTER_LOGIN_EVENT = 'afterLogin';

    protected $httpClient;
    protected $currentUserId;
    protected $apiroot;

    private $lastConnectCookies;

    function __construct($apiroot) {
        $this->apiroot = $apiroot;
        $this->httpClient = new HTTP\Client();
    }

    private function checkAuthByToken($username, $password) {
        $url = $this->apiroot . '/authenticationtoken/' . $password . '/user';
        $request = new HTTP\Request('GET', $url);
        return $this->decodeResponse($this->httpClient->send($request));
    }

    private function checkAuthByLoginPassword($username, $password) {
        $url = $this->apiroot . '/login';
        $headers = [ 'Content-Type' => 'application/json' ];
        $body = json_encode([
            'username' => $username,
            'password' => $password
        ]);
        $request = new HTTP\Request('POST', $url, $headers, $body);
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
        if ( $this->checkAuthByToken($user, $password) ) {
            return true;
        }
        if ( $this->checkAuthByLoginPassword($user, $password)) {
            return true;
        }
        return false;
    }

    function getCurrentUser() {
        return $this->currentUserId;
    }

    function getAuthCookies() {
        return $this->lastConnectCookies;
    }

    function authenticate(\Sabre\DAV\Server $server, $realm) {
        $rv = parent::authenticate($server, $realm);
        if ($this->lastConnectCookies) {
            $server->emit(self::AFTER_LOGIN_EVENT, [$this->lastConnectCookies]);
        }
        return $rv;
    }
}
