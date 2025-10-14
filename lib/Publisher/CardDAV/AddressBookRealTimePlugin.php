<?php
namespace ESN\Publisher\CardDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\Uri;
use Sabre\Event\EventEmitter;

#[\AllowDynamicProperties]
class AddressBookRealTimePlugin extends \ESN\Publisher\RealTimePlugin {

    protected $carddavBackend;

    private $PUBSUB_TOPICS = [
        'ADDRESSBOOK_CREATED' => 'sabre:addressbook:created',
        'ADDRESSBOOK_DELETED' => 'sabre:addressbook:deleted',
        'ADDRESSBOOK_UPDATED' => 'sabre:addressbook:updated'
    ];

    function __construct($client, $carddavBackend) {
        parent::__construct($client);
        $this->carddavBackend = $carddavBackend;
    }

    function initialize(Server $server) {
        parent::initialize($server);

        $eventEmitter = $this->carddavBackend->getEventEmitter();

        $eventEmitter->on('sabre:addressBookCreated', [$this, 'onAddressBookCreated']);
        $eventEmitter->on('sabre:addressBookDeleted', [$this, 'onAddressBookDeleted']);
        $eventEmitter->on('sabre:addressBookUpdated', [$this, 'onAddressBookUpdated']);
    }

    function buildData($data) {
        return $data;
    }

    function onAddressBookCreated($data) {
        $this->createMessage(
            $this->PUBSUB_TOPICS['ADDRESSBOOK_CREATED'],
            [
                'path' => $data['path'],
                'owner' => $data['principaluri']
            ]
        );

        $this->publishMessages();
    }

    function onAddressBookDeleted($data) {
        $this->createMessage(
            $this->PUBSUB_TOPICS['ADDRESSBOOK_DELETED'],
            [
                'path' => $data['path'],
                'owner' => $data['principaluri']
            ]
        );

        $this->publishMessages();
    }

    function onAddressBookUpdated($data) {
        $this->createMessage(
            $this->PUBSUB_TOPICS['ADDRESSBOOK_UPDATED'],
            [
                'path' => $data['path']
            ]
        );

        $this->publishMessages();
    }
}
