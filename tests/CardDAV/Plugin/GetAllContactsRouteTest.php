<?php

namespace ESN\CardDAV\Plugin;

use ESN\CardDAV\ContactCursor;
use PHPUnit\Framework\Attributes\DataProvider;
use Sabre\DAV\Exception\BadRequest;

require_once ESN_TEST_BASE . '/CardDAV/PluginTestBase.php';

class GetAllContactsRouteTest extends \ESN\CardDAV\PluginTestBase {

    function setUp(): void {
        parent::setUp();

        // Production also runs the generic JSON GET plugin, which resolves paths in the DAV tree.
        $this->server->addPlugin(new \ESN\JSON\Plugin('json'));
    }

    function testGetAllContactsUsesDedicatedPathAndKeepsCardLinks() {
        $response = $this->makeRequest('GET', '/contacts/' . $this->userTestId1 . '.json?sort=fn&limit=2');

        $this->assertEquals(200, $response->status);
        $body = json_decode($response->getBodyAsString());
        $this->assertEquals('/contacts/' . $this->userTestId1 . '.json', $body->{'_links'}->self->href);
        $this->assertCount(2, $body->{'_embedded'}->{'dav:item'});
        $this->assertStringStartsWith('/addressbooks/' . $this->userTestId1 . '/', $body->{'_embedded'}->{'dav:item'}[0]->{'_links'}->self->href);
        $this->assertNotEmpty($body->next);

        $next = $this->makeRequest('GET', '/contacts/' . $this->userTestId1 . '.json?sort=fn&limit=2&after=' . urlencode($body->next));
        $this->assertEquals(200, $next->status);
        $nextBody = json_decode($next->getBodyAsString());
        $this->assertCount(2, $nextBody->{'_embedded'}->{'dav:item'});
        $this->assertNotEquals($body->{'_embedded'}->{'dav:item'}[0]->{'_links'}->self->href, $nextBody->{'_embedded'}->{'dav:item'}[0]->{'_links'}->self->href);
    }

    function testAddressBookNamedAllContactsRemainsReachable() {
        $this->createAddressBook('principals/users/' . $this->userTestId1, 'all-contacts');

        $addressBook = $this->makeRequest('GET', '/addressbooks/' . $this->userTestId1 . '/all-contacts.json');
        $this->assertEquals(200, $addressBook->status);
        $this->assertCount(4, json_decode($addressBook->getBodyAsString())->{'_embedded'}->{'dav:item'});

        $allContacts = $this->makeRequest('GET', '/contacts/' . $this->userTestId1 . '.json');
        $this->assertEquals(200, $allContacts->status);
        $this->assertCount(8, json_decode($allContacts->getBodyAsString())->{'_embedded'}->{'dav:item'});
    }

    function testAddressBookHomeIgnoresContactsQuery() {
        $response = $this->makeRequest('GET', '/addressbooks/' . $this->userTestId1 . '.json?contacts=true&personal=true');

        $this->assertEquals(200, $response->status);
        $body = json_decode($response->getBodyAsString());
        $this->assertCount(3, $body->{'_embedded'}->{'dav:addressbook'});
        $this->assertObjectNotHasProperty('dav:item', $body->{'_embedded'});
    }

    function testOtherUserCannotGetAllContacts() {
        $response = $this->makeRequest('GET', '/contacts/' . $this->userTestId2 . '.json');

        $this->assertEquals(403, $response->status);
    }

    function testUnknownUserHasNoContactList() {
        $response = $this->makeRequest('GET', '/contacts/aaaaaaaaaaaaaaaaaaaaaaaa.json');

        $this->assertEquals(404, $response->status);
    }

    function testDefaultOrderMatchesExplicitAscendingOrder() {
        $path = '/contacts/' . $this->userTestId1 . '.json';
        $default = $this->makeRequest('GET', $path);
        $ascending = $this->makeRequest('GET', $path . '?order=asc');

        $this->assertEquals(200, $default->status);
        $this->assertEquals(200, $ascending->status);
        $defaultBody = json_decode($default->getBodyAsString());
        $ascendingBody = json_decode($ascending->getBodyAsString());
        $this->assertEquals($defaultBody, $ascendingBody);
    }

    function testDescendingOrderResumesAfterLastCardOfPreviousPage() {
        $path = '/contacts/' . $this->userTestId1 . '.json?order=desc&limit=2';
        $first = $this->makeRequest('GET', $path);
        $this->assertEquals(200, $first->status);
        $firstBody = json_decode($first->getBodyAsString());
        // The existing fixture has FN values d, c, b, a for card1, card2, card3, card4.
        $firstPageItems = $firstBody->{'_embedded'}->{'dav:item'};
        $firstPageCards = array_map(fn($item) => basename($item->{'_links'}->self->href), $firstPageItems);
        $this->assertSame(['card1', 'card2'], $firstPageCards);
        $this->assertNotEmpty($firstBody->next);

        $last = $this->makeRequest('GET', $path . '&after=' . urlencode($firstBody->next));
        $this->assertEquals(200, $last->status);
        $lastBody = json_decode($last->getBodyAsString());
        $lastPageItems = $lastBody->{'_embedded'}->{'dav:item'};
        $lastPageCards = array_map(fn($item) => basename($item->{'_links'}->self->href), $lastPageItems);
        $this->assertSame(['card3', 'card4'], $lastPageCards);
        $this->assertObjectNotHasProperty('next', $lastBody);
    }

    #[DataProvider('invalidOrderQueries')]
    function testInvalidOrderIsRejected(string $query) {
        $response = $this->makeRequest('GET', '/contacts/' . $this->userTestId1 . '.json?' . $query);

        $this->assertEquals(400, $response->status);
    }

    static function invalidOrderQueries() {
        return [['order='], ['order=invalid'], ['order[]=asc']];
    }

    private const CURSOR_CARD = ['fn_sort' => 'Élodie Martin', 'id' => '6abcced236be748e3d36302b'];

    #[DataProvider('supportedOrders')]
    function testRoundTripPreservesSortKeysAndOrder(string $order) {
        $cursor = ContactCursor::encode(self::CURSOR_CARD, $order);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $cursor);
        $cursorJson = base64_decode(strtr($cursor, '-_', '+/'));
        $data = json_decode($cursorJson, true);
        $this->assertSame($order, $data['order']);
        $this->assertSame(self::CURSOR_CARD['fn_sort'], $data['fn_sort']);
        $this->assertSame(self::CURSOR_CARD['id'], $data['_id']);
        $this->assertSame(self::CURSOR_CARD, ContactCursor::decode($cursor, $order));
    }

    function testDefaultOrderIsAscending() {
        $defaultCursor = ContactCursor::encode(self::CURSOR_CARD);
        $ascendingCursor = ContactCursor::encode(self::CURSOR_CARD, 'asc');
        $decodedCard = ContactCursor::decode($defaultCursor);

        $this->assertSame($ascendingCursor, $defaultCursor);
        $this->assertSame(self::CURSOR_CARD, $decodedCard);
    }

    #[DataProvider('supportedOrders')]
    function testAbsentCursorStartsListing(string $order) {
        $this->assertNull(ContactCursor::decode(null, $order));
    }

    static function supportedOrders() {
        return ['ascending' => ['asc'], 'descending' => ['desc']];
    }

    #[DataProvider('oppositeOrders')]
    function testOppositeOrderIsRejected(string $cursorOrder, string $requestedOrder) {
        $cursor = ContactCursor::encode(self::CURSOR_CARD, $cursorOrder);

        $this->expectException(BadRequest::class);
        ContactCursor::decode($cursor, $requestedOrder);
    }

    static function oppositeOrders() {
        return [
            'ascending cursor with descending request' => ['asc', 'desc'],
            'descending cursor with ascending request' => ['desc', 'asc']
        ];
    }

    function testLegacyCursorResumesAscendingListing() {
        // Legacy cursors contain the last name and id, but no order field.
        $legacyData = ['fn_sort' => self::CURSOR_CARD['fn_sort'], '_id' => self::CURSOR_CARD['id']];
        $legacyJson = json_encode($legacyData);
        $cursor = base64_encode($legacyJson);

        $this->assertSame(self::CURSOR_CARD, ContactCursor::decode($cursor));
        $this->assertSame(self::CURSOR_CARD, ContactCursor::decode($cursor, 'asc'));
    }

    function testLegacyCursorCannotResumeDescendingListing() {
        // Legacy cursors contain the last name and id, but no order field.
        $legacyData = ['fn_sort' => self::CURSOR_CARD['fn_sort'], '_id' => self::CURSOR_CARD['id']];
        $legacyJson = json_encode($legacyData);
        $cursor = base64_encode($legacyJson);

        $this->expectException(BadRequest::class);
        ContactCursor::decode($cursor, 'desc');
    }

    #[DataProvider('malformedCursors')]
    function testMalformedCursorIsRejected(string $cursor) {
        $this->expectException(BadRequest::class);
        ContactCursor::decode($cursor);
    }

    static function malformedCursors() {
        return [
            'invalid base64' => ['not-base64!'],
            'invalid JSON' => [base64_encode('{')]
        ];
    }

    #[DataProvider('invalidCursorData')]
    function testInvalidCursorDataIsRejected(array $data) {
        $cursorJson = json_encode($data);
        $cursor = base64_encode($cursorJson);

        $this->expectException(BadRequest::class);
        ContactCursor::decode($cursor);
    }

    static function invalidCursorData() {
        $validKeys = ['fn_sort' => self::CURSOR_CARD['fn_sort'], '_id' => self::CURSOR_CARD['id']];
        return [
            'missing keys' => [[]],
            'invalid name' => [array_replace($validKeys, ['fn_sort' => 1])],
            'invalid id' => [array_replace($validKeys, ['_id' => 'not-an-id'])],
            'unsupported order' => [$validKeys + ['order' => 'invalid']],
            'null order' => [$validKeys + ['order' => null]],
            'array order' => [$validKeys + ['order' => []]],
            'numeric order' => [$validKeys + ['order' => 1]]
        ];
    }
}
