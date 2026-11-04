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
        $server->xml->elementMap['{urn:ietf:params:xml:ns:caldav}mkcalendar'] = \Sabre\CalDAV\Xml\Request\MkCalendar::class;
        $server->xml->elementMap['{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set'] = \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet::class;
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
     * The supported-calendar-component-set deserialiser indexes the attributes
     * of its children, so a text child makes it throw a TypeError.
     */
    function testReportBodyRaisingTypeErrorAnswersBadRequest() {
        $response = $this->report('<C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
            . '<D:set><D:prop><C:supported-calendar-component-set>x</C:supported-calendar-component-set>'
            . '</D:prop></D:set></C:mkcalendar>');

        $this->assertEquals(400, $response->getStatus());
        $this->assertStringContainsString('Sabre\DAV\Exception\BadRequest', $response->getBodyAsString());
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
