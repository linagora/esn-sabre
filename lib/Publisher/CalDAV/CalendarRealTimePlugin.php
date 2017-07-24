<?php
namespace ESN\Publisher\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\DAV\Sharing;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Document;
use Sabre\Uri;
use Sabre\Event\EventEmitter;
use \ESN\Utils\Utils as Utils;

class CalendarRealTimePlugin extends \ESN\Publisher\RealTimePlugin {

    protected $caldavBackend;

    private $CALENDAR_TOPICS = [
        'CALENDAR_CREATED' => 'calendar:calendar:created',
        'CALENDAR_UPDATED' => 'calendar:calendar:updated',
        'CALENDAR_DELETED' => 'calendar:calendar:deleted',
    ];

    function __construct($client, $caldavBackend) {
        parent::__construct($client);
        $this->caldavBackend = $caldavBackend;
    }

    function initialize(Server $server) {
        parent::initialize($server);

        $eventEmitter = $this->caldavBackend->getEventEmitter();

        $eventEmitter->on('esn:calendarCreated', [$this, 'calendarCreated']);
        $eventEmitter->on('esn:calendarUpdated', [$this, 'calendarUpdated']);
        $eventEmitter->on('esn:calendarDeleted', [$this, 'calendarDeleted']);
        $eventEmitter->on('esn:updateSharees', [$this, 'updateSharees']);
        $eventEmitter->on('esn:updatePublicRight', [$this, 'updatePublicRight']);
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
            if ($instance['sharee']->inviteStatus !== \Sabre\DAV\Sharing\Plugin::INVITE_ACCEPTED) {
                continue;
            }
            else if ($instance['type'] == 'delete') {
                $topic = $this->CALENDAR_TOPICS['CALENDAR_DELETED'];
                $props = null;
            } else if ($instance['type'] == 'create') {
                $topic = $this->CALENDAR_TOPICS['CALENDAR_CREATED'];
                $props = [
                    'access' => $sharingPlugin->accessToRightRse($instance['sharee']->access)
                ];
            } else if ($instance['type'] == 'update') {
                $topic = $this->CALENDAR_TOPICS['CALENDAR_UPDATED'];
                $props = [
                    'access' => $sharingPlugin->accessToRightRse($instance['sharee']->access)
                ];
            }

            $calendarPath = Utils::calendarPathFromUri($instance['sharee']->principal,  $instance['uri']);

            $this->createMessage(
                $topic,
                [
                    'calendarPath' => $calendarPath,
                    'calendarProps' => $props
                ]
            );
        }

        $this->publishMessages();
    }

    function updatePublicRight($path, $notifySubscribers = true) {
        $topic = $this->CALENDAR_TOPICS['CALENDAR_UPDATED'];
        $calendar = $this->server->tree->getNodeForPath($path);

        $props = ['public_right' => $calendar->getPublicRight()];

        $invites = $calendar->getInvites();
        $calendarid = $calendar->getCalendarId();

        if ($notifySubscribers) {
            $subscribers = $calendar->getSubscribers();

            foreach($subscribers as $subscriber) {
                $path = Utils::calendarPathFromUri($subscriber['principaluri'], $subscriber['uri']);

                $this->createMessage(
                    $topic,
                    [
                        'calendarPath' => $path,
                        'calendarProps' => $props
                    ]
                );
            }
        }

        foreach($invites as $invite) {
            $calendars = $this->caldavBackend->getCalendarsForUser($invite->principal);
            foreach($calendars as $calendarUser) {
                if($calendarUser['id'][0] == $calendarid) {
                    $calendarUri = $calendarUser['uri'];
                }
            }

            $path = Utils::calendarPathFromUri($invite->principal, $calendarUri);

            $this->createMessage(
            $topic,
                [
                    'calendarPath' => $path,
                    'calendarProps' => $props
                ]
            );
        }

        $this->publishMessages();
    }
}
