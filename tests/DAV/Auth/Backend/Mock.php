<?php

declare(strict_types=1);

namespace ESN\DAV\Auth\Backend;

use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;

use \ESN\DAV\Auth\Backend\AuthTenant;

class Mock implements \Sabre\DAV\Auth\Backend\BackendInterface
{
    public $fail = false;
    protected $apiroot;
    protected $principalBackend;
    protected $server;


    public $invalidCheckResponse = false;

    public $principal = 'principals/admin';
    public $tenant = null;

    function __construct($apiroot, ?string $realm = null, $principalBackend, $server) {
        $this->apiroot = $apiroot;
        $this->principalBackend = $principalBackend;
        $this->server = $server;

        if (!is_null($realm)) {
            $this->realm = $realm;
        }
        $this->tenant = new AuthTenant('admin');
        $this->principal = (string) $this->tenant->getPrincipal();
    }

    public function setPrincipal($principal)
    {
        $this->principal = $principal;
    }

    public function setAuthTenant($tenant)
    {
       $this->tenant = $tenant;
       $this->principal = (string) $tenant->getPrincipal();
       $this->server->emit('auth:success',[$tenant]);
    }
    /**
     * When this method is called, the backend must check if authentication was
     * successful.
     *
     * The returned value must be one of the following
     *
     * [true, "principals/username"]
     * [false, "reason for failure"]
     *
     * If authentication was successful, it's expected that the authentication
     * backend returns a so-called principal url.
     *
     * Examples of a principal url:
     *
     * principals/admin
     * principals/user1
     * principals/users/joe
     * principals/uid/123457
     *
     * If you don't use WebDAV ACL (RFC3744) we recommend that you simply
     * return a string such as:
     *
     * principals/users/[username]
     *
     * @return array
     */
    public function check(RequestInterface $request, ResponseInterface $response)
    {
        if ($this->invalidCheckResponse) {
            return 'incorrect!';
        }
        if ($this->fail) {
            return [false, 'fail!'];
        }

        $this->server->emit('auth:success',[$this->tenant]);
        return [true, $this->principal];
    }

    /**
     * This method is called when a user could not be authenticated, and
     * authentication was required for the current request.
     *
     * This gives you the oppurtunity to set authentication headers. The 401
     * status code will already be set.
     *
     * In this case of Basic Auth, this would for example mean that the
     * following header needs to be set:
     *
     * $response->addHeader('WWW-Authenticate', 'Basic realm=SabreDAV');
     *
     * Keep in mind that in the case of multiple authentication backends, other
     * WWW-Authenticate headers may already have been set, and you'll want to
     * append your own WWW-Authenticate header instead of overwriting the
     * existing one.
     */
    public function challenge(RequestInterface $request, ResponseInterface $response)
    {
    }
}
