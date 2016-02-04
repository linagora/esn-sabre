<?php
namespace ESN\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;

class CalDAVPublisherPlugin extends ServerPlugin {
    protected $server;
    protected $message;

    private $REDIS_EVENTS = 'calendar:event:updated';

    private $WS_EVENTS = [
      'EVENT_CREATED' => 'calendar:ws:event:created',
      'EVENT_UPDATED' => 'calendar:ws:event:updated',
      'EVENT_DELETED' => 'calendar:ws:event:deleted'
    ];

    function __construct($client) {
        $this->client = $client;
    }

    function initialize(Server $server) {
        $this->server = $server;

        $server->on('beforeCreateFile',   [$this, 'beforeCreateFile']);
        $server->on('afterCreateFile',    [$this, 'after']);

        $server->on('beforeWriteContent', [$this, 'beforeWriteContent']);
        $server->on('afterWriteContent',  [$this, 'after']);

        $server->on('beforeUnbind',       [$this, 'beforeUnbind']);
        $server->on('afterUnbind',        [$this, 'after']);
    }

    function after($path) {
        $this->publishMessage();
        return true;
    }

    function beforeUnbind($path) {
        $node = $this->server->tree->getNodeForPath($path);
        if (!($node instanceof \Sabre\CalDAV\CalendarObject)) {
            return true;
        }

        $body = json_encode([
            'event_id' => '/' . $path,
            'type' => 'deleted',
            'event' => $node->get(),
            'websocketEvent' => $this->WS_EVENTS['EVENT_DELETED']
        ]);

        $this->createMessage($path, $body);
        return true;
    }

    function beforeCreateFile($path, &$data, \Sabre\DAV\ICollection $parent, &$modified) {
        if (!($parent instanceof \Sabre\CalDAV\Calendar)) {
            return true;
        }

        $body = json_encode([
            'event_id' => '/' . $path,
            'type' => 'created',
            'event' => $data,
            'websocketEvent' => $this->WS_EVENTS['EVENT_CREATED']
        ]);

        $this->createMessage($path, $body);
        return true;
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        if (!($node instanceof \Sabre\CalDAV\CalendarObject)) {
            return true;
        }

        $body = json_encode([
            'event_id' => '/' .$path,
            'type' => 'updated',
            'event' => $data,
            'old_event' => $node->get(),
            'etag' => $node->getETag(),
            'websocketEvent' => $this->WS_EVENTS['EVENT_UPDATED']
        ]);

        $this->createMessage($path, $body);
        return true;
    }

    protected function createMessage($path, $body) {
        $this->message = [
            'topic' => $this->REDIS_EVENTS,
            'data' => $body
        ];
        return $this->message;
    }

    protected function publishMessage() {
        if ($this->message) {
            $this->client->publish($this->message['topic'], $this->message['data']);
        }
    }
}
