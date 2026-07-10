<?php
namespace ESN\Publisher;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Document;
use Sabre\Uri;
use Sabre\Event\EventEmitter;

abstract class RealTimePlugin extends ServerPlugin {

    protected $messages = array();
    protected $client;
    protected $server;

    public function __construct($client) {
        $this->client = $client;
    }

    public function initialize(Server $server) {
        $this->server = $server;
    }

    abstract protected function buildData($data);

    public function createMessage($topic, $data) {
        $this->messages[] = [
            'topic' => $topic,
            'data' =>  $data
        ];
    }

    public function getMessages() {
        return $this->messages;
    }

    /**
     * Returns the principal URI of the currently authenticated user, i.e. who
     * actually performed the action that triggers this realtime message. This
     * may differ from the resource owner (delegation, admin impersonation,
     * technical token), which is exactly why it is worth reporting for
     * auditability. Returns null when no user is authenticated.
     */
    protected function getConnectedUser() {
        if (!$this->server) {
            return null;
        }

        $authPlugin = $this->server->getPlugin('auth');

        return $authPlugin ? $authPlugin->getCurrentPrincipal() : null;
    }

    public function publishMessages() {
        $connectedUser = $this->getConnectedUser();

        foreach($this->messages as $message) {
            try {
                $message['data'] = $this->buildData($message['data']);
                if (is_array($message['data'])) {
                    $message['data']['connectedUser'] = $connectedUser;
                }
                $this->client->publish($message['topic'], json_encode($message['data']));
            } catch (\Exception $e) {
                // Be lenient: a malformed event (e.g. an invalid RRULE UNTIL value) that cannot be
                // serialized must not crash the request that triggered the notification. The event
                // has already been persisted; we log and skip its realtime message.
                error_log('RealTimePlugin: skipping realtime message on topic ' . $message['topic']
                    . ' due to serialization error: ' . $e->getMessage());
            }
        }

        $this->messages = array();
    }
}
