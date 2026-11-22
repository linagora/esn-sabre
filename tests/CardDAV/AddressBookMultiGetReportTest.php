<?php

namespace ESN\CardDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * Exercises ESN\CardDAV\CardDAVPlugin::addressbookMultiGetReport, which
 * answers 404 for an href that names no card (RFC 6352 section 8.7).
 *
 * @medium
 */
class AddressBookMultiGetReportTest extends \ESN\DAV\ServerMock {

    const BOOK = '/addressbooks/54b64eadf6d7d8e41d263e0f/book1/';

    private function multiget(array $hrefs, $prop = '<d:getetag/>') {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_DEPTH'        => '1',
            'REQUEST_URI'       => self::BOOK,
        ]);
        $request->setBody('<c:addressbook-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav"><d:prop>' . $prop . '</d:prop>'
            . implode('', array_map(function ($href) { return '<d:href>' . $href . '</d:href>'; }, $hrefs))
            . '</c:addressbook-multiget>');

        return $this->request($request);
    }

    /**
     * @return array href => [status of the response or null, etag or null, address-data or null]
     */
    private function parseMultiStatus($body) {
        $xml = simplexml_load_string($body);
        $xml->registerXPathNamespace('d', 'DAV:');

        $result = [];
        foreach ($xml->xpath('//d:response') as $response) {
            $response->registerXPathNamespace('d', 'DAV:');
            $response->registerXPathNamespace('c', 'urn:ietf:params:xml:ns:carddav');
            $status = $response->xpath('d:status');
            $etag = $response->xpath('.//d:getetag');
            $data = $response->xpath('.//c:address-data');
            $result[(string) $response->xpath('d:href')[0]] = [
                $status ? (string) $status[0] : null,
                $etag ? (string) $etag[0] : null,
                $data ? (string) $data[0] : null,
            ];
        }

        return $result;
    }

    function testUnknownHrefGetsA404Response() {
        $response = $this->multiget([self::BOOK . 'card1', self::BOOK . 'unknown.vcf']);

        $this->assertEquals(207, $response->status);
        $responses = $this->parseMultiStatus($response->getBodyAsString());
        $this->assertCount(2, $responses);
        $this->assertNull($responses[self::BOOK . 'card1'][0]);
        $this->assertNotEmpty($responses[self::BOOK . 'card1'][1]);
        $this->assertEquals(['HTTP/1.1 404 Not Found', null, null], $responses[self::BOOK . 'unknown.vcf']);
    }

    function testOnlyAnUnknownHrefGetsA404Response() {
        $response = $this->multiget([self::BOOK . 'unknown.vcf']);

        $this->assertEquals(207, $response->status);
        $this->assertEquals([
            self::BOOK . 'unknown.vcf' => ['HTTP/1.1 404 Not Found', null, null],
        ], $this->parseMultiStatus($response->getBodyAsString()));
    }

    function testKnownCardsKeepTheirConvertedAddressData() {
        $this->carddavBackend->createCard($this->carddavAddressBook['id'], 'v3.vcf',
            "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:v3\r\nFN:Three\r\nEND:VCARD\r\n");

        $response = $this->multiget([self::BOOK . 'v3.vcf', self::BOOK . 'unknown.vcf'], '<c:address-data/>');

        $this->assertEquals(207, $response->status);
        $responses = $this->parseMultiStatus($response->getBodyAsString());
        $this->assertStringContainsString('FN:Three', $responses[self::BOOK . 'v3.vcf'][2]);
        $this->assertEquals('HTTP/1.1 404 Not Found', $responses[self::BOOK . 'unknown.vcf'][0]);
    }

    function testKnownHrefsAreAnsweredAsBefore() {
        $response = $this->multiget([self::BOOK . 'card1', self::BOOK . 'card2']);

        $this->assertEquals(207, $response->status);
        $this->assertEquals([self::BOOK . 'card1', self::BOOK . 'card2'],
            array_keys($this->parseMultiStatus($response->getBodyAsString())));
    }
}
