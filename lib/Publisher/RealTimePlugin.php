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

    public function publishMessages() {
        foreach($this->messages as $message) {
            try {
                $message['data'] = $this->buildData($message['data']);
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
