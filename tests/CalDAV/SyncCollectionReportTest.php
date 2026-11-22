<?php

namespace ESN\CalDAV;

require_once ESN_TEST_BASE . '/DAV/ServerMock.php';

/**
 * The sync-collection REPORT of a calendar, answered by Sabre's sync plugin on
 * the changes of the Mongo backend.
 *
 * @medium
 */
class SyncCollectionReportTest extends \ESN\DAV\ServerMock {

    function setUp(): void {
        parent::setUp();

        $this->server->addPlugin(new \Sabre\DAV\Sync\Plugin());
    }

    private function syncCollection($syncToken) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD'    => 'REPORT',
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'HTTP_DEPTH'        => '1',
            'REQUEST_URI'       => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/',
        ]);
        $request->setBody('<d:sync-collection xmlns:d="DAV:">'
            . '<d:sync-token>' . $syncToken . '</d:sync-token><d:sync-level>1</d:sync-level>'
            . '<d:prop><d:getetag/></d:prop></d:sync-collection>');

        return $this->request($request);
    }

    public static function neverIssuedSyncTokens(): array {
        return [
            'non-numeric suffix' => ['http://sabre.io/ns/sync/abc'],
            'negative suffix' => ['http://sabre.io/ns/sync/-1'],
            'decimal suffix' => ['http://sabre.io/ns/sync/1.5'],
            'above the current token' => ['http://sabre.io/ns/sync/999999'],
            'too long for an integer' => ['http://sabre.io/ns/sync/99999999999999999999999'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('neverIssuedSyncTokens')]
    function testNeverIssuedSyncTokenIsRefused($syncToken) {
        $response = $this->syncCollection($syncToken);

        $this->assertEquals(403, $response->status);
        $this->assertStringContainsString('valid-sync-token', $response->getBodyAsString());
    }

    function testIssuedSyncTokenListsTheChanges() {
        $response = $this->syncCollection('http://sabre.io/ns/sync/1');

        $this->assertEquals(207, $response->status);
        $this->assertStringContainsString('event1.ics', $response->getBodyAsString());
    }
}
