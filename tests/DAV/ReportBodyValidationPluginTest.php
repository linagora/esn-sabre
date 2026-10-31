<?php

namespace ESN\DAV;

#[\AllowDynamicProperties]
class ReportBodyValidationPluginTest extends \PHPUnit\Framework\TestCase {

    private function prepareServer() {
        // A root the authenticated user may change the ACL of, so that ACL
        // requests reach the body parsing.
        $root = new class('root') extends \Sabre\DAV\SimpleCollection implements \Sabre\DAVACL\IACL {
            use \Sabre\DAVACL\ACLTrait;

            function getACL() {
                return [['principal' => '{DAV:}authenticated', 'privilege' => '{DAV:}all', 'protected' => true]];
            }
        };
        $server = new \Sabre\DAV\Server($root);
        $server->sapi = new \Sabre\HTTP\SapiMock();
        $server->addPlugin(new \Sabre\DAV\Auth\Plugin(new class implements \Sabre\DAV\Auth\Backend\BackendInterface {
            function check(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) {
                return [true, 'principals/user1'];
            }

            function challenge(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) {
            }
        }));
        $server->addPlugin(new \Sabre\DAVACL\Plugin());
        $server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
        $server->addPlugin(new \Sabre\CalDAV\SharingPlugin());
        $server->addPlugin(new ReportBodyValidationPlugin());

        return $server;
    }

    private function request($method, $body, array $headers = []) {
        $server = $this->prepareServer();
        $headers = array_merge(['Content-Type' => 'application/xml'], $headers);
        $request = new \Sabre\HTTP\Request($method, '/', $headers, $body);
        $server->httpRequest = $request;
        $server->exec();

        return $server->httpResponse;
    }

    private function report($body) {
        return $this->request('REPORT', $body);
    }

    function testJsonBodyShouldAnswerBadRequest() {
        $response = $this->report('{"match":{"start":"20261001T000000","end":"20261101T000000"}}');

        $this->assertEquals(400, $response->getStatus());
        $this->assertStringContainsString('Sabre\DAV\Exception\BadRequest', $response->getBodyAsString());
    }

    function testTruncatedXmlBodyShouldAnswerBadRequest() {
        $response = $this->report('<x');

        $this->assertEquals(400, $response->getStatus());
    }

    function testEmptyBodyShouldAnswerBadRequest() {
        $response = $this->report('');

        $this->assertEquals(400, $response->getStatus());
    }

    function testWellFormedUnsupportedReportShouldStillAnswerReportNotSupported() {
        $response = $this->report('<?xml version="1.0"?><d:unknown-report xmlns:d="DAV:"/>');

        $this->assertEquals(415, $response->getStatus());
        $this->assertStringContainsString('ReportNotSupported', $response->getBodyAsString());
    }

    /**
     * A mismatched end tag after an empty element, nested inside a `{DAV:}prop`,
     * used to spin Sabre's deserialiser until max_execution_time instead of
     * raising a parse error. It must now answer 400 immediately.
     */
    function testIllFormedCalendarQueryDoesNotHang() {
        $body = file_get_contents(__DIR__ . '/fixtures/report-keyvalue-loop.xml');

        $start = microtime(true);
        $response = $this->request('REPORT', $body, ['Depth' => '1']);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.0, $elapsed);
        $this->assertEquals(400, $response->getStatus());
    }

    function testMalformedPropfindBodyShouldAnswerBadRequest() {
        $response = $this->request('PROPFIND', '<D:propfind xmlns:D="DAV:"><D:a><D:b><D:prop><D:getetag/></D6prop>', ['Depth' => '0']);

        $this->assertEquals(400, $response->getStatus());
    }

    function testMalformedProppatchBodyShouldAnswerBadRequest() {
        $response = $this->request('PROPPATCH', '<D:propertyupdate xmlns:D="DAV:"><D:a><D:b><D:prop><D:getetag/></D6prop>');

        $this->assertEquals(400, $response->getStatus());
    }

    function testWellFormedPropfindBodyShouldStillBeHandled() {
        $response = $this->request('PROPFIND', '<?xml version="1.0"?><D:propfind xmlns:D="DAV:"><D:prop><D:getetag/></D:prop></D:propfind>', ['Depth' => '0']);

        $this->assertEquals(207, $response->getStatus());
    }

    function testEmptyPropfindBodyShouldStillMeanAllprop() {
        $response = $this->request('PROPFIND', '', ['Depth' => '0']);

        $this->assertEquals(207, $response->getStatus());
    }

    function testAclWithWrongRootShouldAnswerBadRequest() {
        $response = $this->request('ACL', '<D:propfind xmlns:D="DAV:"/>');

        $this->assertEquals(400, $response->getStatus());
        $this->assertStringContainsString('Sabre\DAV\Exception\BadRequest', $response->getBodyAsString());
    }

    function testAclWithUndeclaredPrefixShouldAnswerBadRequest() {
        $response = $this->request('ACL', '<D:acl xmlns:D="DAV:"><X:ace/></D:acl>');

        $this->assertEquals(400, $response->getStatus());
    }

    function testAclWithNonUtf8BodyShouldAnswerBadRequest() {
        $response = $this->request('ACL', "<?xml version=\"1.0\" encoding=\"UTF-8\"?><D:acl xmlns:D=\"DAV:\">\xff\xfe</D:acl>");

        $this->assertEquals(400, $response->getStatus());
    }

    function testEmptyAclBodyShouldAnswerBadRequest() {
        $response = $this->request('ACL', '');

        $this->assertEquals(400, $response->getStatus());
    }

    function testEmptyXmlPostShouldAnswerBadRequest() {
        $response = $this->request('POST', '');

        $this->assertEquals(400, $response->getStatus());
        $this->assertStringContainsString('Sabre\DAV\Exception\BadRequest', $response->getBodyAsString());
    }

    function testXmlPostWithUndeclaredPrefixShouldAnswerBadRequest() {
        $response = $this->request('POST', '<CS:share xmlns:D="DAV:"><D:set/></CS:share>');

        $this->assertEquals(400, $response->getStatus());
    }

    function testTruncatedXmlPostShouldAnswerBadRequest() {
        $response = $this->request('POST', '<D:share-resource xmlns:D="DAV:"');

        $this->assertEquals(400, $response->getStatus());
    }

    function testWellFormedUnknownXmlPostShouldStillReachOtherHandlers() {
        $response = $this->request('POST', '<?xml version="1.0"?><D:unknown xmlns:D="DAV:"/>');

        $this->assertEquals(501, $response->getStatus());
    }

    function testNonXmlPostShouldNotBeValidated() {
        $response = $this->request('POST', '{not xml', ['Content-Type' => 'application/json']);

        $this->assertEquals(501, $response->getStatus());
    }
}
