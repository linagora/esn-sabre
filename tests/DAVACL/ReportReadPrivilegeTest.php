<?php

namespace ESN\DAVACL;

use PHPUnit\Framework\Attributes\DataProvider;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * Sabre runs calendar-query, addressbook-query and sync-collection on the collection without checking {DAV:}read
 * on it, and only hides the properties of each match: their hrefs answer the attacker's filters. ESN\DAVACL\Plugin
 * refuses those REPORTs on a collection the user cannot read.
 *
 * @medium
 */
class ReportReadPrivilegeTest extends \ESN\DAV\ServerMock {
    const OTHER_USER = '54b64eadf6d7d8e41d263e0e';
    const OTHER_CALENDAR = '/calendars/' . self::OTHER_USER . '/calendar2/';
    const OTHER_BOOK = '/addressbooks/' . self::OTHER_USER . '/otherBook/';

    function setUp(): void {
        parent::setUp();

        $this->server->addPlugin(new \Sabre\DAV\Sync\Plugin());

        $this->caldavBackend->createCalendarObject($this->calUser2['id'], 'hidden-event.ics', join("\r\n", [
            'BEGIN:VCALENDAR', 'BEGIN:VEVENT', 'UID:hidden-event', 'DTSTART:20150228T030000Z',
            'DTEND:20150228T040000Z', 'SUMMARY:Secret meeting', 'END:VEVENT', 'END:VCALENDAR', '']));
        $bookId = $this->carddavBackend->createAddressBook('principals/users/' . self::OTHER_USER, 'otherBook', []);
        $this->carddavBackend->createCard($bookId, 'hidden-card.vcf', "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:hidden-card\r\nFN:Secret contact\r\nEND:VCARD\r\n");
    }

    public static function readReports(): array {
        $c = 'xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav"';
        $a = 'xmlns:d="DAV:" xmlns:a="urn:ietf:params:xml:ns:carddav"';

        return [
            'calendar-query' => [self::OTHER_CALENDAR, '<c:calendar-query ' . $c . '><d:prop><d:getetag/></d:prop>'
                . '<c:filter><c:comp-filter name="VCALENDAR"><c:comp-filter name="VEVENT"><c:prop-filter name="SUMMARY">'
                . '<c:text-match>Secret</c:text-match></c:prop-filter></c:comp-filter></c:comp-filter></c:filter></c:calendar-query>'],
            'calendar-multiget' => [self::OTHER_CALENDAR, '<c:calendar-multiget ' . $c . '><d:prop><d:getetag/></d:prop>'
                . '<d:href>' . self::OTHER_CALENDAR . 'hidden-event.ics</d:href></c:calendar-multiget>'],
            'calendar sync-collection' => [self::OTHER_CALENDAR, '<d:sync-collection xmlns:d="DAV:"><d:sync-token/>'
                . '<d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>'],
            'addressbook-query' => [self::OTHER_BOOK, '<a:addressbook-query ' . $a . '><d:prop><d:getetag/></d:prop>'
                . '<a:filter><a:prop-filter name="FN"><a:text-match>Secret</a:text-match></a:prop-filter></a:filter></a:addressbook-query>'],
            'addressbook-multiget' => [self::OTHER_BOOK, '<a:addressbook-multiget ' . $a . '><d:prop><d:getetag/></d:prop>'
                . '<d:href>' . self::OTHER_BOOK . 'hidden-card.vcf</d:href></a:addressbook-multiget>'],
            'addressbook sync-collection' => [self::OTHER_BOOK, '<d:sync-collection xmlns:d="DAV:"><d:sync-token/>'
                . '<d:sync-level>1</d:sync-level><d:prop><d:getetag/></d:prop></d:sync-collection>'],
        ];
    }

    #[DataProvider('readReports')]
    function testReadReportOnAnUnreadableCollectionIsRefused(string $uri, string $body) {
        $response = $this->report($uri, $body);

        $this->assertEquals(403, $response->status);
        // No multistatus: a 207 with per-item 403 would still list the matching hrefs
        $this->assertStringNotContainsString('multistatus', $response->getBodyAsString());
    }

    #[DataProvider('readReports')]
    function testReadReportOnAReadableCollectionStillAnswers(string $uri, string $body) {
        $this->authBackend->setAuthTenant(new \ESN\Utils\AuthTenant(self::OTHER_USER, SERVER_MOCK_DOMAIN_ID));

        $response = $this->report($uri, $body);

        $this->assertEquals(207, $response->status);
        $this->assertStringContainsString('hidden-', $response->getBodyAsString());
    }

    private function report(string $uri, string $body) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_DEPTH'        => '1',
            'REQUEST_URI'       => $uri,
        ]);
        $request->setBody($body);

        return $this->request($request);
    }
}
