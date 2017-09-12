<?php
namespace ESN\Publisher\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Document;
use Sabre\Uri;
use \ESN\Utils\Utils as Utils;

class EventRealTimePlugin extends \ESN\Publisher\RealTimePlugin {
    const PRIORITY_LOWER_THAN_SCHEDULE_PLUGIN = 101;

    protected $caldavBackend;

    // We use a topic per action, as in OP
    private $EVENT_TOPICS = [
        'EVENT_CREATED' => 'calendar:event:created',
        'EVENT_UPDATED' => 'calendar:event:updated',
        'EVENT_DELETED' => 'calendar:event:deleted',
        'EVENT_REQUEST' => 'calendar:event:request',
        'EVENT_ALARM_CREATED' => 'calendar:event:alarm:created',
        'EVENT_ALARM_UPDATED' => 'calendar:event:alarm:updated',
        'EVENT_ALARM_DELETED' => 'calendar:event:alarm:deleted',
        'EVENT_ALARM_REQUEST' => 'calendar:event:alarm:request',
        'EVENT_ALARM_CANCEL' => 'calendar:event:alarm:cancel',
        'EVENT_REPLY' => 'calendar:event:reply',
        'EVENT_CANCEL' => 'calendar:event:cancel'
    ];

    function __construct($client, $caldavBackend) {
        parent::__construct($client);
        $this->caldavBackend = $caldavBackend;
    }

    function initialize(Server $server) {
        parent::initialize($server);

        $server->on('beforeCreateFile',   [$this, 'beforeCreateFile']);
        $server->on('afterCreateFile',    [$this, 'after']);

        $server->on('beforeWriteContent', [$this, 'beforeWriteContent']);
        $server->on('afterWriteContent',  [$this, 'after']);

        $server->on('beforeUnbind',       [$this, 'beforeUnbind']);
        $server->on('afterUnbind',        [$this, 'after']);

        //we want that the schedule plugin get called before so attendee's event are created
        $server->on('schedule',           [$this, 'schedule'], self::PRIORITY_LOWER_THAN_SCHEDULE_PLUGIN);
        $server->on('itip', [$this, 'itip']);
    }

    function buildData($data) {
        if(isset( $data['eventSourcePath'])) {
            $path = '/' . $data['eventSourcePath'];
        } else  {
            $path = $data['eventPath'];
        }

        if($this->server->tree->nodeExists($path)) {
            $data['etag'] = $this->server->tree->getNodeForPath($path)->getETag();
        }

        return $data;
    }

    function after($path) {
        $this->publishMessages();
        return true;
    }

    function beforeUnbind($path) {
        $node = $this->server->tree->getNodeForPath('/'.$path);

        if ($node instanceof \Sabre\CalDAV\CalendarObject) {
            list($parentUri) = Uri\split($path);
            $nodeParent = $this->server->tree->getNodeForPath('/'.$parentUri);
            $this->addSharedUsers('DELETED', $nodeParent, $path, $node->get());
        }

        return true;
    }

    function beforeCreateFile($path, &$data, \Sabre\DAV\ICollection $parent, &$modified) {
        $this->addSharedUsers('CREATED', $parent, $path, $data);

        return true;
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        list($parentUri) = Uri\split($path);

        $nodeParent = $this->server->tree->getNodeForPath('/'.$parentUri);

        $oldVcal = \Sabre\VObject\Reader::read($node->get());
        $this->addSharedUsers('UPDATED', $nodeParent, $path, $data, $oldVcal);

        return true;
    }

    function addSharedUsers($action, $calendar, $calendarPathObject, $data, $old_event = null) {
        if ($calendar instanceof \ESN\CalDAV\SharedCalendar) {

            $subscribers = $calendar->getSubscribers();
            $invites = $calendar->getInvites();
            $calendarid = $calendar->getCalendarId();

            $pathExploded = explode('/', $calendarPathObject);
            $objectUri = $pathExploded[3];

            $event = \Sabre\VObject\Reader::read($data);
            $event->remove('method');

            $dataMessage = [
                'eventPath' => '/' . $calendarPathObject,
                'event' => $event
            ];

            $this->createMessage($this->EVENT_TOPICS['EVENT_ALARM_'.$action], $dataMessage);

            foreach($subscribers as $subscriber) {
                $path = Utils::objectPathFromUri($subscriber['principaluri'],  $subscriber['uri'], $objectUri);

                $dataMessage = [
                    'eventPath' => $path,
                    'eventSourcePath' => '/' . $calendarPathObject,
                    'event' => $event
                ];

                if($old_event) {
                    $dataMessage['old_event'] = $old_event;
                }

                $this->createMessage($this->EVENT_TOPICS['EVENT_'.$action], $dataMessage);
            }

            foreach($invites as $user) {
                if($user->inviteStatus === \Sabre\DAV\Sharing\Plugin::INVITE_INVALID) {
                    continue;
                }

                $calendars = $this->caldavBackend->getCalendarsForUser($user->principal);
                foreach($calendars as $calendarUser) {
                    if($calendarUser['id'][0] == $calendarid) {
                        $calendarUri = $calendarUser['uri'];
                    }
                }

                $path = Utils::objectPathFromUri($user->principal,  $calendarUri, $objectUri);

                $dataMessage = [
                    'eventPath' => $path,
                    'event' => $event
                ];

                if($old_event) {
                    $dataMessage['old_event'] = $old_event;
                }

                $this->createMessage($this->EVENT_TOPICS['EVENT_'.$action], $dataMessage);
            }
        }
    }

    function schedule(\Sabre\VObject\ITip\Message $iTipMessage) {
        list($homePath, $eventPath) = Utils::getEventPathsFromItipsMessage($iTipMessage, $this->server);
        $path = $homePath . $eventPath;

        $event = clone $iTipMessage->message;
        $event->remove('method');

        $dataMessage = [
            'eventPath' => '/' . $path,
            'event' => $event
        ];

        $this->createMessage(
            $this->EVENT_TOPICS['EVENT_'.$iTipMessage->method],
            $dataMessage
        );

        if($iTipMessage->method !== 'REPLY'){
            $this->createMessage(
                $this->EVENT_TOPICS['EVENT_ALARM_'.$iTipMessage->method],
                $dataMessage
            );
        }

        return true;
    }

    function itip(\Sabre\VObject\ITip\Message $iTipMessage) {
        $this->schedule($iTipMessage);
        $this->publishMessages();
    }
}
