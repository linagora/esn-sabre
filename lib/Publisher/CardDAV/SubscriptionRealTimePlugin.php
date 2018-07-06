<?php
namespace ESN\Publisher\CardDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\Uri;
use Sabre\Event\EventEmitter;

class SubscriptionRealTimePlugin extends \ESN\Publisher\RealTimePlugin {

    protected $carddavBackend;

    private $PUBSUB_TOPICS = [
        'ADDRESSBOOK_SUBSCRIPTION_DELETED' => 'sabre:addressbook:subscription:deleted',
    ];

    function __construct($client, $carddavBackend) {
        parent::__construct($client);
        $this->carddavBackend = $carddavBackend;
    }

    function initialize(Server $server) {
        parent::initialize($server);

        $eventEmitter = $this->carddavBackend->getEventEmitter();

        $eventEmitter->on('sabre:addressBookSubscriptionDeleted', [$this, 'onAddressBookSubscriptionDeleted']);
    }

    function buildData($data) {
        return $data;
    }

    function onAddressBookSubscriptionDeleted($data) {
        $this->createMessage(
            $this->PUBSUB_TOPICS['ADDRESSBOOK_SUBSCRIPTION_DELETED'],
            [
                'path' => $data['path'],
                'owner' => $data['principaluri']
            ]
        );

        $this->publishMessages();
    }
}
