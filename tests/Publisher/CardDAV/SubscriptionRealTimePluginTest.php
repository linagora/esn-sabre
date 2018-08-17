<?php
namespace ESN\Publisher\CardDAV;


require_once ESN_TEST_BASE . '/Publisher/CardDAV/CardDAVBackendMock.php';

class SubscriptionRealTimePluginTest extends \PHPUnit_Framework_TestCase {

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
        $this->plugin = new SubscriptionRealTimePlugin($this->publisher, $this->cardDAVBackend, $this->getMock(\Sabre\Event\EventEmitter::class));

        $this->server = $this->getMock(\Sabre\DAV\Server::class);

        $this->plugin->initialize($this->server);
    }

    function testAddressBookSubscriptionDeleted() {
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('sabre:addressbook:subscription:deleted');
        $this->plugin->onAddressBookSubscriptionDeleted([
            'path' => self::PATH,
            'principaluri' => self::PRINCIPAL_URI
        ]);
    }

    function testAddressBookSubscriptionUpdated() {
        $this->publisher
            ->expects($this->once())
            ->method('publish')
            ->with('sabre:addressbook:subscription:updated');
        $this->plugin->onAddressBookSubscriptionUpdated([
            'path' => self::PATH
        ]);
    }
}
