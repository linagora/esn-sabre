<?php

namespace ESN\DAV\Auth\Backend;

class Esn extends \Sabre\DAV\Auth\Backend\AbstractBasic {

    protected $currentUserId;
    protected $apiroot;

    function __construct($apiroot) {
        $this->apiroot = $apiroot;
    }
    
    private function initCurl($url) {
      $curl = curl_init();
      curl_setopt($curl, CURLOPT_URL, $url);
      curl_setopt($curl, CURLOPT_COOKIESESSION, true);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
      curl_setopt($curl, CURLOPT_TIMEOUT, 10);
      return $curl;
    }

    private function checkAuthByToken($username, $password) {
      $url = $this->apiroot . "/authenticationtoken/" . $password . "/user";
      $curl = $this->initCurl($url);
      $result = curl_exec($curl);
      $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      curl_close($curl);
      if ( $http_status != 200 ) {
        return false;
      }
      
      $user = null;
      
      try {
        $user = json_decode($result);
      } catch(Exception $e) {
        return false;
      }
      
      $emailMatch = in_array($username, $user->emails);
      if( !$emailMatch ) {
        return false;
      }
      
      $this->currentUserId = $user->_id;
      return true;
    }
    
    private function checkAuthByLoginPassword($username, $password) {
      $url = $this->apiroot."/login";
      $request_body = json_encode(
        array(
          "username" => $username,
          "password" => $password
        )
      );
      
      $curl = $this->initCurl($url);
      
      curl_setopt($curl, CURLOPT_POST, true);
      curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-type: application/json'));
      curl_setopt($curl, CURLOPT_POSTFIELDS, $request_body);
      
      $result = curl_exec($curl);
      $http_status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
      curl_close($curl);
      if ( $http_status != 200 ) {
        return false;
      }
      
      $user = null;
      
      try {
        $user = json_decode($result);
      } catch(Exception $e) {
        return false;
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
}
