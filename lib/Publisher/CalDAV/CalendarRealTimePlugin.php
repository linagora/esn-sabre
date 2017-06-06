<?php
namespace ESN\Publisher\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Document;
use Sabre\Uri;
use Sabre\Event\EventEmitter;

class CalendarRealTimePlugin extends \ESN\Publisher\RealTimePlugin {

    protected $eventEmitter;

    private $CALENDAR_TOPICS = [
        'CALENDAR_CREATED' => 'calendar:calendar:created',
        'CALENDAR_UPDATED' => 'calendar:calendar:updated',
        'CALENDAR_DELETED' => 'calendar:calendar:deleted',
    ];

    function __construct($client, $eventEmitter) {
        parent::__construct($client);
        $this->eventEmitter = $eventEmitter;
    }

    function initialize(Server $server) {
        parent::initialize($server);

        $this->eventEmitter->on('esn:calendarCreated', [$this, 'calendarCreated']);
        $this->eventEmitter->on('esn:calendarUpdated', [$this, 'calendarUpdated']);
        $this->eventEmitter->on('esn:calendarDeleted', [$this, 'calendarDeleted']);
        $this->eventEmitter->on('esn:updateSharees', [$this, 'updateSharees']);
    }

    function getCalendarProps($node) {
        $properties = [
            "{DAV:}displayname",
            "{urn:ietf:params:xml:ns:caldav}calendar-description" ,
            "{http://apple.com/ns/ical/}calendar-color",
            "{http://apple.com/ns/ical/}apple-order"
        ];

        return $node->getProperties($properties);
    }

    function buildData($data) {
        return $data;
    }

    function prepareAndPublishMessages($path, $props, $topic) {

        $this->createMessage(
            $topic,
            [
                'calendarPath' => $path,
                'calendarProps' => $props
            ]
        );

        $this->publishMessages();
    }

    function calendarCreated($path) {
        $node = $this->server->tree->getNodeForPath($path);
        $props = $this->getCalendarProps($node);

        $this->prepareAndPublishMessages($path, $props, $this->CALENDAR_TOPICS['CALENDAR_CREATED']);
    }

    function calendarDeleted($path) {
        $this->prepareAndPublishMessages($path, null, $this->CALENDAR_TOPICS['CALENDAR_DELETED']);
    }

    function calendarUpdated($path) {
        $node = $this->server->tree->getNodeForPath($path);
        $props = $this->getCalendarProps($node);

        $this->prepareAndPublishMessages($path, $props, $this->CALENDAR_TOPICS['CALENDAR_UPDATED']);
    }

    function updateSharees($calendarInstances) {
        $sharingPlugin = $this->server->getPlugin('sharing');

        foreach($calendarInstances as $instance) {

            if ($instance['type'] == 'delete') {
                $event = $this->CALENDAR_TOPICS['CALENDAR_DELETED'];
                $props = null;
            } else if ($instance['type'] == 'create') {
                $event = $this->CALENDAR_TOPICS['CALENDAR_CREATED'];
                $props = [
                    'access' => $sharingPlugin->accessToRightRse($instance['sharee']->access)
                ];
            } else if ($instance['type'] == 'update') {
                $event = $this->CALENDAR_TOPICS['CALENDAR_UPDATED'];
                $props = [
                    'access' => $sharingPlugin->accessToRightRse($instance['sharee']->access)
                ];
            }

            $principalArray = explode('/', $instance['sharee']->principal);
            $nodeInstance = '/calendars/' . $principalArray[2] . '/' . $instance['uri'];

            $this->createMessage(
                $event,
                [
                    'calendarPath' => $nodeInstance,
                    'calendarProps' => $props
                ]
            );
        }

        $this->publishMessages();
    }
}
