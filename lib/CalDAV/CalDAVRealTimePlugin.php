<?php
namespace ESN\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;

class CalDAVRealTimePlugin extends ServerPlugin {
    protected $server;
    protected $message;

    private $REDIS_EVENTS = 'calendar:event:updated';

    private $WS_EVENTS = [
        'EVENT_CREATED' => 'calendar:ws:event:created',
        'EVENT_UPDATED' => 'calendar:ws:event:updated',
        'EVENT_DELETED' => 'calendar:ws:event:deleted',
        'EVENT_REQUEST' => 'calendar:ws:event:request',
        'EVENT_REPLY' => 'calendar:ws:event:reply',
        'EVENT_CANCEL' => 'calendar:ws:event:cancel'
    ];

    function __construct($client) {
        $this->client = $client;
    }

    function initialize(Server $server) {
        $this->server = $server;
        $this->messages = array();

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
        $node = $this->server->tree->getNodeForPath('/'.$path);

        if ($node instanceof \Sabre\CalDAV\CalendarObject) {
            $body = [
                'eventPath' => '/' . $path,
                'type' => 'deleted',
                'event' => \Sabre\VObject\Reader::read($node->get()),
                'websocketEvent' => $this->WS_EVENTS['EVENT_DELETED']
            ];

            $this->createMessage($path, $body);
        }

        return true;
    }

    function beforeCreateFile($path, &$data, \Sabre\DAV\ICollection $parent, &$modified) {
        if ($parent instanceof \Sabre\CalDAV\Calendar) {
            $body = [
                'eventPath' => '/' . $path,
                'type' => 'created',
                'event' => \Sabre\VObject\Reader::read($data),
                'websocketEvent' => $this->WS_EVENTS['EVENT_CREATED']
            ];

            $this->createMessage($path, $body);
        }

        return true;
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        if ($node instanceof \Sabre\CalDAV\CalendarObject) {
            $vcal = \Sabre\VObject\Reader::read($data);
            $oldVcal = \Sabre\VObject\Reader::read($node->get());
            $body = [
                'eventPath' => '/' .$path,
                'type' => 'updated',
                'event' => $vcal,
                'old_event' => $oldVcal,
                'websocketEvent' => $this->WS_EVENTS['EVENT_UPDATED']
            ];

            $this->createMessage($path, $body);
        }

        return true;
    }

    protected function createMessage($path, $body) {
        $this->messages[] = [
            'topic' => $this->REDIS_EVENTS,
            'data' => $body
        ];
        return $this->messages;
    }

    protected function publishMessage() {
        foreach($this->messages as $message) {
            $path = $message['data']['eventPath'];
            if($this->server->tree->nodeExists($path)) {
                $message['data']['etag'] = $this->server->tree->getNodeForPath($path)->getETag();
            }
            $this->client->publish($message['topic'], json_encode($message['data']));
        }
    }
}