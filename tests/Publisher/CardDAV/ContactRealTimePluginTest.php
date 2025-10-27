<?php
namespace ESN\Publisher\CardDAV;
use Sabre\DAV\ServerPlugin;

require_once ESN_TEST_BASE . '/CardDAV/MockUtils.php';

class ContactRealTimePluginTest extends \PHPUnit\Framework\TestCase {

    const SHARED_ADDRESSBOOK = [[
        'id' => 'shared-one',
        'principaluri' => '/principals/users/cardo',
        'uri' => 'addressbooks/cardo/shared-one'
    ]];
    const USER_PRINCIPAL = 'principals/users/userId';
    const DOMAIN_PRINCIPAL = 'principals/domains/domainId';

    private function getPlugin($server = null) {
        $plugin = new ContactRealTimePluginMock($server);
        $server = $plugin->getServer();
        $this->mockTree($server);

        return $plugin;
    }

    private function mockTree($server) {
        $contactMock = $this->getMockBuilder('\Sabre\CardDAV\Card')
            ->disableOriginalConstructor()
            ->getMock();
        $contactMock->method('getOwner')
            ->will($this->returnValue(self::USER_PRINCIPAL));
        $addressbookMock = $this->getMockBuilder('\ESN\CardDAV\AddressBook')
            ->disableOriginalConstructor()
            ->getMock();
        $addressbookMock->method('getSubscribedAddressBooks')
            ->will($this->returnValue(self::SHARED_ADDRESSBOOK));

        $principalMock = $this->getMockBuilder('\Sabre\DAVACL\Principal')
            ->disableOriginalConstructor()
            ->getMock();
        $principalMock->method('getGroupMemberSet')
            ->will($this->returnValue([ self::USER_PRINCIPAL ]));
        
        $groupAddressBookMock = $this->getMockBuilder('\ESN\CardDAV\AddressBook')
            ->disableOriginalConstructor()
            ->getMock();
        $groupAddressBookMock->method('getSubscribedAddressBooks')
            ->will($this->returnValue([]));
        $groupContactMock = $this->getMockBuilder('\Sabre\CardDAV\Card')
            ->disableOriginalConstructor()
            ->getMock();
        
        $groupContactMock->method('getOwner')
            ->will($this->returnValue(self::DOMAIN_PRINCIPAL));

        $map = [
            ['/addressbooks/bookId/bookName/contact.vcf', $contactMock],
            ['/addressbooks/bookId/bookName', $addressbookMock],
            ['/addressbooks/domainId/dab', $groupAddressBookMock],
            ['/addressbooks/domainId/dab/gcontact.vcf', $groupContactMock],
            [self::DOMAIN_PRINCIPAL, $principalMock]
        ];

        $server->tree = $this->getMockBuilder('\Sabre\DAV\Tree')
            ->disableOriginalConstructor()
            ->getMock();

        $server->tree
            ->expects($this->any())
            ->method('getNodeForPath')
            ->will($this->returnValueMap($map));
    }

    function testAfterBindEventShouldDoNothingWithNonCardPath() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $this->assertTrue($server->emit('afterBind', ['not_a_card_path']));
        $this->assertNull($client->message);
    }

    function testAfterBindEventShouldPublishMessage() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $this->assertTrue($server->emit('afterBind', ['addressbooks/bookId/bookName/contact.vcf']));
        $this->assertNotNull($client->message);
    }

    function testAfterBindGroupContactEventShouldPublishMessage() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $this->assertTrue($server->emit('afterBind', ['addressbooks/domainId/dab/gcontact.vcf']));
        $this->assertNotNull($client->message);
    }

    function testAfterWriteContentEventShouldDoNothingWithNonCardNode() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $nodeMock = new \Sabre\DAV\SimpleFile("filename", "contents");

        $this->assertTrue($server->emit('afterWriteContent', ["not_a_card_path", $nodeMock]));
        $this->assertNull($client->message);
    }

    function testAfterWriteContentEventShouldPublishMessage() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $nodeMock = $this->getMockBuilder('\Sabre\CardDAV\Card')
            ->disableOriginalConstructor()
            ->getMock();
        $nodeMock->method('getOwner')
            ->will($this->returnValue(self::USER_PRINCIPAL));

        $this->assertTrue($server->emit('afterWriteContent', ["addressbooks/bookId/bookName/contact.vcf", $nodeMock]));
        $this->assertNotNull($client->message);
    }

    function testAfterWriteContentGroupContactEventShouldPublishMessage() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $nodeMock = $this->getMockBuilder('\Sabre\CardDAV\Card')
            ->disableOriginalConstructor()
            ->getMock();
        $nodeMock->method('getOwner')
            ->will($this->returnValue(self::DOMAIN_PRINCIPAL));

        $this->assertTrue($server->emit('afterWriteContent', ['addressbooks/domainId/dab/gcontact.vcf', $nodeMock]));
        $this->assertNotNull($client->message);
    }

    function testAfterUnbindEventShouldDoNothingWithNonCardPath() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $this->assertTrue($server->emit('afterUnbind', ["not_a_card_path"]));
        $this->assertNull($client->message);
    }

    function testAfterUnbindEventShouldPublishMessage() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $this->assertTrue($server->emit('afterUnbind', ["addressbooks/bookId/bookName/contact.vcf"]));
        $this->assertNotNull($client->message);
    }

    function testAfterUnbindGroupContactEventShouldPublishMessage() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $this->assertTrue($server->emit('afterUnbind', ['addressbooks/domainId/dab/gcontact.vcf']));
        $this->assertNotNull($client->message);
    }
}

class ClientMock implements \ESN\Publisher\Publisher {
    public $topic;
    public $message;

    function publish($topic, $message) {
        $this->topic = $topic;
        $this->message = $message;
    }
}

class ContactRealTimePluginMock extends ContactRealTimePlugin {

    function __construct($server) {
        if (!$server) $server = new \Sabre\DAV\Server([]);
        $this->initialize($server);
        $this->client = new ClientMock();
        $this->server = $server;
    }

    function getClient() {
        return $this->client;
    }

    function getMessage() {
        return $this->message;
    }

    function getServer() {
        return $this->server;
    }
}
