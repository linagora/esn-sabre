<?php

namespace ESN\DAV;

#[\AllowDynamicProperties]
class ReportBodyValidationPluginTest extends \PHPUnit\Framework\TestCase {

    private function prepareServer() {
        $server = new \Sabre\DAV\Server([]);
        $server->sapi = new \Sabre\HTTP\SapiMock();
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

    /**
     * The keyValue loop is also reachable through `{DAV:}set/{DAV:}prop` of an
     * extended MKCOL, which ESN's calendar subscription plugin parses itself on
     * `beforeMethod:MKCOL` (priority 30): the pre-check must run before it.
     */
    function testMalformedMkcolBodyShouldAnswerBadRequestBeforeOtherParsers() {
        $server = $this->prepareServer();
        $parsed = false;
        $server->on('beforeMethod:MKCOL', function() use (&$parsed) {
            $parsed = true;
        }, 30);
        $server->httpRequest = new \Sabre\HTTP\Request('MKCOL', '/new', ['Content-Type' => 'application/xml'],
            '<D:mkcol xmlns:D="DAV:"><D:set><D:prop><D:a><D:b><D:prop><D:getetag/></D6prop>');

        $start = microtime(true);
        $server->exec();
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.0, $elapsed);
        $this->assertEquals(400, $server->httpResponse->getStatus());
        $this->assertFalse($parsed);
    }

    function testMalformedShareResourcePostShouldAnswerBadRequest() {
        $response = $this->request('POST', '<D:share-resource xmlns:D="DAV:"><D:sharee><D:a><D:b><D:prop><D:getetag/></D6prop>',
            ['Content-Type' => 'application/davsharing+xml']);

        $this->assertEquals(400, $response->getStatus());
    }

    function testMalformedLockBodyShouldAnswerBadRequest() {
        $response = $this->request('LOCK', '<D:lockinfo xmlns:D="DAV:"><D:a><D:b><D:prop><D:getetag/></D6prop>');

        $this->assertEquals(400, $response->getStatus());
    }

    function testMalformedAclBodyShouldAnswerBadRequest() {
        $response = $this->request('ACL', '<D:acl xmlns:D="DAV:"><D:ace><D:a><D:b><D:prop><D:getetag/></D6prop>');

        $this->assertEquals(400, $response->getStatus());
    }

    function testNonXmlPostBodyShouldNotBeChecked() {
        $response = $this->request('POST', '{"not": "xml"', ['Content-Type' => 'application/json']);

        $this->assertNotEquals(400, $response->getStatus());
    }

    function testWellFormedPropfindBodyShouldStillBeHandled() {
        $response = $this->request('PROPFIND', '<?xml version="1.0"?><D:propfind xmlns:D="DAV:"><D:prop><D:getetag/></D:prop></D:propfind>', ['Depth' => '0']);

        $this->assertEquals(207, $response->getStatus());
    }

    function testEmptyPropfindBodyShouldStillMeanAllprop() {
        $response = $this->request('PROPFIND', '', ['Depth' => '0']);

        $this->assertEquals(207, $response->getStatus());
    }
}
