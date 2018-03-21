<?php
namespace ESN\Publisher\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject;
use Sabre\Uri;
use \ESN\Utils\Utils as Utils;
use \ESN\CalDAV\Schedule\IMipPlugin;

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
        'EVENT_CANCEL' => 'calendar:event:cancel',
        'RESOURCE_EVENT_CREATED' => 'resource:calendar:event:created',
        'RESOURCE_EVENT_ACCEPTED' => 'resource:calendar:event:accepted',
        'RESOURCE_EVENT_DECLINED' => 'resource:calendar:event:declined'
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
        $server->on('schedule', [$this, 'schedule'], self::PRIORITY_LOWER_THAN_SCHEDULE_PLUGIN);
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
            $calendarid = $calendar->getCalendarId();
            $pathExploded = explode('/', $calendarPathObject);
            $objectUri = $pathExploded[3];
            $calendarUri = $pathExploded[2];

            $event = \Sabre\VObject\Reader::read($data);
            $event->remove('method');

            $dataMessage = [
                'eventPath' => '/' . $calendarPathObject,
                'event' => $event
            ];

            if($old_event) {
                $dataMessage['old_event'] = $old_event;
            }

            $options = [
                'action' => $action,
                'eventSourcePath' => $calendarPathObject,
                'calendarid' => $calendarid,
                'objectUri' => $objectUri,
                'calendarUri' => $calendarUri
            ];

            $this->createMessage($this->EVENT_TOPICS['EVENT_ALARM_'.$action], $dataMessage);
            $this->notifySubscribers($calendar->getSubscribers(), $dataMessage, $options);
            $this->notifyInvites($calendar->getInvites(), $dataMessage, $options);
        }
    }

    private function notifySubscribers($subscribers, $dataMessage, $options) {
        foreach($subscribers as $subscriber) {
            $dataMessage['eventPath'] = Utils::objectPathFromUri($subscriber['principaluri'],  $subscriber['uri'], $options['objectUri']);
            $dataMessage['eventSourcePath'] = '/' . $options['eventSourcePath'];

            $this->createMessage($this->EVENT_TOPICS['EVENT_'.$options['action']], $dataMessage);
        }
    }

    private function notifyInvites($invites, $dataMessage, $options) {
        foreach($invites as $user) {
            if($user->inviteStatus === \Sabre\DAV\Sharing\Plugin::INVITE_INVALID) {
                continue;
            }

            $calendars = $this->caldavBackend->getCalendarsForUser($user->principal);

            foreach($calendars as $calendarUser) {
                if($calendarUser['id'][0] == $options['calendarid']) {
                    $calendarUri = $calendarUser['uri'];
                }
            }

            $dataMessage['eventPath'] = Utils::objectPathFromUri($user->principal,  $calendarUri, $options['objectUri']);

            $this->createMessage($this->EVENT_TOPICS['EVENT_'.$options['action']], $dataMessage);
        }
    }

    function schedule(\Sabre\VObject\ITip\Message $iTipMessage) {
        switch($iTipMessage->scheduleStatus) {
            case \ESN\CalDAV\Schedule\IMipPlugin::SCHEDSTAT_SUCCESS_PENDING:
            case \ESN\CalDAV\Schedule\IMipPlugin::SCHEDSTAT_FAIL_TEMPORARY:
            case \ESN\CalDAV\Schedule\IMipPlugin::SCHEDSTAT_FAIL_PERMANENT:

                return false;
        }

        $recipientPrincipalUri = Utils::getPrincipalByUri($iTipMessage->recipient, $this->server);
        if (!$recipientPrincipalUri) {
            return true;
        }

        list($homePath, $eventPath, $upToDateEventIcs) = Utils::getEventForItip($recipientPrincipalUri, $iTipMessage->uid, $iTipMessage->method, $this->server);
        $path = $homePath . $eventPath;

        if (!$homePath || !$eventPath) {
            return false;
        }
        
        $dataMessage = [
            'eventPath' => '/' . $path,
            'event' => VObject\Reader::read($upToDateEventIcs)
        ];

        $this->createMessage(
            $this->EVENT_TOPICS['EVENT_'.$iTipMessage->method],
            $dataMessage
        );

        list($namespace, $homeId, $calendarUri, $objectUri) = explode('/', $path);
        $calendar = $this->server->tree->getNodeForPath('/'. substr($path,0,strrpos($path,'/')));
        $calendarid = $calendar->getCalendarId();

        $options = [
            'action' => $iTipMessage->method,
            'eventSourcePath' => $path,
            'calendarid' => $calendarid,
            'objectUri' => $objectUri,
            'calendarUri' => $calendarUri
        ];

        $this->notifySubscribers($calendar->getSubscribers(), $dataMessage, $options);
        $this->notifyInvites($calendar->getInvites(), $dataMessage, $options);

        if($iTipMessage->method !== 'REPLY'){
            $this->createMessage(
                $this->EVENT_TOPICS['EVENT_ALARM_'.$iTipMessage->method],
                $dataMessage
            );
        }

        if($iTipMessage->method === 'REQUEST' && Utils::isResourceFromPrincipal($recipientPrincipalUri)) {
            $pathExploded = explode('/', $path);

            $dataMessage = [
                'resourceId' => $pathExploded[1],
                'eventId' => $pathExploded[3],
                'eventPath' => '/' . $path,
                'ics' => $upToDateEventIcs
            ];
            $this->createMessage(
                $this->EVENT_TOPICS['RESOURCE_EVENT_CREATED'],
                $dataMessage
            );
        }

        $senderPrincipalUri = Utils::getPrincipalByUri($iTipMessage->sender, $this->server);

        if($senderPrincipalUri && $iTipMessage->method === 'REPLY' && Utils::isResourceFromPrincipal($senderPrincipalUri)) {
            list($homePath, $eventPath, $upToDateEventIcs) = Utils::getEventForItip($senderPrincipalUri, $iTipMessage->uid, $iTipMessage->method, $this->server);
            $path = $homePath . $eventPath;

            if (!$homePath || !$eventPath) {
                return false;
            }

            $explodedSenderPrincipalUri = explode('/', $senderPrincipalUri);

            foreach ($iTipMessage->message->VEVENT->ATTENDEE as $attendee) {
                if ($attendee->getValue() === $iTipMessage->sender) {
                    switch($attendee['PARTSTAT']->getValue()) {
                        case 'ACCEPTED':
                            $dataMessage = [
                                'resourceId' => $explodedSenderPrincipalUri[2],
                                'eventId' => $iTipMessage->uid,
                                'eventPath' => '/' . $path,
                                'ics' => $upToDateEventIcs
                            ];
                            $this->createMessage(
                                $this->EVENT_TOPICS['RESOURCE_EVENT_ACCEPTED'],
                                $dataMessage
                            );
                            break;
                        case 'DECLINED':
                            $dataMessage = [
                                'resourceId' => $explodedSenderPrincipalUri[2],
                                'eventId' => $iTipMessage->uid,
                                'eventPath' => '/' . $path,
                                'ics' => $upToDateEventIcs
                            ];
                            $this->createMessage(
                                $this->EVENT_TOPICS['RESOURCE_EVENT_DECLINED'],
                                $dataMessage
                            );
                    }
                }
            }
        }

        return true;
    }

    function itip(\Sabre\VObject\ITip\Message $iTipMessage) {
        if ($this->schedule($iTipMessage)) {
            $this->publishMessages();
        }
    }
}
