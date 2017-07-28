<?php
namespace ESN\Publisher\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Document;
use Sabre\Uri;
use Sabre\Event\EventEmitter;

class SubscriptionRealTimePlugin extends \ESN\Publisher\RealTimePlugin {

    protected $eventEmitter;

    private $SUBSCRIPTION_TOPICS = [
        'SUBSCRIPTION_CREATED' => 'calendar:subscription:created',
        'SUBSCRIPTION_UPDATED' => 'calendar:subscription:updated',
        'SUBSCRIPTION_DELETED' => 'calendar:subscription:deleted',
    ];

    function __construct($client, $eventEmitter) {
        parent::__construct($client);
        $this->eventEmitter = $eventEmitter;
    }

    function initialize(Server $server) {
        parent::initialize($server);

        $this->eventEmitter->on('esn:subscriptionCreated', [$this, 'subscriptionCreated']);
        $this->eventEmitter->on('esn:subscriptionUpdated', [$this, 'subscriptionUpdated']);
        $this->eventEmitter->on('esn:subscriptionDeleted', [$this, 'subscriptionDeleted']);
    }

    function buildData($data) {
        return $data;
    }

    function prepareAndPublishMessages($path, $topic, $sourcePath = null) {
        $this->createMessage($topic, ['calendarPath' => $path, 'calendarSourcePath' => $sourcePath]);
        $this->publishMessages();
    }

    function subscriptionCreated($path) {
        $this->prepareAndPublishMessages($path, $this->SUBSCRIPTION_TOPICS['SUBSCRIPTION_CREATED']);
    }

    function subscriptionUpdated($path) {
        $this->prepareAndPublishMessages($path, $this->SUBSCRIPTION_TOPICS['SUBSCRIPTION_UPDATED']);
    }

    function subscriptionDeleted($path, $sourcePath) {
        $this->prepareAndPublishMessages($path, $this->SUBSCRIPTION_TOPICS['SUBSCRIPTION_DELETED'], $sourcePath);
    }
}
