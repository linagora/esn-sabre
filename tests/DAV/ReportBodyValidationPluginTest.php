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
        $server->xml->elementMap['{urn:ietf:params:xml:ns:caldav}calendar-query'] = \Sabre\CalDAV\Xml\Request\CalendarQueryReport::class;
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

    /**
     * XMLReader accepts U+FEFF inside an element name but XMLWriter refuses it.
     * Sabre echoes unknown requested properties in the multistatus, so writing
     * that name used to throw a ValueError (500).
     */
    function testPropfindWithBomInPropertyNameShouldAnswerBadRequest() {
        $body = "<D:propfind xmlns:D=\"DAV:\"><D:prop><D:a\u{FEFF}b/></D:prop></D:propfind>";
        $response = $this->request('PROPFIND', $body, ['Depth' => '0']);

        $this->assertEquals(400, $response->getStatus());
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

    function testProppatchWithBomInPropertyNameShouldAnswerBadRequest() {
        $body = "<D:propertyupdate xmlns:D=\"DAV:\"><D:set><D:prop><D:a\u{FEFF}b>x</D:a\u{FEFF}b></D:prop></D:set></D:propertyupdate>";
        $response = $this->request('PROPPATCH', $body);

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
