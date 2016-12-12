<?php
namespace ESN\CalDAV;

use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;

class CalDAVRealTimePlugin extends ServerPlugin {
    const PRIORITY_LOWER_THAN_SCHEDULE_PLUGIN = 101;

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

        //we want that the schedule plugin get called before so attendee's event are created
        $server->on('schedule',           [$this, 'schedule'], self::PRIORITY_LOWER_THAN_SCHEDULE_PLUGIN);
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
        $path = $homePath.$home->getCalendarObjectByUID($uid);

        $event = $iTipMessage->message;
        $event->remove('method');

        $body = [
            'eventPath' => '/' . $path,
            'type' => $iTipMessage->method,
            'event' => $iTipMessage->message,
            'websocketEvent' => $this->WS_EVENTS['EVENT_'.$iTipMessage->method]
        ];

        $this->createMessage($path, $body);
        return true;
    }
}