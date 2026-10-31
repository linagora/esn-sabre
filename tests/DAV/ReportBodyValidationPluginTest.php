<?php

namespace ESN\DAV;

#[\AllowDynamicProperties]
class ReportBodyValidationPluginTest extends \PHPUnit\Framework\TestCase {

    private function prepareServer() {
        $server = new \Sabre\DAV\Server([]);
        $server->sapi = new \Sabre\HTTP\SapiMock();
        $server->xml->elementMap['{urn:ietf:params:xml:ns:caldav}calendar-query'] = \Sabre\CalDAV\Xml\Request\CalendarQueryReport::class;
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

    private function calendarQuery($compFilterAttributes, $timeRange) {
        return '<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
            . '<D:prop><D:getetag/></D:prop><C:filter><C:comp-filter ' . $compFilterAttributes . '>'
            . '<C:comp-filter name="VEVENT">' . $timeRange . '</C:comp-filter>'
            . '</C:comp-filter></C:filter></C:calendar-query>';
    }

    /**
     * Runs the request with PHP errors mapped to ErrorException, as esn.php does.
     */
    private function requestWithErrorsAsExceptions($method, $body, array $headers = []) {
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
        try {
            return $this->request($method, $body, $headers);
        } finally {
            restore_error_handler();
        }
    }

    function testInvalidTimeRangeDateShouldAnswerBadRequest() {
        $body = $this->calendarQuery('name="VCALENDAR"', '<C:time-range start="203501010T00Z"/>');

        $response = $this->request('REPORT', $body, ['Depth' => '1']);

        $this->assertEquals(400, $response->getStatus());
        $this->assertStringContainsString('Sabre\DAV\Exception\BadRequest', $response->getBodyAsString());
    }

    function testCompFilterWithoutNameShouldAnswerBadRequest() {
        $body = $this->calendarQuery('nme="VCALENDAR"', '<C:time-range start="20350101T000000Z"/>');

        $response = $this->requestWithErrorsAsExceptions('REPORT', $body, ['Depth' => '1']);

        $this->assertEquals(400, $response->getStatus());
        $this->assertStringContainsString('Sabre\DAV\Exception\BadRequest', $response->getBodyAsString());
    }

    function testValidCalendarQueryShouldStillReachReportHandlers() {
        $body = $this->calendarQuery('name="VCALENDAR"', '<C:time-range start="20350101T000000Z"/>');

        $response = $this->request('REPORT', $body, ['Depth' => '1']);

        $this->assertEquals(415, $response->getStatus());
        $this->assertStringContainsString('ReportNotSupported', $response->getBodyAsString());
    }
}
