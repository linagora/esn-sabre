<?php
namespace ESN\Publisher\CardDAV;
use Sabre\DAV\ServerPlugin;

require_once ESN_TEST_BASE . '/CardDAV/MockUtils.php';

class ContactRealTimePluginTest extends \PHPUnit_Framework_TestCase {

    private function getPlugin($server = null) {
        $plugin = new ContactRealTimePluginMock($server);
        $server = $plugin->getServer();
        $this->mockTree($server);

        return $plugin;
    }

    private function mockTree($server) {
        $server->tree = $this->getMockBuilder('\Sabre\DAV\Tree')->disableOriginalConstructor()->getMock();

        $nodeMock = $this->createCard();

        $server->tree->expects($this->any())->method('getNodeForPath')
            ->will($this->returnValue($nodeMock));
    }

    private function createCard() {
        $addressBookInfo = [
            'uri' => 'addressbooks/456456/123123',
            'id' => '123123',
            'principaluri' => 'principals/users/456456'
        ];
        $cardData = [
            'carddata' => "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:hello\r\nEND:VCARD\r\n"
        ];

        return new \Sabre\CardDAV\Card(new \ESN\CardDAV\CardDAVBackendMock(), $addressBookInfo, $cardData);
    }

    function testAfterCreateFileEventShouldDoNothingWithNonCardPath() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $this->assertTrue($server->emit('afterCreateFile', ["not_a_card_path"]));
        $this->assertNull($client->message);
    }

    function testAfterCreateFileEventShouldPublishMessage() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $this->assertTrue($server->emit('afterCreateFile', ["addressbooks/123/contacts/456.vcf"]));
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

        $nodeMock = $this->createCard();

        $this->assertTrue($server->emit('afterWriteContent', ["addressbooks/123/contacts/456.vcf", $nodeMock]));
        $this->assertNotNull($client->message);
    }

    function testAfterMoveEventShouldDoNothingWithNonCardPath() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $this->assertTrue($server->emit('afterMove', ["not_a_card_path", "addressbooks/123/collected/456.vcf"]));
        $this->assertNull($client->message);
    }

    function testAfterMoveEventShouldPublishMessage() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $this->assertTrue($server->emit('afterMove', ["addressbooks/123/contacts/456.vcf", "addressbooks/123/collected/456.vcf"]));
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

        $this->assertTrue($server->emit('afterUnbind', ["addressbooks/123/contacts/456.vcf"]));
        $this->assertNotNull($client->message);
    }

    function testIgnoreAfterUnbindEventWhenAfterMoveEventIsFiredBefore() {
        $plugin = $this->getPlugin();
        $server = $plugin->getServer();
        $client = $plugin->getClient();

        $this->assertTrue($server->emit('afterMove', ["addressbooks/123/contacts/456.vcf", "addressbooks/123/collected/456.vcf"]));
        $this->assertNotNull($client->message);

        $client->message = null;

        $this->assertTrue($server->emit('afterUnbind', ["addressbooks/123/contacts/456.vcf"]));
        $this->assertNull($client->message);
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
