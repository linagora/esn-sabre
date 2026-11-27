<?php
namespace ESN\CardDAV;

use ESN\Utils\AuthTenant;
use ESN\Utils\TenantType;
use PHPUnit\Framework\Attributes\DataProvider;

require_once ESN_TEST_BASE . '/CardDAV/PluginTestBase.php';
require_once ESN_TEST_BASE . '/DAV/MongoFindCounter.php';

class DomainMembersAccessTest extends PluginTestBase {
    private string $bookPath;
    private string $domainBookId;
    private const CARD = "BEGIN:VCARD\r\nVERSION:3.0\r\nUID:probe\r\nFN:Directory Secret\r\nEMAIL:secret@example.org\r\nEND:VCARD\r\n";

    function setUp(): void {
        parent::setUp();
        $this->esndb->domains->updateOne(['_id' => new \MongoDB\BSON\ObjectId(SERVER_MOCK_DOMAIN_ID)],
            ['$set' => ['administrators' => [['user_id' => new \MongoDB\BSON\ObjectId($this->userTestId2)]]]]);
        $this->domainBookId = $this->carddavBackend->createAddressBook('principals/domains/' . SERVER_MOCK_DOMAIN_ID,
            'domain-members', ['{DAV:}acl' => ['{DAV:}read'], '{http://open-paas.org/contacts}type' => 'group']);
        $this->carddavBackend->createCard($this->domainBookId, 'probe.vcf', self::CARD);
        $this->bookPath = '/addressbooks/' . SERVER_MOCK_DOMAIN_ID . '/domain-members';
        $this->server->addPlugin(new \ESN\CardDAV\Sharing\Plugin());
        $this->server->addPlugin(new \Sabre\CardDAV\VCFExportPlugin());
    }

    private function actor(bool $technical = false, ?string $userId = null): void {
        $this->authBackend->setAuthTenant(new AuthTenant($userId ?? $this->userTestId1, SERVER_MOCK_DOMAIN_ID,
            $technical ? TenantType::Technical : TenantType::User));
        $this->server->tree->markDirty('');
    }

    private function callDav(string $method, string $suffix = '', string $body = '', array $headers = []) {
        $this->server->tree->markDirty('');
        $headers += ['Content-Type' => 'application/xml', 'Accept' => 'application/xml', 'Depth' => '1'];
        $request = new \Sabre\HTTP\Request($method, $this->bookPath . $suffix, $headers, $body);
        return $this->request($request);
    }

    private function changeAccess(string $access) {
        $body = $access === 'restricted' ? '<d:acl xmlns:d="DAV:"/>'
            : '<d:acl xmlns:d="DAV:"><d:ace><d:principal><d:href>/principals/domains/' . SERVER_MOCK_DOMAIN_ID
                . '</d:href></d:principal><d:grant><d:privilege><d:read/></d:privilege></d:grant></d:ace></d:acl>';
        return $this->callDav('ACL', '', $body);
    }

    #[DataProvider('invalidAcls')]
    function testInvalidAclIsRejectedWithoutChangingAccess(string $body, int $status, string $condition = '') {
        $this->actor(true);
        $response = $this->callDav('ACL', '', $body);
        $this->assertEquals($status, $response->status, $response->getBodyAsString());
        if ($condition !== '') {
            $document = new \DOMDocument();
            $document->loadXML($response->getBodyAsString());
            $xpath = new \DOMXPath($document);
            $xpath->registerNamespace('d', 'DAV:');
            $this->assertSame(1, $xpath->query('/d:error/d:' . $condition)->length);
        }
        $this->actor();
        $this->assertEquals(200, $this->callDav('GET', '/probe.vcf')->status);
    }

    static function invalidAcls(): array {
        $ace = '<d:acl xmlns:d="DAV:"><d:ace><d:principal><d:href>/principals/domains/' . SERVER_MOCK_DOMAIN_ID
            . '</d:href></d:principal><d:grant><d:privilege>PRIVILEGE</d:privilege></d:grant>EXTRA</d:ace></d:acl>';
        return [
            'empty body' => ['', 400],
            'wrong XML root' => ['<d:not-acl xmlns:d="DAV:"/>', 400],
            'ACE outside DAV namespace' => ['<d:acl xmlns:d="DAV:"><ace/></d:acl>', 400],
            'mixed ACE namespaces' => [str_replace(['PRIVILEGE', 'EXTRA', '</d:acl>'], ['<d:read/>', '', '<ace/></d:acl>'], $ace), 400],
            'missing principal' => ['<d:acl xmlns:d="DAV:"><d:ace/></d:acl>', 400],
            'unknown privilege' => [str_replace(['PRIVILEGE', 'EXTRA'], ['<d:unknown/>', ''], $ace), 403, 'not-supported-privilege'],
            'unrecognized principal' => [str_replace(['PRIVILEGE', 'EXTRA', 'principals/domains/' . SERVER_MOCK_DOMAIN_ID], ['<d:read/>', '', 'principals/users/000000000000000000000000'], $ace), 403, 'recognized-principal'],
            'client-protected ACE' => [str_replace(['PRIVILEGE', 'EXTRA'], ['<d:read/>', '<d:protected/>'], $ace), 409, 'no-ace-conflict']
        ];
    }

    function testOnlyTechnicalUsersMayChangeAcl() {
        foreach ([$this->userTestId1, $this->userTestId2] as $userId) {
            $this->actor(false, $userId);
            $response = $this->changeAccess('restricted');
            $this->assertEquals(403, $response->status, $response->getBodyAsString());
        }
    }

    function testAnotherDomainTechnicalTokenCannotChangeOrReadTheBook() {
        $foreignDomain = '54b64eadf6d7d8e41d263e7b';
        $this->esndb->domains->insertOne(['_id' => new \MongoDB\BSON\ObjectId($foreignDomain), 'name' => 'foreign.example']);
        $this->authBackend->setAuthTenant(new AuthTenant($this->userTestId1, $foreignDomain, TenantType::Technical));
        $this->assertEquals(403, $this->changeAccess('restricted')->status);
        $this->assertEquals(403, $this->callDav('GET', '/probe.vcf')->status);
        $this->actor();
        $this->assertEquals(200, $this->callDav('GET', '/probe.vcf')->status);
    }

    function testStandardAclRemainsUnsupportedOnPersonalAddressBooks() {
        $this->actor(true);
        $this->bookPath = '/addressbooks/' . $this->userTestId1 . '/book1';
        $this->assertEquals(409, $this->changeAccess('restricted')->status);
    }

    static function readRequests(): array {
        $requests = [
            'single card' => ['method' => 'GET', 'suffix' => '/probe.vcf'],
            'export' => ['method' => 'GET', 'suffix' => '?export', 'headers' => ['Accept' => 'text/vcard']],
            'JSON listing' => ['method' => 'GET', 'suffix' => '.json', 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json']],
            'JSON email lookup' => ['method' => 'GET', 'suffix' => '.json?email=secret%40example.org', 'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json']],
            'PROPFIND' => ['method' => 'PROPFIND', 'body' => '<D:propfind xmlns:D="DAV:"><D:prop><D:getetag/></D:prop></D:propfind>'],
            'addressbook-query' => ['method' => 'REPORT', 'body' => '<C:addressbook-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav"><D:prop><C:address-data/></D:prop></C:addressbook-query>'],
            'addressbook-multiget' => ['method' => 'REPORT', 'body' => '<C:addressbook-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav"><D:prop><C:address-data/></D:prop><D:href>/addressbooks/' . SERVER_MOCK_DOMAIN_ID . '/domain-members/probe.vcf</D:href></C:addressbook-multiget>'],
        ];
        $cases = [];
        foreach ($requests as $name => $request) {
            foreach ([false, true] as $administrator) {
                $cases[$name . ($administrator ? ' as admin' : ' as member')] = [$administrator, $request];
            }
        }
        return $cases;
    }

    #[DataProvider('readRequests')]
    function testRestrictionPreservesAdministratorReadWithoutDeletingCards(bool $administrator, array $request) {
        $this->actor(false, $administrator ? $this->userTestId2 : $this->userTestId1);
        $before = $this->callDav(...$request);
        $this->assertContains($before->status, [200, 207], $before->getBodyAsString());
        $this->actor(true);
        $this->assertEquals(200, $this->changeAccess('restricted')->status);
        $this->actor(false, $administrator ? $this->userTestId2 : $this->userTestId1);
        $after = $this->callDav(...$request);
        $this->assertEquals($administrator ? $before->status : 403, $after->status, $after->getBodyAsString());
        if (!$administrator) {
            $this->assertStringNotContainsString('secret@example.org', $after->getBodyAsString());
        }
        $this->assertNotFalse($this->carddavBackend->getCard($this->domainBookId, 'probe.vcf'));
    }

    function testTechnicalAccessAndRestoreWorkWithEmptyMemberRights() {
        $this->actor(true);
        $this->assertEquals(200, $this->changeAccess('restricted')->status);
        $this->assertEquals(200, $this->changeAccess('restricted')->status);
        $this->assertEquals(200, $this->callDav('GET', '/probe.vcf')->status);
        $write = $this->callDav('PUT', '/new.vcf', str_replace('UID:probe', 'UID:new', self::CARD), ['Content-Type' => 'text/vcard']);
        $this->assertEquals(201, $write->status, $write->getBodyAsString());
        $this->actor();
        $this->assertEquals(403, $this->callDav('GET', '/new.vcf')->status);
        $this->actor(true);
        $this->assertEquals(200, $this->changeAccess('members')->status);
        $this->assertEquals(200, $this->changeAccess('members')->status);
        $this->actor();
        $this->assertEquals(200, $this->callDav('GET', '/probe.vcf')->status);
        $this->assertEquals(200, $this->callDav('GET', '/new.vcf')->status);
    }

    function testLegacyDelegationCannotBypassRestrictionInDirectOrAggregatedReads() {
        $this->carddavBackend->updateInvites($this->domainBookId, [new \Sabre\DAV\Xml\Element\Sharee([
            'href' => 'mailto:' . $this->userTestEmail3,
            'principal' => 'principals/users/' . $this->userTestId3,
            'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_READ,
            'inviteStatus' => \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED,
            'properties' => []
        ])]);
        $this->actor(false, $this->userTestId3);
        $this->assertEquals(200, $this->callDav('GET', '/probe.vcf')->status);
        $this->actor(true);
        $this->assertEquals(200, $this->changeAccess('restricted')->status);
        $this->actor(false, $this->userTestId3);
        $this->assertEquals(403, $this->callDav('GET', '/probe.vcf')->status);
        $mirrors = $this->carddavBackend->getSharedAddressBooksForUser('principals/users/' . $this->userTestId3);
        $this->assertCount(1, $mirrors);
        // Domain group mirrors are already removed from a member's home by AddressBookHome.
        $mirrorPath = '/addressbooks/' . $this->userTestId3 . '/' . $mirrors[0]['uri'] . '/probe.vcf';
        $response = $this->request(\Sabre\HTTP\Sapi::createFromServerArray([
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => $mirrorPath
        ]));
        $this->assertEquals(404, $response->status, $response->getBodyAsString());
        $this->server->tree->markDirty('');
        $response = $this->makeRequest('GET', '/contacts/' . $this->userTestId3 . '.json?domainMember=true');
        $this->assertEquals(200, $response->status, $response->getBodyAsString());
        $this->assertStringNotContainsString('secret@example.org', $response->getBodyAsString());
    }

    function testAggregatedDirectoryFollowsDefaultAndReplacedAcl() {
        $path = '/contacts/' . $this->userTestId1 . '.json?domainMember=true';
        $this->actor();
        // Point lookups for the caller are expected; enumerating users of the domain is not.
        $memberScans = \ESN\DAV\MongoFindCounter::count('users', function () use ($path) {
            $response = $this->makeRequest('GET', $path);
            $this->assertEquals(200, $response->status);
            $this->assertStringContainsString('secret@example.org', $response->getBodyAsString());
        }, fn($command) => isset($command->filter->domains) || isset($command->filter->{'domains.domain_id'}));
        $this->assertSame(0, $memberScans);
        foreach (['restricted', 'members'] as $policy) {
            $this->actor(true);
            $this->assertEquals(200, $this->changeAccess($policy)->status);
            $this->actor();
            $response = $this->makeRequest('GET', $path);
            $this->assertEquals(200, $response->status);
            $this->assertSame($policy === 'members', str_contains($response->getBodyAsString(), 'secret@example.org'));
        }
    }

    function testAggregatedListingStillWorksWhenTheDomainHasNoDirectory() {
        $this->carddavBackend->deleteAddressBook($this->domainBookId);
        $this->actor();
        $response = $this->makeRequest('GET', '/contacts/' . $this->userTestId1 . '.json?domainMember=true');
        $this->assertEquals(200, $response->status, $response->getBodyAsString());
        $this->assertStringNotContainsString('secret@example.org', $response->getBodyAsString());
    }

    function testAclCanGrantOnePrincipalAndIsReportedThroughPropfind() {
        $this->actor(true);
        $body = '<d:acl xmlns:d="DAV:"><d:ace><d:principal><d:href>/principals/users/' . $this->userTestId1
            . '</d:href></d:principal><d:grant><d:privilege><d:read/></d:privilege></d:grant></d:ace></d:acl>';
        $this->assertEquals(200, $this->callDav('ACL', '', $body)->status);
        $this->actor();
        $this->assertEquals(200, $this->callDav('GET', '/probe.vcf')->status);
        $this->actor(false, $this->userTestId3);
        $this->assertEquals(403, $this->callDav('GET', '/probe.vcf')->status);
        $this->actor(false, $this->userTestId2);
        $this->assertEquals(200, $this->callDav('GET', '/probe.vcf')->status);
        $this->actor(true);
        $response = $this->callDav('PROPFIND', '', '<d:propfind xmlns:d="DAV:"><d:prop><d:acl/></d:prop></d:propfind>');
        $this->assertEquals(207, $response->status);
        $this->assertStringContainsString('principals/users/' . $this->userTestId1, $response->getBodyAsString());
        $this->assertStringContainsString('principals/users/' . $this->userTestId2, $response->getBodyAsString());
        $this->assertStringNotContainsString('principals/users/' . $this->userTestId2,
            $this->editableAclFromPropfind($response->getBodyAsString()));
    }

    private function editableAclFromPropfind(string $xml): string {
        $document = new \DOMDocument();
        $document->loadXML($xml);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('d', 'DAV:');
        $snapshot = new \DOMDocument();
        $root = $snapshot->createElementNS('DAV:', 'd:acl');
        $snapshot->appendChild($root);
        foreach ($xpath->query('/d:multistatus/d:response[1]/d:propstat/d:prop/d:acl/d:ace[not(d:protected) and not(d:inherited)]') as $ace) {
            $root->appendChild($snapshot->importNode($ace, true));
        }
        return $snapshot->saveXML();
    }

    function testSavedAclCanBeRestoredWithoutReplacingAdministratorRights() {
        $this->actor(true);
        $propfind = '<d:propfind xmlns:d="DAV:"><d:prop><d:acl/></d:prop></d:propfind>';
        $before = $this->callDav('PROPFIND', '', $propfind);
        $this->assertEquals(207, $before->status);
        $savedAcl = $this->editableAclFromPropfind($before->getBodyAsString());
        $this->assertStringNotContainsString('write', $savedAcl);
        $this->assertStringNotContainsString('technicalUser', $savedAcl);
        $this->assertEquals(200, $this->changeAccess('restricted')->status);
        $this->actor(false, $this->userTestId2);
        $this->assertEquals(200, $this->callDav('GET', '/probe.vcf')->status);
        $this->actor();
        $this->assertEquals(403, $this->callDav('GET', '/probe.vcf')->status);
        $this->actor(true);
        $this->assertEquals(200, $this->callDav('ACL', '', $savedAcl)->status);
        $after = $this->callDav('PROPFIND', '', $propfind);
        $this->assertEquals(207, $after->status);
        $this->assertSame($savedAcl, $this->editableAclFromPropfind($after->getBodyAsString()));
        $this->actor(false, $this->userTestId2);
        $this->assertEquals(200, $this->callDav('GET', '/probe.vcf')->status);
        $this->assertEquals(204, $this->callDav('PROPPATCH', '.json', '{"dav:name":"Restored directory"}', ['Content-Type' => 'application/json', 'Accept' => 'application/json'])->status);
    }

    function testAdministratorRightsFollowCurrentDomainRoleWithAnEmptyAcl() {
        $this->actor(true);
        $this->assertEquals(200, $this->changeAccess('restricted')->status);
        $this->actor(false, $this->userTestId2);
        $this->assertEquals(200, $this->callDav('GET', '/probe.vcf')->status);
        $this->assertEquals(403, $this->callDav('PUT', '/new.vcf', self::CARD, ['Content-Type' => 'text/vcard'])->status);
        $response = $this->makeRequest('GET', '/contacts/' . $this->userTestId2 . '.json?domainMember=true');
        $this->assertEquals(200, $response->status);
        $this->assertStringContainsString('secret@example.org', $response->getBodyAsString());

        $this->esndb->domains->updateOne(['_id' => new \MongoDB\BSON\ObjectId(SERVER_MOCK_DOMAIN_ID)],
            ['$set' => ['administrators' => [['user_id' => new \MongoDB\BSON\ObjectId($this->userTestId3)]]]]);
        foreach ([$this->userTestId2 => false, $this->userTestId3 => true] as $user => $isAdministrator) {
            $this->actor(false, $user);
            $this->assertEquals($isAdministrator ? 200 : 403, $this->callDav('GET', '/probe.vcf')->status);
            $response = $this->makeRequest('GET', '/contacts/' . $user . '.json?domainMember=true');
            $this->assertEquals(200, $response->status);
            $this->assertSame($isAdministrator, str_contains($response->getBodyAsString(), 'secret@example.org'));
        }
    }

    #[DataProvider('readRequests')]
    function testForeignDomainMembersAndAdministratorsCannotReadDirectory(bool $administrator, array $request) {
        $foreignDomain = '54b64eadf6d7d8e41d263e7b';
        $foreignUser = '54b64eadf6d7d8e41d263e7c';
        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($foreignDomain), 'name' => 'foreign.example',
            'administrators' => $administrator ? [['user_id' => new \MongoDB\BSON\ObjectId($foreignUser)]] : []
        ]);
        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($foreignUser), 'firstname' => 'Foreign', 'lastname' => 'User',
            'accounts' => [['type' => 'email', 'emails' => ['user@foreign.example']]],
            'domains' => [['domain_id' => new \MongoDB\BSON\ObjectId($foreignDomain)]]
        ]);
        foreach (['default', 'restricted'] as $policy) {
            if ($policy === 'restricted') {
                $this->actor(true);
                $this->assertEquals(200, $this->changeAccess('restricted')->status);
            }
            $this->authBackend->setAuthTenant(new AuthTenant($foreignUser, $foreignDomain, TenantType::User));
            $response = $this->callDav(...$request);
            $this->assertEquals(403, $response->status, $response->getBodyAsString());
            $this->assertStringNotContainsString('secret@example.org', $response->getBodyAsString());
        }
    }

    function testAbstractPrivilegeIsRejectedWithItsStandardCondition() {
        $this->server->on('getSupportedPrivilegeSet', function($node, &$privileges) {
            $privileges['{urn:test}abstract'] = ['abstract' => true, 'aggregates' => []];
        });
        $this->actor(true);
        $body = '<d:acl xmlns:d="DAV:" xmlns:t="urn:test"><d:ace><d:principal><d:href>/principals/domains/'
            . SERVER_MOCK_DOMAIN_ID . '</d:href></d:principal><d:grant><d:privilege><t:abstract/></d:privilege></d:grant></d:ace></d:acl>';
        $response = $this->callDav('ACL', '', $body);
        $this->assertEquals(403, $response->status, $response->getBodyAsString());
        $this->assertStringContainsString('no-abstract', $response->getBodyAsString());
    }

}
