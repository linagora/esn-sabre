<?php
namespace ESN\Publisher\CardDAV;

require_once ESN_TEST_BASE . '/Publisher/CardDAV/CardDAVBackendMock.php';

class AddressBookRealTimePluginTest extends \PHPUnit\Framework\TestCase {

    const PATH = "addressbooks/456456/123123";
    const PRINCIPAL_URI = "principals/users/456456";
    protected $eventEmitter;
    protected $plugin;
    protected $publisher;
    protected $server;

    function setUp() {
        $this->cardDAVBackend = new CardDAVBackendMock();
        $this->cardDAVBackend->setEventEmitter($this->createMock(\Sabre\Event\EventEmitter::class));

        $this->publisher = $this->createMock(\ESN\Publisher\Publisher::class);
        $this->plugin = new AddressBookRealTimePlugin($this->publisher, $this->cardDAVBackend, $this->createMock(\Sabre\Event\EventEmitter::class));

        $this->server = $this->createMock(\Sabre\DAV\Server::class);

        $this->plugin->initialize($this->server);
    }

    function testAddressBookCreated() {
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('sabre:addressbook:created');
        $this->plugin->onAddressBookCreated([
            'path' => self::PATH,
            'principaluri' => self::PRINCIPAL_URI
        ]);
    }

    function testAddressBookDeleted() {
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('sabre:addressbook:deleted');
        $this->plugin->onAddressBookDeleted([
            'path' => self::PATH,
            'principaluri' => self::PRINCIPAL_URI
        ]);
    }

    function testAddressBookUpdated() {
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('sabre:addressbook:updated');
        $this->plugin->onAddressBookUpdated([
            'path' => self::PATH,
        ]);
    }
}
