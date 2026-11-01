<?php

namespace ESN\CardDAV;

use ESN\Utils\AuthTenant;
use ESN\Utils\TenantType;

require_once ESN_TEST_BASE . '/CardDAV/PluginTestBase.php';

/**
 * @medium
 */
class CopyMoveGuardPluginTest extends PluginTestBase {

    const DOMAIN_ID = '54b64eadf6d7d8e41d263e7e';
    const VALID_CARD = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:valid-card\r\nFN:Valid Card\r\nEND:VCARD\r\n";
    const PHOTO_CARD = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:photo-card\r\nFN:Photo Card\r\n" .
        "PHOTO;ENCODING=b;TYPE=JPEG:dGVzdA==\r\nEND:VCARD\r\n";

    private $user1Book2Id;

    function setUp(): void {
        parent::setUp();

        $this->user1Book2Id = $this->createAddressBook('principals/users/' . $this->userTestId1, 'book2');
        $this->carddavBackend->createCard($this->user1Book1Id, 'valid.vcf', self::VALID_CARD);
        $this->carddavBackend->createCard($this->user1Book1Id, 'photo.vcf', self::PHOTO_CARD);
    }

    function testCopyCalendarObjectIntoAddressBookShouldFail() {
        $response = $this->copyOrMove('COPY', $this->eventPath(), '/addressbooks/' . $this->userTestId1 . '/book1/event1.vcf');

        $this->assertEquals(415, $response->status);
        $this->assertFalse($this->carddavBackend->getCard($this->user1Book1Id, 'event1.vcf'));
    }

    function testMoveCalendarObjectIntoAddressBookShouldFail() {
        $response = $this->copyOrMove('MOVE', $this->eventPath(), '/addressbooks/' . $this->userTestId1 . '/book1/event1.vcf');

        $this->assertEquals(415, $response->status);
        $this->assertFalse($this->carddavBackend->getCard($this->user1Book1Id, 'event1.vcf'));
        $this->assertNotNull($this->caldavBackend->getCalendarObject($this->cal['id'], 'event1.ics'));
    }

    function testCopyCardIntoDomainMembersAsAdminShouldFail() {
        $domainMembersId = $this->createDomainMembersAddressBook([['user_id' => $this->userTestId1]]);

        $response = $this->copyOrMove('COPY', $this->book1CardPath('valid.vcf'), $this->domainMembersPath('copied.vcf'));

        $this->assertEquals(403, $response->status);
        $this->assertFalse($this->carddavBackend->getCard($domainMembersId, 'copied.vcf'));
    }

    function testMoveCardIntoDomainMembersAsAdminShouldFail() {
        $domainMembersId = $this->createDomainMembersAddressBook([['user_id' => $this->userTestId1]]);

        $response = $this->copyOrMove('MOVE', $this->book1CardPath('valid.vcf'), $this->domainMembersPath('moved.vcf'));

        $this->assertEquals(403, $response->status);
        $this->assertFalse($this->carddavBackend->getCard($domainMembersId, 'moved.vcf'));
        $this->assertNotFalse($this->carddavBackend->getCard($this->user1Book1Id, 'valid.vcf'));
    }

    function testCopyCardIntoDomainMembersAsTechnicalUserShouldSucceed() {
        $this->authBackend->setAuthTenant(new AuthTenant($this->userTestId1, self::DOMAIN_ID, TenantType::Technical));
        $domainMembersId = $this->createDomainMembersAddressBook([]);
        $this->carddavBackend->createCard($domainMembersId, 'valid.vcf', self::VALID_CARD);

        $response = $this->copyOrMove('COPY', $this->domainMembersPath('valid.vcf'), $this->domainMembersPath('copied.vcf'));

        $this->assertEquals(201, $response->status);
        $this->assertNotFalse($this->carddavBackend->getCard($domainMembersId, 'copied.vcf'));
    }

    function testCopyValidCardBetweenAddressBooksShouldSucceed() {
        $response = $this->copyOrMove('COPY', $this->book1CardPath('valid.vcf'), $this->book2CardPath('copied.vcf'));

        $this->assertEquals(201, $response->status);
        $this->assertEquals(self::VALID_CARD, $this->carddavBackend->getCard($this->user1Book2Id, 'copied.vcf')['carddata']);
    }

    function testMoveValidCardBetweenAddressBooksShouldSucceed() {
        $response = $this->copyOrMove('MOVE', $this->book1CardPath('valid.vcf'), $this->book2CardPath('moved.vcf'));

        $this->assertEquals(201, $response->status);
        $this->assertEquals(self::VALID_CARD, $this->carddavBackend->getCard($this->user1Book2Id, 'moved.vcf')['carddata']);
        $this->assertFalse($this->carddavBackend->getCard($this->user1Book1Id, 'valid.vcf'));
    }

    function testCopyCardWithInlinePhotoShouldStoreFilteredCard() {
        $this->server->addPlugin(new InlinePhotoPlugin(InlinePhotoPlugin::MODE_FILTER));

        $response = $this->copyOrMove('COPY', $this->book1CardPath('photo.vcf'), $this->book2CardPath('copied.vcf'));

        $this->assertEquals(201, $response->status);
        $stored = $this->carddavBackend->getCard($this->user1Book2Id, 'copied.vcf')['carddata'];
        $this->assertStringNotContainsString('PHOTO', $stored);
        $this->assertStringContainsString('UID:photo-card', $stored);
    }

    function testMoveCardWithInlinePhotoShouldStoreFilteredCard() {
        $this->server->addPlugin(new InlinePhotoPlugin(InlinePhotoPlugin::MODE_FILTER));

        $response = $this->copyOrMove('MOVE', $this->book1CardPath('photo.vcf'), $this->book2CardPath('moved.vcf'));

        $this->assertEquals(201, $response->status);
        $this->assertStringNotContainsString('PHOTO', $this->carddavBackend->getCard($this->user1Book2Id, 'moved.vcf')['carddata']);
    }

    function testCopyCardWithInlinePhotoShouldFailInRejectMode() {
        $this->server->addPlugin(new InlinePhotoPlugin(InlinePhotoPlugin::MODE_REJECT));

        $response = $this->copyOrMove('COPY', $this->book1CardPath('photo.vcf'), $this->book2CardPath('copied.vcf'));

        $this->assertEquals(403, $response->status);
        $this->assertFalse($this->carddavBackend->getCard($this->user1Book2Id, 'copied.vcf'));
    }

    private function copyOrMove(string $method, string $source, string $destination) {
        return $this->request([
            'REQUEST_METHOD'   => $method,
            'REQUEST_URI'      => $source,
            'HTTP_DESTINATION' => $destination,
        ]);
    }

    private function createDomainMembersAddressBook(array $administrators) {
        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID),
            'administrators' => $administrators
        ]);

        return $this->createAddressBook('principals/domains/' . self::DOMAIN_ID, 'domain-members');
    }

    private function eventPath(): string {
        return '/calendars/' . $this->userTestId1 . '/' . $this->cal['uri'] . '/event1.ics';
    }

    private function book1CardPath(string $card): string {
        return '/addressbooks/' . $this->userTestId1 . '/book1/' . $card;
    }

    private function book2CardPath(string $card): string {
        return '/addressbooks/' . $this->userTestId1 . '/book2/' . $card;
    }

    private function domainMembersPath(string $card): string {
        return '/addressbooks/' . self::DOMAIN_ID . '/domain-members/' . $card;
    }
}
