<?php
namespace ESN\Publisher\CardDAV;

require_once ESN_TEST_BASE . '/CardDAV/MockUtils.php';

class AddressBookRealTimePluginTest extends \PHPUnit_Framework_TestCase {

    const PATH = "addressbooks/456456/123123";
    const PRINCIPAL_URI = "principals/users/456456";
    protected $eventEmitter;
    protected $plugin;
    protected $publisher;
    protected $server;

    function setUp() {
        $this->cardDAVBackend = new CardDAVBackendMock();
        $this->cardDAVBackend->setEventEmitter($this->getMock(\Sabre\Event\EventEmitter::class));

        $this->publisher = $this->getMock(\ESN\Publisher\Publisher::class);
        $this->plugin = new AddressBookRealTimePlugin($this->publisher, $this->cardDAVBackend, $this->getMock(\Sabre\Event\EventEmitter::class));

        $this->server = $this->getMock(\Sabre\DAV\Server::class);

        $this->plugin->initialize($this->server);
    }

    function testAddressBookDeleted() {
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('sabre:addressbook:deleted');
        $this->plugin->onAddressBookDeleted(self::PATH, self::PRINCIPAL_URI);
    }
}

class CardDAVBackendMock extends \ESN\CardDAV\CardDAVBackendMock {

    protected $eventEmitter;

    function setEventEmitter($value) {
        $this->eventEmitter = $value;
    }

    function getEventEmitter() {
        return $this->eventEmitter;
    }
}
