<?php

namespace ESN\DAV;

use PHPUnit\Framework\TestCase;
use Sabre\HTTP\Request;
use Sabre\HTTP\Response;

class NoRedirectHttpClientTest extends TestCase {
    function testRedirectIsReturnedInsteadOfFollowed() {
        $client = new class extends NoRedirectHttpClient {
            public array $sent = [];

            protected function doRequest(\Sabre\HTTP\RequestInterface $request): \Sabre\HTTP\ResponseInterface {
                $this->sent[] = $request->getUrl();
                return new Response(302, ['Location' => 'http://attacker.example/steal']);
            }
        };

        $response = $client->send(new Request('GET', 'https://api.example/api/technicalToken/introspect', ['X-TECHNICAL-TOKEN' => 'secret']));

        $this->assertSame(302, $response->getStatus());
        $this->assertSame(['https://api.example/api/technicalToken/introspect'], $client->sent);
    }

    function testKeepsTheSeparateHeaderCallbackOfTheParentClient() {
        $settings = (new \ReflectionProperty(\Sabre\HTTP\Client::class, 'curlSettings'))->getValue(new NoRedirectHttpClient());

        $this->assertArrayNotHasKey(CURLOPT_HEADER, $settings);
        $this->assertArrayHasKey(CURLOPT_HEADERFUNCTION, $settings);
    }

    function testIntrospectionClientDoesNotFollowRedirects() {
        $backend = (new \ReflectionClass(\ESN\DAV\Auth\Backend\Esn::class))->newInstanceWithoutConstructor();
        $backend->__construct('http://api.example', null, null, null);

        $this->assertInstanceOf(NoRedirectHttpClient::class, (new \ReflectionProperty($backend, 'httpClient'))->getValue($backend));
    }
}
