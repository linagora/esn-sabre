<?php
namespace ESN\Publisher\CardDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\Uri;
use Sabre\Event\EventEmitter;

#[\AllowDynamicProperties]
class SubscriptionRealTimePlugin extends \ESN\Publisher\RealTimePlugin {

    protected $carddavBackend;

    private $PUBSUB_TOPICS = [
        'ADDRESSBOOK_SUBSCRIPTION_DELETED' => 'sabre:addressbook:subscription:deleted',
        'ADDRESSBOOK_SUBSCRIPTION_UPDATED' => 'sabre:addressbook:subscription:updated',
        'ADDRESSBOOK_SUBSCRIPTION_CREATED' => 'sabre:addressbook:subscription:created'
    ];

    function __construct($client, $carddavBackend) {
        parent::__construct($client);
        $this->carddavBackend = $carddavBackend;
    }

    function initialize(Server $server) {
        parent::initialize($server);

        $eventEmitter = $this->carddavBackend->getEventEmitter();

        $eventEmitter->on('sabre:addressBookSubscriptionDeleted', [$this, 'onAddressBookSubscriptionDeleted']);
        $eventEmitter->on('sabre:addressBookSubscriptionUpdated', [$this, 'onAddressBookSubscriptionUpdated']);
        $eventEmitter->on('sabre:addressBookSubscriptionCreated', [$this, 'onAddressBookSubscriptionCreated']);
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

    function onAddressBookSubscriptionUpdated($data) {
        $this->createMessage(
            $this->PUBSUB_TOPICS['ADDRESSBOOK_SUBSCRIPTION_UPDATED'],
            [
                'path' => $data['path']
            ]
        );

        $this->publishMessages();
    }

    function onAddressBookSubscriptionCreated($data) {
        $this->createMessage(
            $this->PUBSUB_TOPICS['ADDRESSBOOK_SUBSCRIPTION_CREATED'],
            [
                'path' => $data['path']
            ]
        );

        $this->publishMessages();
    }
}
