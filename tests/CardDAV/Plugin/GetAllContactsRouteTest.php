<?php

namespace ESN\CardDAV\Plugin;

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
}
