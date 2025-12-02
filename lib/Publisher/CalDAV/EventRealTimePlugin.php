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

#[\AllowDynamicProperties]
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

        $server->on('afterMove',          [$this, 'afterMove']);
        //we want that the schedule plugin get called before so attendee's event are created
        $server->on('schedule', [$this, 'schedule'], self::PRIORITY_LOWER_THAN_SCHEDULE_PLUGIN);
        $server->on('iTip', [$this, 'itip']);
    }

    function ensureRequiredFields($vobject) {
        // Ensure PRODID is present in VCALENDAR (required by RFC 5545)
        if (!isset($vobject->PRODID)) {
            $vobject->PRODID = '-//Sabre//Sabre VObject ' . VObject\Version::VERSION . '//EN';
        }

        // Ensure DTSTAMP is present in all VEVENTs (required for iTIP)
        if (isset($vobject->VEVENT)) {
            foreach ($vobject->VEVENT as $vevent) {
                if (!isset($vevent->DTSTAMP)) {
                    $vevent->DTSTAMP = gmdate('Ymd\THis\Z');
                }
            }
        }

        return $vobject;
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

        // Ensure required fields are present before publishing
        if (isset($data['event']) && $data['event'] instanceof VObject\Component) {
            $data['event'] = $this->ensureRequiredFields($data['event']);
        }

        return $data;
    }

    function afterMove($path, $destinationPath) {
        $eventNode = $this->server->tree->getNodeForPath($destinationPath);
        $eventPath = '/' . $destinationPath;

        list($calendarNodePath, $eventURI) = Utils::splitEventPath($eventPath);
        $calendar = $this->server->tree->getNodeForPath($calendarNodePath);

        $parts = explode('/', $calendarNodePath);
        if (count($parts) < 3) {
            return true; // Not a calendar
        }

        list(,, $calendarUid) = $parts;

        $options = [
            'action' => 'CREATED',
            'eventSourcePath' => $destinationPath,
            'objectUri' => $eventURI,
            'calendarid' => $calendar->getCalendarId(),
            'calendarUri' => $calendarUid,
        ];

        $dataMessage = [
            'eventPath' => $eventPath,
            'event' => VObject\Reader::read($eventNode->get())
        ];

        $this->notifySubscribers($calendar->getSubscribers(), $dataMessage, $options);
        $this->notifyInvites($calendar->getInvites(), $dataMessage, $options);
        $this->publishMessages();

        return true;
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

    function getFirstChar($data) {
        if (is_resource($data)) {
            $char = fgetc($data);
            rewind($data);
            return $char === false ? null : $char;
        }

        if (is_string($data) && $data !== '') {
            return $data[0];
        }
        return null;
    }

    function addSharedUsers($action, $calendar, $calendarPathObject, $data, $old_event = null) {
        if ($calendar instanceof \ESN\CalDAV\SharedCalendar) {
            $calendarid = $calendar->getCalendarId();
            $pathExploded = explode('/', $calendarPathObject);
            $objectUri = $pathExploded[3];
            $calendarUri = $pathExploded[2];
            $isImport = false;

            // Validate that $data is a string or resource before parsing
            if (!is_string($data) && !is_resource($data)) {
                error_log('EventRealTimePlugin: Invalid data type in addSharedUsers, expected string or resource, got ' . gettype($data));
                return;
            }

            if ($this->getFirstChar($data) === '[') {
                $event = \Sabre\VObject\Reader::readJson($data);
            } else {
                $event = \Sabre\VObject\Reader::read($data);
            }

            $dataAsString = $data;
            if (is_resource($data)) {
                rewind($data);
                $dataAsString = stream_get_contents($data);
                rewind($data);
            } else {
                $dataAsString = $data;
            }
            $event->remove('method');

            if (array_key_exists('import', $this->server->httpRequest->getQueryParameters())) {
                $isImport = true;
            }

            $dataMessage = [
                'eventPath' => '/' . $calendarPathObject,
                'event' => $event,
                'rawEvent' => $dataAsString,
                'import' => $isImport
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

                    list(,, $userId) = explode('/', $user->principal);
                    $eventCalendar = $this->server->tree->getNodeForPath('calendars/' . $userId . '/' . $calendarUri);
                }
            }

            $vCalendar = $dataMessage['event'];
            $dataMessage['event'] = Utils::hidePrivateEventInfoForUser($vCalendar, $eventCalendar, $user->principal);

            $dataMessage['eventPath'] = Utils::objectPathFromUri($user->principal,  $calendarUri, $options['objectUri']);

            $this->createMessage($this->EVENT_TOPICS['EVENT_'.$options['action']], $dataMessage);
        }
    }

    function schedule(\Sabre\VObject\ITip\Message $iTipMessage) {
        if($iTipMessage->method === 'COUNTER') {
            return true;
        }

        switch($iTipMessage->scheduleStatus) {
            case \ESN\CalDAV\Schedule\IMipPlugin::SCHEDSTAT_SUCCESS_PENDING:
            case \ESN\CalDAV\Schedule\IMipPlugin::SCHEDSTAT_FAIL_TEMPORARY:
            case \ESN\CalDAV\Schedule\IMipPlugin::SCHEDSTAT_FAIL_PERMANENT:
                return false;
        }

        $recipientPrincipalUri = Utils::getPrincipalByUri($iTipMessage->recipient, $this->server);

        // If getPrincipalByUri fails (external recipient), try to find principal by email
        if (!$recipientPrincipalUri) {
            $recipientEmail = $iTipMessage->recipient;
            if (strpos($recipientEmail, 'mailto:') === 0) {
                $recipientEmail = substr($recipientEmail, 7);
            }

            // Use PrincipalBackend from CalDAV backend to find user by email
            if ($this->caldavBackend && method_exists($this->caldavBackend, 'getPrincipalBackend')) {
                $principalBackend = $this->caldavBackend->getPrincipalBackend();
                if ($principalBackend && method_exists($principalBackend, 'getPrincipalIdByEmail')) {
                    $userId = $principalBackend->getPrincipalIdByEmail($recipientEmail);
                    if ($userId) {
                        $recipientPrincipalUri = 'principals/users/' . $userId;
                    }
                }
            }
        }

        // If we still don't have a principal URI, we can't process this iTip message
        if (!$recipientPrincipalUri) {
            return true;
        }

        // Get sender principal URI to check if recipient is the organizer themselves
        $senderPrincipalUri = Utils::getPrincipalByUri($iTipMessage->sender, $this->server);

        // Don't send REQUEST to organizer themselves (fixes #215)
        // This happens when ATTENDEE doesn't have mailto: prefix and matches the organizer
        if ($iTipMessage->method === 'REQUEST' &&
            $senderPrincipalUri &&
            $recipientPrincipalUri === $senderPrincipalUri) {
            return true;
        }

        // Get the event from recipient's calendar (should exist now, created by schedule plugin)
        list($eventPath, $upToDateEventIcs) = Utils::getEventObjectFromAnotherPrincipalHome($recipientPrincipalUri, $iTipMessage->uid, $iTipMessage->method, $this->server);

        // If event not found (e.g., CANCEL deleted it, or external REQUEST), construct path manually
        if (!$eventPath) {
            $aclPlugin = $this->server->getPlugin('acl');
            if (!$aclPlugin) {
                return false;
            }

            $caldavNS = '{' . \Sabre\CalDAV\Schedule\Plugin::NS_CALDAV . '}';

            // Get calendar properties
            $this->server->removeListener('propFind', [$aclPlugin, 'propFind']);
            $result = $this->server->getProperties(
                $recipientPrincipalUri,
                [
                    $caldavNS . 'calendar-home-set',
                    $caldavNS . 'schedule-default-calendar-URL',
                ]
            );
            $this->server->on('propFind', [$aclPlugin, 'propFind'], 20);

            if (!isset($result[$caldavNS . 'calendar-home-set']) ||
                !isset($result[$caldavNS . 'schedule-default-calendar-URL'])) {
                return false;
            }

            $homePath = $result[$caldavNS . 'calendar-home-set']->getHref();
            $defaultCalendarPath = $result[$caldavNS . 'schedule-default-calendar-URL']->getHref();

            // Extract calendar URI from the full path
            // defaultCalendarPath is like: /calendars/54b64eadf6d7d8e41d263e0f/events
            $calendarUri = basename($defaultCalendarPath);
            $homeId = basename($homePath);

            // Construct event path: calendars/homeId/calendarUri/uid.ics
            $objectUri = $iTipMessage->uid . '.ics';
            $eventPath = 'calendars/' . $homeId . '/' . $calendarUri . '/' . $objectUri;

            // Use the iTip message as the event data
            $upToDateEventIcs = $iTipMessage->message->serialize();
        }

        $dataMessage = [
            'eventPath' => '/' . $eventPath,
            'event' => VObject\Reader::read($upToDateEventIcs)
        ];

        $this->createMessage(
            $this->EVENT_TOPICS['EVENT_'.$iTipMessage->method],
            $dataMessage
        );

        list($namespace, $homeId, $calendarUri, $objectUri) = explode('/', $eventPath);
        $calendar = $this->server->tree->getNodeForPath('/'. substr($eventPath,0,strrpos($eventPath,'/')));
        $calendarid = $calendar->getCalendarId();

        $options = [
            'action' => $iTipMessage->method,
            'eventSourcePath' => $eventPath,
            'calendarid' => $calendarid,
            'objectUri' => $objectUri,
            'calendarUri' => $calendarUri
        ];

        $this->notifySubscribers($calendar->getSubscribers(), $dataMessage, $options);

        // Only publish alarm events for REQUEST if it's a significant change
        // (e.g., new event, alarm modified, not just another attendee's PARTSTAT change)
        if ($iTipMessage->method === 'REQUEST' && $iTipMessage->significantChange) {
            $this->createMessage(
                $this->EVENT_TOPICS['EVENT_ALARM_REQUEST'],
                $dataMessage
            );
        } elseif ($iTipMessage->method === 'CANCEL') {
            $this->createMessage(
                $this->EVENT_TOPICS['EVENT_ALARM_CANCEL'],
                $dataMessage
            );
        }

        if($iTipMessage->method === 'REQUEST' && Utils::isResourceFromPrincipal($recipientPrincipalUri) && $iTipMessage->significantChange) {
            $pathExploded = explode('/', $eventPath);

            $dataMessage = [
                'resourceId' => $pathExploded[1],
                'eventId' => $pathExploded[3],
                'eventPath' => '/' . $eventPath,
                'ics' => $upToDateEventIcs
            ];
            $this->createMessage(
                $this->EVENT_TOPICS['RESOURCE_EVENT_CREATED'],
                $dataMessage
            );
        }


        if($senderPrincipalUri && $iTipMessage->method === 'REPLY' && Utils::isResourceFromPrincipal($senderPrincipalUri)) {
            list($eventPath, $upToDateEventIcs) = Utils::getEventObjectFromAnotherPrincipalHome($senderPrincipalUri, $iTipMessage->uid, $iTipMessage->method, $this->server);

            if (!$eventPath) {
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
                                'eventPath' => '/' . $eventPath,
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
                                'eventPath' => '/' . $eventPath,
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

        $this->publishMessages();
        return true;
    }

    function itip(\Sabre\VObject\ITip\Message $iTipMessage) {
        if ($this->schedule($iTipMessage)) {
            $this->publishMessages();
        }
    }
}
