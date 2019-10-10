<?php

namespace ESN\DAVACL;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class PluginTest extends \ESN\DAV\ServerMock {
    function testPROPFINDPrincipal() {
        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD'    => 'PROPFIND',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'       => 'application/json',
            'REQUEST_URI'       => '/principals/users/54b64eadf6d7d8e41d263e0f',
        ));

        $response = $this->request($request);
        $jsonResponse = json_decode($response->getBodyAsString(), true);

        $this->assertEquals(200, $response->status);
        $this->assertEquals($jsonResponse, [
          'alternate-URI-set' => [
            'mailto:robertocarlos@realmadrid.com'
          ],
          'principal-URL' => 'principals/users/54b64eadf6d7d8e41d263e0f/',
          'group-member-set' => [],
          'group-membership' => []
        ]);
    }
}