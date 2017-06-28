<?php
namespace ESN\Publisher\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Document;
use Sabre\Uri;

class EventRealTimePlugin extends \ESN\Publisher\RealTimePlugin {
    const PRIORITY_LOWER_THAN_SCHEDULE_PLUGIN = 101;

    protected $caldavBackend;

    // We use a topic per action, as in OP
    private $EVENT_TOPICS = [
        'EVENT_CREATED' => 'calendar:event:created',
        'EVENT_UPDATED' => 'calendar:event:updated',
        'EVENT_DELETED' => 'calendar:event:deleted',
        'EVENT_REQUEST' => 'calendar:event:request',
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
        if(isset( $data['eventSource'])) {
            $path = '/' . $data['eventSource'];
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
            $this->addSharedUsers($this->EVENT_TOPICS['EVENT_DELETED'], $nodeParent, $path, $node->get());
        }

        return true;
    }

    function beforeCreateFile($path, &$data, \Sabre\DAV\ICollection $parent, &$modified) {
        $this->addSharedUsers($this->EVENT_TOPICS['EVENT_CREATED'], $parent, $path, $data);

        return true;
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        list($parentUri) = Uri\split($path);

        $nodeParent = $this->server->tree->getNodeForPath('/'.$parentUri);

        $oldVcal = \Sabre\VObject\Reader::read($node->get());
        $this->addSharedUsers($this->EVENT_TOPICS['EVENT_UPDATED'], $nodeParent, $path, $data, $oldVcal);

        return true;
    }

    function addSharedUsers($topic, $calendar, $calendarPathObject, $data, $old_event = null) {
        if ($calendar instanceof \ESN\CalDAV\SharedCalendar) {
            $options = [
                'baseUri' => $this->server->getBaseUri(),
                'extension' => '.json'
            ];
            $subscribers = $calendar->getSubscribers($options);
            $invites = $calendar->getInvites();
            $calendarid = $calendar->getCalendarId();

            $pathExploded = explode('/', $calendarPathObject);
            $objectUri = $pathExploded[3];

            foreach($subscribers as $subscriber) {
                $principalUriExploded = explode('/', $subscriber['principaluri']);
                $path = '/calendars/' . $principalUriExploded[2] . '/' . $subscriber['uri'] . '/' . $objectUri;
                $event = \Sabre\VObject\Reader::read($data);
                $event->remove('method');

                $dataMessage = [
                    'eventPath' => $path,
                    'eventSource' => $calendarPathObject,
                    'event' => $event
                ];

                if($old_event) {
                    $dataMessage['old_event'] = $old_event;
                }

                $this->createMessage($topic, $dataMessage);
            }

            foreach($invites as $user) {
                $calendars = $this->caldavBackend->getCalendarsForUser($user->principal);
                foreach($calendars as $calendarUser) {
                    if($calendarUser['id'][0] == $calendarid) {
                        $calendarUri = $calendarUser['uri'];
                    }
                }

                $uriExploded = explode('/', $user->principal);
                $path = '/calendars/' . $uriExploded[2] . '/' . $calendarUri . '/' . $objectUri;
                $event = \Sabre\VObject\Reader::read($data);
                $event->remove('method');

                $dataMessage = [
                    'eventPath' => $path,
                    'event' => $event
                ];

                if($old_event) {
                    $dataMessage['old_event'] = $old_event;
                }

                $this->createMessage($topic, $dataMessage);
            }
        }
    }

    function schedule(\Sabre\VObject\ITip\Message $iTipMessage) {
        $aclPlugin = $this->server->getPlugin('acl');

        if (!$aclPlugin) {
            error_log('No aclPlugin');
            return true;
        }

        $caldavNS = '{' . \Sabre\CalDAV\Schedule\Plugin::NS_CALDAV . '}';
        $principalUri = $aclPlugin->getPrincipalByUri($iTipMessage->recipient);
        if (!$principalUri) {
            error_log('3.7;Could not find principal.');
            return true;
        }
        // We found a principal URL, now we need to find its inbox.
        // Unfortunately we may not have sufficient privileges to find this, so
        // we are temporarily turning off ACL to let this come through.
        //
        // Once we support PHP 5.5, this should be wrapped in a try..finally
        // block so we can ensure that this privilege gets added again after.
        $this->server->removeListener('propFind', [$aclPlugin, 'propFind']);
        $result = $this->server->getProperties(
            $principalUri,
            [
                '{DAV:}principal-URL',
                 $caldavNS . 'calendar-home-set',
                 $caldavNS . 'schedule-inbox-URL',
                 $caldavNS . 'schedule-default-calendar-URL',
                '{http://sabredav.org/ns}email-address',
            ]
        );
        // Re-registering the ACL event
        $this->server->on('propFind', [$aclPlugin, 'propFind'], 20);
        if (!isset($result[$caldavNS . 'schedule-inbox-URL'])) {
            error_log('5.2;Could not find local inbox');
            return;
        }
        if (!isset($result[$caldavNS . 'calendar-home-set'])) {
            error_log('5.2;Could not locate a calendar-home-set');
            return;
        }
        if (!isset($result[$caldavNS . 'schedule-default-calendar-URL'])) {
            error_log('5.2;Could not find a schedule-default-calendar-URL property');
            return true;
        }
        $calendarPath = $result[$caldavNS . 'schedule-default-calendar-URL']->getHref();
        $homePath = $result[$caldavNS . 'calendar-home-set']->getHref();
        $inboxPath = $result[$caldavNS . 'schedule-inbox-URL']->getHref();
        if ($iTipMessage->method === 'REPLY') {
            $privilege = 'schedule-deliver-reply';
        } else {
            $privilege = 'schedule-deliver-invite';
        }
        if (!$aclPlugin->checkPrivileges($inboxPath, $caldavNS . $privilege, \Sabre\DAVACL\Plugin::R_PARENT, false)) {
            error_log('3.8;organizer did not have the ' . $privilege . ' privilege on the attendees inbox');
            return;
        }
        // Next, we're going to find out if the item already exits in one of
        // the users' calendars.
        $uid = $iTipMessage->uid;
        $home = $this->server->tree->getNodeForPath($homePath);
        $eventPath = $home->getCalendarObjectByUID($uid);

        if (!$eventPath) {
            error_log("5.0;Event $uid not found in home $homePath.");
            return;
        }

        $path = $homePath . $eventPath;

        $event = clone $iTipMessage->message;
        $event->remove('method');

        $this->createMessage(
            $this->EVENT_TOPICS['EVENT_'.$iTipMessage->method],
            [
                'eventPath' => '/' . $path,
                'event' => $event
            ]
        );

        return true;
    }

    function itip(\Sabre\VObject\ITip\Message $iTipMessage) {
        $this->schedule($iTipMessage);
        $this->publishMessages();
    }
}
