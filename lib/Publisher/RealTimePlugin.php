<?php
namespace ESN\Publisher;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component;
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

    /**
     * The messages of one write share their VObject components (the alarm
     * topic, every subscriber and every invitee carry the same event and
     * old_event), and encoding a component rebuilds its whole jCal tree. Each
     * component is therefore converted once for the batch, keyed by object
     * identity: an invitee's sanitised clone is a distinct object and keeps its
     * own jCal. Every message is built before any is encoded, so that a
     * component completed by buildData (PRODID, DTSTAMP) is never cached in an
     * earlier state.
     */
    public function publishMessages() {
        $connectedUser = $this->getConnectedUser();

        $built = [];
        foreach($this->messages as $message) {
            try {
                $message['data'] = $this->buildData($message['data']);
                if (is_array($message['data'])) {
                    $message['data']['connectedUser'] = $connectedUser;
                }
                $built[] = $message;
            } catch (\Exception $e) {
                $this->logSkippedMessage($message, $e);
            }
        }
        $this->messages = array();

        $jcalByComponent = new \SplObjectStorage();
        foreach($built as $message) {
            try {
                $data = $this->withSerializedComponents($message['data'], $jcalByComponent);
                // Parameter values (e.g. a Latin-1 ATTENDEE;CN) are not repaired to UTF-8 by Sabre
                // validation: substitute invalid bytes rather than publishing `false` as the body.
                $payload = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                $this->client->publish($message['topic'], $payload);
            } catch (\Exception $e) {
                $this->logSkippedMessage($message, $e);
            }
        }
    }

    /**
     * $data with its top-level VObject components replaced by their jCal,
     * which json_encode turns into the same bytes as the component itself.
     */
    private function withSerializedComponents($data, \SplObjectStorage $jcalByComponent) {
        if (!is_array($data)) {
            return $data;
        }

        foreach ($data as $key => $value) {
            if ($value instanceof Component) {
                if (!$jcalByComponent->contains($value)) {
                    $jcalByComponent[$value] = $value->jsonSerialize();
                }
                $data[$key] = $jcalByComponent[$value];
            }
        }

        return $data;
    }

    private function logSkippedMessage($message, \Exception $e) {
        // Be lenient: a malformed event (e.g. an invalid RRULE UNTIL value) that cannot be
        // serialized must not crash the request that triggered the notification. The event
        // has already been persisted; we log and skip its realtime message.
        error_log('RealTimePlugin: skipping realtime message on topic ' . $message['topic']
            . ' due to serialization error: ' . $e->getMessage());
    }
}
