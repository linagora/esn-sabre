<?php

namespace ESN\DAV;

#[\AllowDynamicProperties]
class XmlBodyDeserializationPluginTest extends \PHPUnit\Framework\TestCase {

    function setUp(): void {
        // As esn.php does: deserialiser warnings become ErrorExceptions.
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
        });
    }

    function tearDown(): void {
        restore_error_handler();
    }

    private function prepareServer() {
        $server = new \Sabre\DAV\Server([]);
        $server->sapi = new \Sabre\HTTP\SapiMock();
        $server->addPlugin(new \Sabre\CalDAV\Plugin());
        $server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
        $server->addPlugin(new \Sabre\CalDAV\SharingPlugin());
        $server->addPlugin(new XmlBodyDeserializationPlugin());

        return $server;
    }

    private function request($method, $body, $contentType = 'application/xml') {
        $server = $this->prepareServer();
        $request = new \Sabre\HTTP\Request($method, '/new', ['Content-Type' => $contentType], $body);
        $server->httpRequest = $request;
        $server->exec();

        return $server->httpResponse;
    }

    private function assertBadRequest($response) {
        $this->assertEquals(400, $response->getStatus());
        $this->assertStringContainsString('Sabre\DAV\Exception\BadRequest', $response->getBodyAsString());
    }

    function testMkcolWithShareMissingHrefShouldAnswerBadRequest() {
        $this->assertBadRequest($this->request('MKCOL',
            '<CS:share xmlns:D="DAV:" xmlns:CS="http://calendarserver.org/ns/"><CS:set><CS:read-write/></CS:set></CS:share>'));
    }

    function testPostWithShareMissingHrefShouldAnswerBadRequest() {
        $this->assertBadRequest($this->request('POST',
            '<CS:share xmlns:D="DAV:" xmlns:CS="http://calendarserver.org/ns/"><CS:set><CS:read-write/></CS:set></CS:share>'));
    }

    function testMkcalendarWithSetMissingPropShouldAnswerBadRequest() {
        $this->assertBadRequest($this->request('MKCALENDAR',
            '<C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:set/></C:mkcalendar>'));
    }

    function testMkcalendarWithNamelessCompShouldAnswerBadRequest() {
        $this->assertBadRequest($this->request('MKCALENDAR',
            '<C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:set><D:prop>' .
            '<C:supported-calendar-component-set><C:comp/></C:supported-calendar-component-set>' .
            '</D:prop></D:set></C:mkcalendar>'));
    }

    function testMkcalendarWithTextualComponentSetShouldAnswerBadRequest() {
        $this->assertBadRequest($this->request('MKCALENDAR',
            '<C:mkcalendar xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:set><D:prop>' .
            '<C:supported-calendar-component-set>text</C:supported-calendar-component-set>' .
            '</D:prop></D:set></C:mkcalendar>'));
    }

    function testShareResourceWithTextualShareAccessShouldAnswerBadRequest() {
        $this->assertBadRequest($this->request('POST',
            '<D:share-resource xmlns:D="DAV:"><D:sharee><D:href>mailto:a@b.c</D:href>' .
            '<D:share-access>text</D:share-access></D:sharee></D:share-resource>',
            'application/davsharing+xml'));
    }

    function testAclWithNamelessCompFilterShouldAnswerBadRequest() {
        $this->assertBadRequest($this->request('ACL',
            '<C:calendar-query xmlns:C="urn:ietf:params:xml:ns:caldav"><C:filter><C:comp-filter/></C:filter></C:calendar-query>'));
    }

    function testMalformedXmlIsLeftToSabreHandlers() {
        $response = $this->request('MKCOL', '<D:mkcol xmlns:D="DAV:">');

        // Sabre's MKCOL handler maps the parse error itself.
        $this->assertEquals(400, $response->getStatus());
        $this->assertStringNotContainsString('could not be deserialised', $response->getBodyAsString());
    }

    function testNonXmlPostBodyIsNotDeserialised() {
        $response = $this->request('POST', '<C:calendar-query xmlns:C="urn:ietf:params:xml:ns:caldav"><C:filter><C:comp-filter/></C:filter></C:calendar-query>',
            'application/json');

        $this->assertNotEquals(400, $response->getStatus());
    }

    function testValidMkcolBodyReachesSabreHandler() {
        $response = $this->request('MKCOL',
            '<D:mkcol xmlns:D="DAV:"><D:set><D:prop><D:resourcetype><D:collection/></D:resourcetype></D:prop></D:set></D:mkcol>');

        // Sabre's handler ran: the in-memory root collection refuses new children.
        $this->assertEquals(403, $response->getStatus());
    }
}
