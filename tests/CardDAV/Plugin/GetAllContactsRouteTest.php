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

    #[DataProvider('emailRoutePages')]
    function testEmailSortPaginatesInRequestedOrder(string $order, array $expectedFirstPage, array $expectedLastPage) {
        // Emails deliberately differ from names; card1 has no email.
        $emails = ['card1' => null, 'card2' => 'alpha@example.org', 'card3' => 'BRAVO@example.org', 'card4' => 'zoe@example.org'];
        foreach ($emails as $uri => $email) {
            $data = $this->carddavCards[$uri];
            if ($email !== null) {
                $data = str_replace('END:VCARD', "EMAIL:" . $email . "\r\nEND:VCARD", $data);
            }
            $this->carddavBackend->updateCard($this->user1Book1Id, $uri, $data);
        }
        $path = '/contacts/' . $this->userTestId1 . '.json?sort=email&order=' . $order . '&limit=2';

        $first = $this->makeRequest('GET', $path);
        $this->assertEquals(200, $first->status);
        $firstBody = json_decode($first->getBodyAsString());
        $firstItems = $firstBody->{'_embedded'}->{'dav:item'};
        $firstCards = array_map(fn($item) => basename($item->{'_links'}->self->href), $firstItems);
        $this->assertSame($expectedFirstPage, $firstCards);
        $this->assertNotEmpty($firstBody->next);

        $last = $this->makeRequest('GET', $path . '&after=' . urlencode($firstBody->next));
        $this->assertEquals(200, $last->status);
        $lastBody = json_decode($last->getBodyAsString());
        $lastItems = $lastBody->{'_embedded'}->{'dav:item'};
        $lastCards = array_map(fn($item) => basename($item->{'_links'}->self->href), $lastItems);
        $this->assertSame($expectedLastPage, $lastCards);
        $this->assertObjectNotHasProperty('next', $lastBody);
    }

    static function emailRoutePages() {
        return [
            'ascending email' => ['asc', ['card1', 'card2'], ['card3', 'card4']],
            'descending email' => ['desc', ['card4', 'card3'], ['card2', 'card1']]
        ];
    }

    #[DataProvider('supportedOrders')]
    function testEmailCursorPreservesSortKeys(string $order) {
        $card = ['email_sort' => 'elodie@example.org', 'id' => self::CURSOR_CARD['id']];
        $cursor = ContactCursor::encode($card, $order, 'email');
        $decodedCard = ContactCursor::decode($cursor, $order, 'email');

        $this->assertSame($card, $decodedCard);
    }

    #[DataProvider('differentSortFields')]
    function testCursorCannotBeUsedWithDifferentSortField(string $cursorSort, string $requestedSort) {
        $card = self::CURSOR_CARD + ['email_sort' => 'elodie@example.org'];
        $cursor = ContactCursor::encode($card, 'asc', $cursorSort);

        $this->expectException(BadRequest::class);
        ContactCursor::decode($cursor, 'asc', $requestedSort);
    }

    static function differentSortFields() {
        return [
            'name cursor with email request' => ['fn', 'email'],
            'email cursor with name request' => ['email', 'fn']
        ];
    }

    function testArraySortIsRejected() {
        $response = $this->makeRequest('GET', '/contacts/' . $this->userTestId1 . '.json?sort[]=email');

        $this->assertEquals(400, $response->status);
    }

    function testEmailSortIsStoredOnCreateUpdateAndRemoval() {
        $query = ['addressbookid' => new \MongoDB\BSON\ObjectId($this->user1Book1Id), 'uri' => 'email-card'];
        $cardData = "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Email Card\r\nEMAIL: zoe@example.org \r\nEMAIL:alpha@example.org\r\nEND:VCARD\r\n";

        $this->carddavBackend->createCard($this->user1Book1Id, 'email-card', $cardData);
        $created = $this->sabredb->cards->findOne($query);
        $this->assertSame('zoe@example.org', $created['email_sort']);
        $this->assertArrayNotHasKey('email_sort_missing', $created);

        $updatedData = str_replace('zoe@example.org', 'middle@example.org', $cardData);
        $this->carddavBackend->updateCard($this->user1Book1Id, 'email-card', $updatedData);
        $updated = $this->sabredb->cards->findOne($query);
        $this->assertSame('middle@example.org', $updated['email_sort']);
        $this->assertArrayNotHasKey('email_sort_missing', $updated);

        $this->carddavBackend->updateCard($this->user1Book1Id, 'email-card', "BEGIN:VCARD\r\nFN:Email Card\r\nEND:VCARD\r\n");
        $withoutEmail = $this->sabredb->cards->findOne($query);
        $this->assertSame('', $withoutEmail['email_sort']);
        $this->assertArrayNotHasKey('email_sort_missing', $withoutEmail);
    }

    #[DataProvider('preferredEmails')]
    function testEmailSortUsesPreferredEmail(string $version, string $emails, string $expectedEmail) {
        $cardData = "BEGIN:VCARD\r\nVERSION:" . $version . "\r\nFN:Email Card\r\n" . $emails . "END:VCARD\r\n";
        $this->carddavBackend->createCard($this->user1Book1Id, 'email-card', $cardData);
        $query = ['addressbookid' => new \MongoDB\BSON\ObjectId($this->user1Book1Id), 'uri' => 'email-card'];

        $created = $this->sabredb->cards->findOne($query);
        $this->assertSame($expectedEmail, $created['email_sort']);
    }

    static function preferredEmails() {
        return [
            'vCard 3 preferred type' => ['3.0', "EMAIL:zoe@example.org\r\nEMAIL;TYPE=INTERNET,PREF: alpha@example.org \r\n", 'alpha@example.org'],
            'vCard 4 lowest preference' => ['4.0', "EMAIL:zoe@example.org\r\nEMAIL;PREF=50:bravo@example.org\r\nEMAIL;PREF=2:alpha@example.org\r\n", 'alpha@example.org'],
            'vCard 4 preference 100 before unpreferred' => ['4.0', "EMAIL:zoe@example.org\r\nEMAIL;PREF=100:alpha@example.org\r\n", 'alpha@example.org'],
            'same preference keeps first' => ['4.0', "EMAIL;PREF=2:zoe@example.org\r\nEMAIL;PREF=2:alpha@example.org\r\n", 'zoe@example.org'],
            'no preference keeps first' => ['4.0', "EMAIL:zoe@example.org\r\nEMAIL:alpha@example.org\r\n", 'zoe@example.org']
        ];
    }

    #[DataProvider('emailResponseOrder')]
    function testListingOrdersEmailPropertiesByPreference(string $version, string $emailLines, array $expectedOrder) {
        $cardData = "BEGIN:VCARD\r\nVERSION:" . $version . "\r\nUID:card1\r\nFN:Email Order\r\n" . $emailLines . "TEL;TYPE=WORK:123456\r\nEND:VCARD\r\n";
        $this->carddavBackend->updateCard($this->user1Book1Id, 'card1', $cardData);
        $storedCard = $this->carddavBackend->getCard($this->user1Book1Id, 'card1');
        $original = json_decode(json_encode(\Sabre\VObject\Reader::read($cardData)), true);
        $originalEmails = array_values(array_filter($original[1], fn($property) => $property[0] === 'email'));
        $propertiesByEmail = array_column($originalEmails, null, 3);
        $expectedEmails = array_map(fn($value) => $propertiesByEmail[$value], $expectedOrder);
        $originalOtherProperties = array_values(array_filter($original[1], fn($property) => $property[0] !== 'email'));

        foreach (['', '?sort=email&order=asc', '?sort=email&order=desc'] as $query) {
            $response = $this->makeRequest('GET', '/contacts/' . $this->userTestId1 . '.json' . $query);
            $this->assertEquals(200, $response->status);
            $items = json_decode($response->getBodyAsString(), true)['_embedded']['dav:item'];
            $matchingItems = array_values(array_filter($items, fn($item) => basename($item['_links']['self']['href']) === 'card1'));
            $this->assertCount(1, $matchingItems);
            $item = $matchingItems[0];
            $properties = $item['data'][1];

            // Compare complete properties so TYPE, PREF and other parameters survive the reordering.
            $emails = array_values(array_filter($properties, fn($property) => $property[0] === 'email'));
            $otherProperties = array_values(array_filter($properties, fn($property) => $property[0] !== 'email'));
            $this->assertSame($expectedEmails, $emails);
            $this->assertSame($originalOtherProperties, $otherProperties);
            $this->assertSame($storedCard['etag'], $item['etag']);
        }

        $this->assertEquals($storedCard, $this->carddavBackend->getCard($this->user1Book1Id, 'card1'));
    }

    static function emailResponseOrder() {
        return [
            'vCard 3 preferred emails first' => ['3.0',
                "EMAIL;TYPE=WORK:zoe@example.org\r\nEMAIL;TYPE=INTERNET,PREF:alpha@example.org\r\nEMAIL;TYPE=HOME,PREF:bravo@example.org\r\nEMAIL;TYPE=HOME:gamma@example.org\r\n",
                ['alpha@example.org', 'bravo@example.org', 'zoe@example.org', 'gamma@example.org']],
            'vCard 4 increasing PREF with stable ties' => ['4.0',
                "EMAIL:zoe@example.org\r\nEMAIL;PREF=50:delta@example.org\r\nEMAIL;PREF=2:alpha@example.org\r\nEMAIL;PREF=2:bravo@example.org\r\nEMAIL;PREF=100:charlie@example.org\r\n",
                ['alpha@example.org', 'bravo@example.org', 'delta@example.org', 'charlie@example.org', 'zoe@example.org']],
            'no PREF preserves original order' => ['4.0',
                "EMAIL;TYPE=HOME:zoe@example.org\r\nEMAIL;TYPE=WORK:alpha@example.org\r\n",
                ['zoe@example.org', 'alpha@example.org']],
            'single email' => ['4.0', "EMAIL;PREF=1:zoe@example.org\r\n", ['zoe@example.org']],
            'no email' => ['4.0', '', []]
        ];
    }
}
