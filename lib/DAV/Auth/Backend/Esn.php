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

    function __construct($apiroot, $realm = null) {
        $this->apiroot = $apiroot;
        $this->httpClient = new HTTP\Client();

        if (!is_null($realm)) {
            $this->realm = $realm;
        }
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
            $cookies = self::parseCookie($cookies);
            $cookiestr = self::buildCookie($cookies);

            $this->lastConnectCookies = $cookiestr;
        }

        $this->currentUserId = $user->_id;
        return true;
    }

    private static function buildCookie($cookies) {
        $cookieval = [];
        foreach ($cookies as $k => $v) {
            $cookieval[] = $k . '=' . $v . '';
        }
        return implode('; ', $cookieval);
    }

    private static function parseCookie($headers) {
        $meta = array('domain', 'expires', 'path', 'secure', 'comment', 'httponly', 'max-age');
        $cookies = [];
        foreach ($headers as $header) {
            $parts = explode(';', $header);
            $cdata = array();
            foreach ($parts as $part) {
                $kv = array_map("trim", explode('=', $part, 2));
                if (!in_array(strtolower($kv[0]), $meta)) {
                    $cookies[$kv[0]] = $kv[1];
                }
            }
        }
        return $cookies;
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
        $id = $this->currentUserId;
        return $id ? "principals/users/" . $id : null;
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
            list($rv, $msg) = parent::check($request, $response);
        }

        if ($rv) {
            $msg = $this->getCurrentPrincipal();
        }

        return [$rv, $msg];
    }
}
