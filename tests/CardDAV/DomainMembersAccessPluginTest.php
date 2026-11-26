<?php

namespace ESN\CardDAV;

use ESN\CardDAV\Group\DomainMembersAccessPolicy;
use ESN\Utils\AuthTenant;
use ESN\Utils\TenantType;
use PHPUnit\Framework\Attributes\DataProvider;

require_once ESN_TEST_BASE . '/CardDAV/PluginTestBase.php';

class DomainMembersAccessPluginTest extends PluginTestBase {
    const OTHER_DOMAIN_ID = '54b64eadf6d7d8e41d263e7b';
    const TECHNICAL_USER_ID = '54b64eadf6d7d8e41d263eaa';
    const BOOK = '/addressbooks/' . SERVER_MOCK_DOMAIN_ID . '/domain-members';
    const CARDS = [
        'member1.vcf' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:member1\r\nFN:Member One\r\nEMAIL:member1@example.org\r\nEND:VCARD\r\n",
        'member2.vcf' => "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:member2\r\nFN:Member Two\r\nEMAIL:member2@example.org\r\nEND:VCARD\r\n",
    ];

    private $domainMembersId;

    function setUp(): void {
        parent::setUp();

        $this->server->addPlugin(new \Sabre\CardDAV\VCFExportPlugin());
        $this->server->addPlugin(new Sharing\Plugin());

        // user2 administers the domain, user1 is a plain member
        $this->esndb->domains->updateOne(
            [ '_id' => new \MongoDB\BSON\ObjectId(SERVER_MOCK_DOMAIN_ID) ],
            [ '$set' => [ 'administrators' => [ [ 'user_id' => new \MongoDB\BSON\ObjectId($this->userTestId2) ] ] ] ]
        );

        $this->domainMembersId = $this->carddavBackend->createAddressBook(
            'principals/domains/' . SERVER_MOCK_DOMAIN_ID,
            Backend\Esn::DOMAIN_MEMBERS_URI,
            [ '{DAV:}acl' => [ '{DAV:}read' ] ]
        );
        array_map(
            fn($uri) => $this->carddavBackend->createCard($this->domainMembersId, $uri, self::CARDS[$uri]),
            array_keys(self::CARDS)
        );
    }

    function testMembersReadTheDomainMembersByDefault() {
        $this->actAsMember();

        $this->assertEquals(200, $this->getCard()->status);
    }

    #[DataProvider('readRequests')]
    function testRestrictedPolicyDeniesTheMembers(string $readRequest) {
        $this->applyPolicyAsTechnicalUser('restricted');
        $this->actAsMember();

        $response = $this->$readRequest();

        $this->assertEquals(403, $response->status);
        $this->assertStringNotContainsString('Member One', $response->getBodyAsString());
    }

    #[DataProvider('readRequests')]
    function testRestrictedPolicyDeniesTheDomainAdministrators(string $readRequest) {
        $this->applyPolicyAsTechnicalUser('restricted');
        $this->authBackend->setAuthTenant(new AuthTenant($this->userTestId2, SERVER_MOCK_DOMAIN_ID));

        $response = $this->$readRequest();

        $this->assertEquals(403, $response->status);
        $this->assertStringNotContainsString('Member One', $response->getBodyAsString());
    }

    static function readRequests() {
        return [
            'card' => [ 'getCard' ],
            'export' => [ 'exportAddressBook' ],
            'JSON listing' => [ 'listContacts' ],
            'JSON email lookup' => [ 'lookupContactByEmail' ],
            'PROPFIND' => [ 'propfindAddressBook' ],
            'addressbook-query REPORT' => [ 'queryAddressBook' ],
            'addressbook-multiget REPORT' => [ 'multigetCards' ],
        ];
    }

    function testRestrictedPolicyHidesTheDomainMembersFromTheAggregatedContacts() {
        $this->server->addPlugin(new \ESN\JSON\Plugin('json'));
        $this->actAsMember();
        $this->assertStringContainsString('Member One', $this->aggregatedContacts());

        $this->applyPolicyAsTechnicalUser('restricted');
        $this->actAsMember();

        $this->assertStringNotContainsString('Member One', $this->aggregatedContacts());
    }

    function testRestrictedPolicyHidesTheDomainMembersFromTheDomainAddressBookList() {
        $this->applyPolicyAsTechnicalUser('restricted');
        $this->authBackend->setAuthTenant(new AuthTenant($this->userTestId2, SERVER_MOCK_DOMAIN_ID));

        $response = $this->makeRequest('GET', '/addressbooks/' . SERVER_MOCK_DOMAIN_ID . '.json');

        $this->assertStringNotContainsString(Backend\Esn::DOMAIN_MEMBERS_URI, $response->getBodyAsString());
    }

    function testTechnicalUserStillReadsAndWritesARestrictedDomainMembers() {
        $this->applyPolicyAsTechnicalUser('restricted');

        $this->assertEquals(200, $this->listContacts()->status);
        $this->assertEquals(201, $this->putCard('member3.vcf')->status);
        $this->assertEquals(204, $this->deleteCard('member3.vcf')->status);
    }

    function testReadablePolicyRestoresTheMembersAccessWithoutLosingCards() {
        $this->applyPolicyAsTechnicalUser('restricted');
        $this->applyPolicyAsTechnicalUser('readable');
        $this->actAsMember();

        $this->assertEquals(200, $this->getCard()->status);
        $this->assertCount(count(self::CARDS), $this->carddavBackend->getCards($this->domainMembersId));
    }

    function testReadablePolicyRestoresTheAdministratorsAccess() {
        $this->applyPolicyAsTechnicalUser('restricted');
        $this->applyPolicyAsTechnicalUser('readable');
        $this->authBackend->setAuthTenant(new AuthTenant($this->userTestId2, SERVER_MOCK_DOMAIN_ID));

        $this->assertEquals(200, $this->getCard()->status);
    }

    #[DataProvider('policies')]
    function testApplyingAPolicyIsIdempotent(string $policy) {
        $this->assertEquals(204, $this->applyPolicyAsTechnicalUser($policy)->status);
        $this->assertEquals(204, $this->applyPolicyAsTechnicalUser($policy)->status);

        $this->assertEquals($policy, $this->storedPolicy()->value);
        $this->assertCount(count(self::CARDS), $this->carddavBackend->getCards($this->domainMembersId));
    }

    static function policies() {
        return [
            'restricted' => [ 'restricted' ],
            'readable' => [ 'readable' ],
        ];
    }

    function testSwitchingThePolicyKeepsTheSyncToken() {
        $syncToken = $this->addressBookSyncToken();

        $this->applyPolicyAsTechnicalUser('restricted');
        $this->applyPolicyAsTechnicalUser('readable');

        $this->assertEquals($syncToken, $this->addressBookSyncToken());
    }

    #[DataProvider('endUsers')]
    function testEndUsersCannotUpdateThePolicy(string $userIdField) {
        $this->authBackend->setAuthTenant(new AuthTenant($this->$userIdField, SERVER_MOCK_DOMAIN_ID));

        $response = $this->postPolicy('restricted');

        $this->assertEquals(403, $response->status);
        $this->assertEquals(DomainMembersAccessPolicy::Readable, $this->storedPolicy());
    }

    static function endUsers() {
        return [
            'member' => [ 'userTestId1' ],
            'domain administrator' => [ 'userTestId2' ],
        ];
    }

    function testTechnicalTokenOfAnotherDomainCannotUpdateThePolicy() {
        $this->actAsTechnicalUserOf(self::OTHER_DOMAIN_ID);

        $response = $this->postPolicy('restricted');

        $this->assertEquals(403, $response->status);
        $this->assertEquals(DomainMembersAccessPolicy::Readable, $this->storedPolicy());
    }

    #[DataProvider('readRequests')]
    function testTechnicalTokenOfAnotherDomainCannotReadTheDomainMembers(string $readRequest) {
        $this->actAsTechnicalUserOf(self::OTHER_DOMAIN_ID);

        $response = $this->$readRequest();

        $this->assertEquals(403, $response->status);
        $this->assertStringNotContainsString('Member One', $response->getBodyAsString());
    }

    function testTechnicalTokenOfAnotherDomainCannotWriteTheDomainMembers() {
        $this->actAsTechnicalUserOf(self::OTHER_DOMAIN_ID);

        $this->assertEquals(403, $this->putCard('member3.vcf')->status);
        $this->assertEquals(403, $this->deleteCard('member1.vcf')->status);
        $this->assertCount(count(self::CARDS), $this->carddavBackend->getCards($this->domainMembersId));
    }

    #[DataProvider('invalidPolicyBodies')]
    function testInvalidPolicyIsRejected($body) {
        $this->actAsTechnicalUserOf(SERVER_MOCK_DOMAIN_ID);

        $response = $this->makeRequest('POST', self::BOOK . '.json', [ DomainMembersAccessPlugin::JSON_KEY => $body ]);

        $this->assertEquals(400, $response->status);
        $this->assertEquals(DomainMembersAccessPolicy::Readable, $this->storedPolicy());
    }

    static function invalidPolicyBodies() {
        return [
            'unknown policy' => [ [ 'policy' => 'public' ] ],
            'missing policy' => [ [] ],
            'not a string' => [ [ 'policy' => [ 'restricted' ] ] ],
            'not an object' => [ 'restricted' ],
        ];
    }

    function testPolicyCanOnlyBeSetOnTheDomainMembersAddressBook() {
        $this->actAsTechnicalUserOf(SERVER_MOCK_DOMAIN_ID);

        $response = $this->makeRequest('POST', self::BOOK . '/member1.vcf', [ DomainMembersAccessPlugin::JSON_KEY => [ 'policy' => 'restricted' ] ]);

        $this->assertEquals(405, $response->status);
        $this->assertEquals(DomainMembersAccessPolicy::Readable, $this->storedPolicy());
    }

    private function actAsMember() {
        $this->authBackend->setAuthTenant(new AuthTenant($this->userTestId1, SERVER_MOCK_DOMAIN_ID));
    }

    private function actAsTechnicalUserOf(string $domainId) {
        $this->authBackend->setAuthTenant(new AuthTenant(self::TECHNICAL_USER_ID, $domainId, TenantType::Technical));
    }

    private function applyPolicyAsTechnicalUser(string $policy) {
        $this->actAsTechnicalUserOf(SERVER_MOCK_DOMAIN_ID);

        $response = $this->postPolicy($policy);
        $this->assertEquals(204, $response->status, $response->getBodyAsString());

        return $response;
    }

    private function postPolicy(string $policy) {
        return $this->makeRequest('POST', self::BOOK . '.json', [ DomainMembersAccessPlugin::JSON_KEY => [ 'policy' => $policy ] ]);
    }

    private function storedPolicy(): DomainMembersAccessPolicy {
        return DomainMembersAccessPolicy::fromAddressBookInfo($this->domainMembersAddressBookInfo());
    }

    private function addressBookSyncToken() {
        return $this->domainMembersAddressBookInfo()['{http://sabredav.org/ns}sync-token'];
    }

    private function domainMembersAddressBookInfo(): array {
        $addressBooks = $this->carddavBackend->getAddressBooksFor('principals/domains/' . SERVER_MOCK_DOMAIN_ID);

        return array_values(array_filter($addressBooks, fn($addressBook) => $addressBook['uri'] === Backend\Esn::DOMAIN_MEMBERS_URI))[0];
    }

    private function aggregatedContacts(): string {
        $response = $this->makeRequest('GET', '/contacts/' . $this->userTestId1 . '.json?domainMember=true');
        $this->assertEquals(200, $response->status);

        return $response->getBodyAsString();
    }

    private function getCard() {
        return $this->davRequest('GET', self::BOOK . '/member1.vcf');
    }

    private function exportAddressBook() {
        return $this->davRequest('GET', self::BOOK . '?export');
    }

    private function listContacts() {
        return $this->makeRequest('GET', self::BOOK . '.json');
    }

    private function lookupContactByEmail() {
        return $this->makeRequest('GET', self::BOOK . '.json?email=member1@example.org');
    }

    private function propfindAddressBook() {
        return $this->davRequest('PROPFIND', self::BOOK, '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:allprop/></d:propfind>', [ 'HTTP_DEPTH' => '1' ]);
    }

    private function queryAddressBook() {
        return $this->davRequest('REPORT', self::BOOK, '<?xml version="1.0"?>'
            . '<c:addressbook-query xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav">'
            . '<d:prop><d:getetag/><c:address-data/></d:prop></c:addressbook-query>', [ 'HTTP_DEPTH' => '1' ]);
    }

    private function multigetCards() {
        return $this->davRequest('REPORT', '/addressbooks/' . $this->userTestId1 . '/book1', '<?xml version="1.0"?>'
            . '<c:addressbook-multiget xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:carddav">'
            . '<d:prop><d:getetag/><c:address-data/></d:prop>'
            . '<d:href>' . self::BOOK . '/member1.vcf</d:href></c:addressbook-multiget>', [ 'HTTP_DEPTH' => '1' ]);
    }

    private function putCard(string $uri) {
        return $this->davRequest('PUT', self::BOOK . '/' . $uri, "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:new\r\nFN:New Member\r\nEND:VCARD\r\n", [ 'HTTP_CONTENT_TYPE' => 'text/vcard' ]);
    }

    private function deleteCard(string $uri) {
        return $this->davRequest('DELETE', self::BOOK . '/' . $uri);
    }

    private function davRequest(string $method, string $uri, ?string $body = null, array $headers = []) {
        $request = \Sabre\HTTP\Sapi::createFromServerArray($headers + [
            'REQUEST_METHOD' => $method,
            'HTTP_CONTENT_TYPE' => 'application/xml',
            'REQUEST_URI' => $uri,
        ]);
        if ($body !== null) {
            $request->setBody($body);
        }

        return $this->request($request);
    }
}
