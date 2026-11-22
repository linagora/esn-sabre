<?php

namespace ESN\DAV;

#[\AllowDynamicProperties]
class JsonRepresentationETagPluginTest extends \PHPUnit\Framework\TestCase {

    const VCARD = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:c1\r\nFN:John Doe\r\nEND:VCARD\r\n";
    const LAST_MODIFIED = 1767225600;
    const ICAL = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:test\r\nBEGIN:VEVENT\r\nUID:e1\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nSUMMARY:Meeting\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    private function prepareServer() {
        $server = new \Sabre\DAV\Server([
            new JsonRepresentationETagPluginTestFile('c1.vcf', self::VCARD, 'text/vcard'),
            new JsonRepresentationETagPluginTestFile('e1.ics', self::ICAL, 'text/calendar')
        ]);
        $server->sapi = new JsonRepresentationETagPluginTestSapiMock();
        $server->addPlugin(new \Sabre\CardDAV\Plugin());
        $server->addPlugin(new \Sabre\CalDAV\Plugin());
        $server->addPlugin(new JsonRepresentationETagPlugin());

        return $server;
    }

    private function request($method, $path, array $headers) {
        $server = $this->prepareServer();
        $request = new \Sabre\HTTP\Request($method, $path, $headers);
        $server->httpRequest = $request;
        $server->invokeMethod($request, $server->httpResponse);

        return $server->sapi->response;
    }

    private function storedETag($path) {
        return $this->request('GET', $path, ['Accept' => 'text/vcard'])->getHeader('ETag');
    }

    function testVCardKeepsTheStoredETag() {
        $response = $this->request('GET', '/c1.vcf', ['Accept' => 'text/vcard']);

        $this->assertEquals(200, $response->getStatus());
        $this->assertEquals('"' . sha1(self::VCARD) . '"', $response->getHeader('ETag'));
    }

    function testJCardHasItsOwnETag() {
        $response = $this->request('GET', '/c1.vcf', ['Accept' => 'application/vcard+json']);

        $this->assertEquals(200, $response->getStatus());
        $this->assertStringStartsWith('application/vcard+json', $response->getHeader('Content-Type'));
        $this->assertEquals('"' . sha1(self::VCARD) . '-json"', $response->getHeader('ETag'));
    }

    function testJCalHasItsOwnETag() {
        $response = $this->request('GET', '/e1.ics', ['Accept' => 'application/calendar+json']);

        $this->assertEquals(200, $response->getStatus());
        $this->assertStringStartsWith('application/calendar+json', $response->getHeader('Content-Type'));
        $this->assertEquals('"' . sha1(self::ICAL) . '-json"', $response->getHeader('ETag'));
    }

    function testJCardRequestWithTheVCardETagIsNotAnsweredNotModified() {
        $response = $this->request('GET', '/c1.vcf', [
            'Accept' => 'application/vcard+json',
            'If-None-Match' => $this->storedETag('/c1.vcf')
        ]);

        $this->assertEquals(200, $response->getStatus());
        $this->assertStringStartsWith('application/vcard+json', $response->getHeader('Content-Type'));
    }

    function testJCardRequestWithTheJCardETagIsAnsweredNotModified() {
        $response = $this->request('GET', '/c1.vcf', [
            'Accept' => 'application/vcard+json',
            'If-None-Match' => JsonRepresentationETagPlugin::toJsonETag($this->storedETag('/c1.vcf'))
        ]);

        $this->assertEquals(304, $response->getStatus());
    }

    function testJCardRequestMatchesTheJCardETagAmongOthers() {
        $response = $this->request('GET', '/c1.vcf', [
            'Accept' => 'application/vcard+json',
            'If-None-Match' => '"other", ' . JsonRepresentationETagPlugin::toJsonETag($this->storedETag('/c1.vcf'))
        ]);

        $this->assertEquals(304, $response->getStatus());
    }

    function testVCardRequestWithTheJCardETagIsNotAnsweredNotModified() {
        $response = $this->request('GET', '/c1.vcf', [
            'Accept' => 'text/vcard',
            'If-None-Match' => JsonRepresentationETagPlugin::toJsonETag($this->storedETag('/c1.vcf'))
        ]);

        $this->assertEquals(200, $response->getStatus());
        $this->assertStringStartsWith('text/vcard', $response->getHeader('Content-Type'));
    }

    function testVCardRequestWithTheVCardETagIsAnsweredNotModified() {
        $response = $this->request('GET', '/c1.vcf', [
            'Accept' => 'text/vcard',
            'If-None-Match' => $this->storedETag('/c1.vcf')
        ]);

        $this->assertEquals(304, $response->getStatus());
    }

    function testJCardHeadRequestWithTheJCardETagMatches() {
        $this->expectException(\Sabre\DAV\Exception\PreconditionFailed::class);

        $this->request('HEAD', '/c1.vcf', [
            'Accept' => 'application/vcard+json',
            'If-None-Match' => JsonRepresentationETagPlugin::toJsonETag($this->storedETag('/c1.vcf'))
        ]);
    }

    function testJCardNotModifiedResponseCarriesTheJCardETag() {
        $jCardETag = JsonRepresentationETagPlugin::toJsonETag($this->storedETag('/c1.vcf'));

        $response = $this->request('GET', '/c1.vcf', [
            'Accept' => 'application/vcard+json',
            'If-None-Match' => $jCardETag
        ]);

        $this->assertEquals(304, $response->getStatus());
        $this->assertEquals($jCardETag, $response->getHeader('ETag'));
    }

    function testJCalNotModifiedResponseCarriesTheJCalETag() {
        $jCalETag = JsonRepresentationETagPlugin::toJsonETag($this->storedETag('/e1.ics'));

        $response = $this->request('GET', '/e1.ics', [
            'Accept' => 'application/calendar+json',
            'If-None-Match' => $jCalETag
        ]);

        $this->assertEquals(304, $response->getStatus());
        $this->assertEquals($jCalETag, $response->getHeader('ETag'));
    }

    function testJCardRequestWithAnUnmatchedETagIgnoresIfModifiedSince() {
        $response = $this->request('GET', '/c1.vcf', [
            'Accept' => 'application/vcard+json',
            'If-None-Match' => '"other"',
            'If-Modified-Since' => \Sabre\HTTP\toDate(new \DateTime('@' . self::LAST_MODIFIED))
        ]);

        $this->assertEquals(200, $response->getStatus());
        $this->assertStringStartsWith('application/vcard+json', $response->getHeader('Content-Type'));
    }

    function testJCalRequestWithAnUnmatchedETagIgnoresIfModifiedSince() {
        $response = $this->request('GET', '/e1.ics', [
            'Accept' => 'application/calendar+json',
            'If-None-Match' => '"other"',
            'If-Modified-Since' => \Sabre\HTTP\toDate(new \DateTime('@' . self::LAST_MODIFIED))
        ]);

        $this->assertEquals(200, $response->getStatus());
        $this->assertStringStartsWith('application/calendar+json', $response->getHeader('Content-Type'));
    }

    function testVCardRequestWithAnUnmatchedETagIgnoresIfModifiedSince() {
        $response = $this->request('GET', '/c1.vcf', [
            'Accept' => 'text/vcard',
            'If-None-Match' => '"other"',
            'If-Modified-Since' => \Sabre\HTTP\toDate(new \DateTime('@' . self::LAST_MODIFIED))
        ]);

        $this->assertEquals(200, $response->getStatus());
    }

    function testRequestPreferringXVCardIsNegotiatedAsText() {
        $response = $this->request('GET', '/c1.vcf', [
            'Accept' => 'text/x-vcard, application/vcard+json;q=0.9',
            'If-None-Match' => $this->storedETag('/c1.vcf')
        ]);

        $this->assertEquals(304, $response->getStatus());
        $this->assertEquals($this->storedETag('/c1.vcf'), $response->getHeader('ETag'));
    }

    function testJCardRequestWithAnyETagIsAnsweredNotModifiedWithTheJCardETag() {
        $response = $this->request('GET', '/c1.vcf', [
            'Accept' => 'application/vcard+json',
            'If-None-Match' => '*'
        ]);

        $this->assertEquals(304, $response->getStatus());
        $this->assertEquals(JsonRepresentationETagPlugin::toJsonETag($this->storedETag('/c1.vcf')), $response->getHeader('ETag'));
    }

}

class JsonRepresentationETagPluginTestFile extends \Sabre\DAV\SimpleFile {

    function getLastModified() {
        return JsonRepresentationETagPluginTest::LAST_MODIFIED;
    }
}

class JsonRepresentationETagPluginTestSapiMock {

    public $response;

    function sendResponse($response) {
        $this->response = $response;
    }
}
