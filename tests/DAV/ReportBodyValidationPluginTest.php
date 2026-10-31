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
     * MKCOL, MKCALENDAR, ACL and XML POST bodies go through the same deserialisers.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('xmlBodyMethods')]
    function testIllFormedBodyIsRejectedForEveryXmlMethod($method, $fixture, array $headers) {
        $body = file_get_contents(__DIR__ . '/fixtures/' . $fixture);

        $start = microtime(true);
        $response = $this->request($method, $body, $headers);

        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertEquals(400, $response->getStatus());
    }

    static function xmlBodyMethods() {
        $methods = [];
        foreach (['report-keyvalue-loop.xml', 'mkcol-prop-loop.xml'] as $fixture) {
            $methods += [
                "MKCOL $fixture" => ['MKCOL', $fixture, []],
                "MKCOL text/xml $fixture" => ['MKCOL', $fixture, ['Content-Type' => 'text/xml; charset=utf-8']],
                "MKCALENDAR $fixture" => ['MKCALENDAR', $fixture, []],
                "MKCALENDAR without Content-Type $fixture" => ['MKCALENDAR', $fixture, ['Content-Type' => 'text/plain']],
                "ACL $fixture" => ['ACL', $fixture, []],
                "POST $fixture" => ['POST', $fixture, []],
                "POST davsharing $fixture" => ['POST', $fixture, ['Content-Type' => 'application/davsharing+xml']]
            ];
        }

        return $methods;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonXmlBodies')]
    function testNonXmlBodiesAreLeftToTheirHandlers($method, $contentType) {
        $response = $this->request($method, '{"id":"not xml"}', ['Content-Type' => $contentType]);

        $this->assertNotEquals(400, $response->getStatus());
    }

    static function nonXmlBodies() {
        return [
            'JSON POST' => ['POST', 'application/json'],
            'iCalendar POST' => ['POST', 'text/calendar'],
            'JSON MKCOL' => ['MKCOL', 'application/json']
        ];
    }

    function testIllFormedBodyIsRejectedThroughMethodOverride() {
        $server = $this->prepareServer();
        $server->addPlugin(new XHttpMethodOverridePlugin());
        $server->httpRequest = new \Sabre\HTTP\Request('POST', '/', [
            'Content-Type' => 'text/plain',
            'X-Http-Method-Override' => 'ACL'
        ], file_get_contents(__DIR__ . '/fixtures/report-keyvalue-loop.xml'));
        $server->exec();

        $this->assertEquals(400, $server->httpResponse->getStatus());
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
