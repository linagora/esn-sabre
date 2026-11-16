<?php

namespace ESN\CardDAV;

use PHPUnit\Framework\Attributes\DataProvider;

require_once ESN_TEST_BASE . '/CardDAV/PluginTestBase.php';

/**
 * @medium
 */
class DotSegmentNameGuardTest extends PluginTestBase {

    const VALID_CARD = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:valid-card\r\nFN:Valid Card\r\nEND:VCARD\r\n";
    const VALID_EVENT = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Test//EN\r\nBEGIN:VEVENT\r\n" .
        "UID:dot-event\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T100000Z\r\nDTEND:20260101T110000Z\r\n" .
        "SUMMARY:Dot\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    function setUp(): void {
        parent::setUp();

        $this->carddavBackend->createCard($this->user1Book1Id, 'valid.vcf', self::VALID_CARD);
    }

    static function dotSegmentNames(): array {
        return [
            'encoded dot' => ['%2E', '.'],
            'encoded dot lowercase' => ['%2e', '.'],
            'encoded dot dot' => ['%2E%2E', '..'],
            'mixed dot dot' => ['.%2e', '..'],
            'double encoded dot' => ['%252E', '%2E'],
            'double encoded dot dot' => ['%252e%252E', '%2e%2E'],
        ];
    }

    #[DataProvider('dotSegmentNames')]
    function testPutCardWithDotSegmentNameShouldFail(string $encodedName, string $storedName) {
        $response = $this->request($this->putRequest($this->book1Path($encodedName), self::VALID_CARD, 'text/vcard'));

        $this->assertEquals(400, $response->status);
        $this->assertFalse($this->carddavBackend->getCard($this->user1Book1Id, $storedName));
    }

    #[DataProvider('dotSegmentNames')]
    function testCopyCardToDotSegmentNameShouldFail(string $encodedName, string $storedName) {
        $response = $this->copyOrMove('COPY', $this->book1Path('valid.vcf'), $this->book1Path($encodedName));

        $this->assertEquals(400, $response->status);
        $this->assertFalse($this->carddavBackend->getCard($this->user1Book1Id, $storedName));
    }

    #[DataProvider('dotSegmentNames')]
    function testMoveCardToDotSegmentNameShouldFail(string $encodedName, string $storedName) {
        $response = $this->copyOrMove('MOVE', $this->book1Path('valid.vcf'), $this->book1Path($encodedName));

        $this->assertEquals(400, $response->status);
        $this->assertFalse($this->carddavBackend->getCard($this->user1Book1Id, $storedName));
        $this->assertNotFalse($this->carddavBackend->getCard($this->user1Book1Id, 'valid.vcf'));
    }

    #[DataProvider('dotSegmentNames')]
    function testPutCalendarObjectWithDotSegmentNameShouldFail(string $encodedName, string $storedName) {
        $response = $this->request($this->putRequest($this->calendarPath($encodedName), self::VALID_EVENT, 'text/calendar'));

        $this->assertEquals(400, $response->status);
        $this->assertNull($this->caldavBackend->getCalendarObject($this->cal['id'], $storedName));
    }

    function testPutCardWithDotInNameShouldSucceed() {
        $response = $this->request($this->putRequest($this->book1Path('.hidden.vcf'), self::VALID_CARD, 'text/vcard'));

        $this->assertEquals(201, $response->status);
        $this->assertNotFalse($this->carddavBackend->getCard($this->user1Book1Id, '.hidden.vcf'));
    }

    private function putRequest(string $uri, string $body, string $contentType) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'PUT',
            'HTTP_CONTENT_TYPE' => $contentType,
            'REQUEST_URI'       => $uri,
        ]);
        $request->setBody($body);

        return $request;
    }

    private function copyOrMove(string $method, string $source, string $destination) {
        return $this->request([
            'REQUEST_METHOD'   => $method,
            'REQUEST_URI'      => $source,
            'HTTP_DESTINATION' => $destination,
        ]);
    }

    private function book1Path(string $name): string {
        return '/addressbooks/' . $this->userTestId1 . '/book1/' . $name;
    }

    private function calendarPath(string $name): string {
        return '/calendars/' . $this->userTestId1 . '/' . $this->cal['uri'] . '/' . $name;
    }
}
