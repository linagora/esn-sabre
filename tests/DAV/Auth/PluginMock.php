<?php

namespace ESN\DAV\Auth;

use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

class PluginMock extends \Sabre\DAV\Auth\Plugin {

    /**
     * Remove cached currentPrincipal to allow principal change
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @return bool
     */
    function beforeMethod(RequestInterface $request, ResponseInterface $response) {
        $this->currentPrincipal = null;

        return parent::beforeMethod($request, $response);
    }

}