<?php

namespace ESN\DAVACL\PrincipalBackend;

use \Sabre\HTTP;
use \ESN\Utils\Utils as Utils;

// This class will replace ESN\DAVACL\PrincipalBackend\Mongo when all functions call Rest API instead of direct access to ESN db
class EsnRequest extends Mongo {

    function __construct($db, $authBackend = null, $apiroot = null, HTTP\Client $httpClient = null) {
        parent::__construct($db);

        $this->authBackend = $authBackend;
        $this->apiroot = $apiroot;
        if ($httpClient) {
            $this->httpClient = $httpClient;
        } else {
            $this->httpClient = new HTTP\Client();
        }

        $this->apiEndpointMap = [
            'resources' => '/linagora.esn.resource/api/resources/'
        ];
    }

    function getClient() {
        return $this->httpClient;
    }

    function getPrincipalByPath($path) {
        list($root, $type, $id) = explode('/', $path);

        //Use Rest API if implemented, Esn DB if not
        if (isset($this->apiEndpointMap[$type])) {
            $url =  $this->apiroot . $this->apiEndpointMap[$type] . $id;
            $request = new \Sabre\HTTP\Request('GET', $url);
            $cookie = $this->authBackend->getAuthCookies();
            $request->setHeader('Cookie', $cookie);

            $response = $this->httpClient->send($request);

            $principal = $this->responseToResource($response, $type);
        } else {
            $principal = parent::getPrincipalByPath($path);
        }

        return $principal;
    }

    private function responseToResource($response, $type) {
        if ($response->getStatus() != 200) {
            return null;
        }

        $obj = json_decode($response->getBodyAsString(), true);
        if (!$obj) {
            return null;
        }

        switch ($type) {
            case 'resources':
                $displayname = "";
                if (isset($obj['name'])) {
                    $displayname = $obj['name'];
                }

                $principal = [
                    'id' => (string)$obj['_id'],
                    '{DAV:}displayname' => $displayname,
                    '{http://sabredav.org/ns}email-address' => $obj['_id'] . '@' . $obj['domain']['name']
                ];
        }

        $principal['uri'] = 'principals/' . $type . '/' . $obj['_id'];

        return $principal;
    }
}
