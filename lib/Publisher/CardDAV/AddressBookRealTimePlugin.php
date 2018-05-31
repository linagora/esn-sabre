<?php
namespace ESN\Publisher\CardDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\Uri;
use Sabre\Event\EventEmitter;

class AddressBookRealTimePlugin extends \ESN\Publisher\RealTimePlugin {

    protected $carddavBackend;

    private $PUBSUB_TOPICS = [
        'ADDRESSBOOK_DELETED' => 'sabre:addressbook:deleted',
    ];

    function __construct($client, $carddavBackend) {
        parent::__construct($client);
        $this->carddavBackend = $carddavBackend;
    }

    function initialize(Server $server) {
        parent::initialize($server);

        $eventEmitter = $this->carddavBackend->getEventEmitter();

        $eventEmitter->on('sabre:addressBookDeleted', [$this, 'onAddressBookDeleted']);
    }

    function buildData($data) {
        return $data;
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
}
