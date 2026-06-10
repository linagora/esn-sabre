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
    // Run after Sabre's CalDAV plugin (priority 100) to get the converted ICS data
    const PRIORITY_AFTER_CALDAV_PLUGIN = 150;

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

        // Use lower priority (higher number) to run after Sabre's CalDAV plugin
        // which converts jCal/JSON to ICS format at priority 100
        $server->on('beforeCreateFile',   [$this, 'beforeCreateFile'], self::PRIORITY_AFTER_CALDAV_PLUGIN);
        $server->on('afterCreateFile',    [$this, 'after']);

        $server->on('beforeWriteContent', [$this, 'beforeWriteContent'], self::PRIORITY_AFTER_CALDAV_PLUGIN);
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
        $eventPath = '/' . ltrim($destinationPath, '/');
        list($calendarNodePath, $eventURI) = Utils::splitEventPath($eventPath);
        if ($calendarNodePath === null || $eventURI === null) {
            return true;
        }

        $eventNode = $this->server->tree->getNodeForPath($destinationPath);
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
        if (!($calendar instanceof \ESN\CalDAV\SharedCalendar)) {
            return;
        }

        $payload = $this->parseEventPayload($data);
        if ($payload === null) {
            return;
        }
        list($event, $dataAsString) = $payload;

        $pathExploded = explode('/', $calendarPathObject);

        $dataMessage = [
            'eventPath' => '/' . $calendarPathObject,
            'event' => $event,
            'rawEvent' => $dataAsString,
            'import' => array_key_exists('import', $this->server->httpRequest->getQueryParameters())
        ];

        if($old_event) {
            $dataMessage['old_event'] = $old_event;
        }

        $options = [
            'action' => $action,
            'eventSourcePath' => $calendarPathObject,
            'calendarid' => $calendar->getCalendarId(),
            'objectUri' => $pathExploded[3],
            'calendarUri' => $pathExploded[2]
        ];

        $this->cancelOrphanedAlarmOnUidChange($action, $old_event, $event, $calendarPathObject);

        $this->createMessage($this->EVENT_TOPICS['EVENT_ALARM_'.$action], $dataMessage);
        $this->notifySubscribers($calendar->getSubscribers(), $dataMessage, $options);
        $this->notifyInvites($calendar->getInvites(), $dataMessage, $options);
    }

    /**
     * Parses the raw event payload (iCalendar or jCal, string or stream).
     *
     * @return array|null [$event, $dataAsString], or null when $data is not a
     *                    parseable type.
     */
    private function parseEventPayload($data): ?array {
        // Validate that $data is a string or resource before parsing
        if (!is_string($data) && !is_resource($data)) {
            error_log('EventRealTimePlugin: Invalid data type in addSharedUsers, expected string or resource, got ' . gettype($data));
            return null;
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
        }

        $event->remove('method');

        return [$event, $dataAsString];
    }

    /**
     * When a PUT replaces the content of an existing resource with a different UID
     * (client reuses the same .ics filename for a completely different event), the
     * old alarm must be cancelled before the new one is created, otherwise consumers
     * are left with an orphaned alarm indexed on the old UID.
     */
    private function cancelOrphanedAlarmOnUidChange($action, $old_event, $event, $calendarPathObject): void {
        if ($action !== 'UPDATED') {
            return;
        }
        if (!$old_event) {
            return;
        }

        $oldUid = isset($old_event->VEVENT->UID) ? (string)$old_event->VEVENT->UID : null;
        $newUid = isset($event->VEVENT->UID) ? (string)$event->VEVENT->UID : null;

        if ($this->uidChanged($oldUid, $newUid)) {
            $this->createMessage($this->EVENT_TOPICS['EVENT_ALARM_CANCEL'], [
                'eventPath' => '/' . $calendarPathObject,
                'event'     => $old_event,
                'rawEvent'  => $old_event->serialize(),
            ]);
        }
    }

    private function uidChanged(?string $oldUid, ?string $newUid): bool {
        return $oldUid && $newUid && $oldUid !== $newUid;
    }

    private function notifySubscribers($subscribers, $dataMessage, $options) {
        foreach($subscribers as $subscriber) {
            $dataMessage['eventPath'] = Utils::objectPathFromUri($subscriber['principaluri'],  $subscriber['uri'], $options['objectUri']);
            $dataMessage['eventSourcePath'] = '/' . $options['eventSourcePath'];

            $this->createMessage($this->EVENT_TOPICS['EVENT_'.$options['action']], $dataMessage);
        }
    }

    private function notifyInvites($invites, $dataMessage, $options) {
        $calendarUri = null;
        $eventCalendar = null;

        foreach($invites as $user) {
            if($user->inviteStatus === \Sabre\DAV\Sharing\Plugin::INVITE_INVALID) {
                continue;
            }

            $inviteeCalendar = $this->findInviteeCalendar($user->principal, $options['calendarid']);
            if ($inviteeCalendar !== null) {
                list($calendarUri, $eventCalendar) = $inviteeCalendar;
            }

            $dataMessage['event'] = Utils::hidePrivateEventInfoForUser($dataMessage['event'], $eventCalendar, $user->principal);

            $dataMessage['eventPath'] = Utils::objectPathFromUri($user->principal,  $calendarUri, $options['objectUri']);

            $this->createMessage($this->EVENT_TOPICS['EVENT_'.$options['action']], $dataMessage);
        }
    }

    /**
     * Finds the invitee's instance of the shared calendar.
     *
     * @return array|null [$calendarUri, $eventCalendar node], or null when the
     *                    invitee has no instance of the calendar.
     */
    private function findInviteeCalendar($principal, $calendarid): ?array {
        $found = null;

        foreach($this->caldavBackend->getCalendarsForUser($principal) as $calendarUser) {
            if($calendarUser['id'][0] == $calendarid) {
                $calendarUri = $calendarUser['uri'];

                list(,, $userId) = explode('/', $principal);
                $found = [$calendarUri, $this->server->tree->getNodeForPath('calendars/' . $userId . '/' . $calendarUri)];
            }
        }

        return $found;
    }

    function schedule(\Sabre\VObject\ITip\Message $iTipMessage) {
        if($iTipMessage->method === 'COUNTER') {
            return true;
        }

        if ($this->hasPendingOrFailedScheduleStatus($iTipMessage)) {
            return false;
        }

        $recipientPrincipalUri = $this->resolveRecipientPrincipalUri($iTipMessage);

        // If we still don't have a principal URI, we can't process this iTip message
        if (!$recipientPrincipalUri) {
            return true;
        }

        // Get sender principal URI to check if recipient is the organizer themselves
        $senderPrincipalUri = Utils::getPrincipalByUri($iTipMessage->sender, $this->server);

        if ($this->isSelfAddressedRequest($iTipMessage, $recipientPrincipalUri, $senderPrincipalUri)) {
            return true;
        }

        $delivery = $this->resolveEventDelivery($iTipMessage, $recipientPrincipalUri);
        if ($delivery === null) {
            return false;
        }
        list($eventPath, $upToDateEventIcs, $foundInCalendar) = $delivery;

        $dataMessage = [
            'eventPath' => '/' . $eventPath,
            'event'     => VObject\Reader::read($upToDateEventIcs),
            'rawEvent'  => $upToDateEventIcs,
        ];

        $this->createMessage(
            $this->EVENT_TOPICS['EVENT_'.$iTipMessage->method],
            $dataMessage
        );

        list(,, $calendarUri, $objectUri) = explode('/', $eventPath);
        $calendar = $this->server->tree->getNodeForPath('/'. substr($eventPath,0,strrpos($eventPath,'/')));

        $options = [
            'action' => $iTipMessage->method,
            'eventSourcePath' => $eventPath,
            'calendarid' => $calendar->getCalendarId(),
            'objectUri' => $objectUri,
            'calendarUri' => $calendarUri
        ];

        $this->notifySubscribers($calendar->getSubscribers(), $dataMessage, $options);

        $this->notifyAlarm($iTipMessage, $dataMessage);

        $this->notifyResourceEventCreated($iTipMessage, $recipientPrincipalUri, $eventPath, $upToDateEventIcs);

        $this->emitSearchIndexingEvent($iTipMessage->method, $foundInCalendar, $dataMessage);

        if (!$this->notifyResourceReplyStatus($iTipMessage, $senderPrincipalUri)) {
            return false;
        }

        $this->publishMessages();
        return true;
    }

    private function hasPendingOrFailedScheduleStatus(\Sabre\VObject\ITip\Message $iTipMessage): bool {
        switch($iTipMessage->scheduleStatus) {
            case \ESN\CalDAV\Schedule\IMipPlugin::SCHEDSTAT_SUCCESS_PENDING:
            case \ESN\CalDAV\Schedule\IMipPlugin::SCHEDSTAT_FAIL_TEMPORARY:
            case \ESN\CalDAV\Schedule\IMipPlugin::SCHEDSTAT_FAIL_PERMANENT:
                return true;
        }

        return false;
    }

    private function resolveRecipientPrincipalUri(\Sabre\VObject\ITip\Message $iTipMessage): ?string {
        $recipientPrincipalUri = Utils::getPrincipalByUri($iTipMessage->recipient, $this->server);
        if ($recipientPrincipalUri) {
            return $recipientPrincipalUri;
        }

        // If getPrincipalByUri fails (external recipient), try to find principal by email
        $recipientEmail = $iTipMessage->recipient;
        if (strpos($recipientEmail, 'mailto:') === 0) {
            $recipientEmail = substr($recipientEmail, 7);
        }

        // Use PrincipalBackend from CalDAV backend to find user by email
        if ($this->caldavBackend && method_exists($this->caldavBackend, 'getPrincipalBackend')) {
            $principalBackend = $this->caldavBackend->getPrincipalBackend();
            if ($principalBackend && method_exists($principalBackend, 'getAuthTenantByEmail')) {
                $tenant = $principalBackend->getAuthTenantByEmail($recipientEmail);
                if ($tenant) {
                    return (string) $tenant->getPrincipal();
                }
            }
        }

        return null;
    }

    /**
     * Don't send REQUEST to organizer themselves (fixes #215)
     * This happens when ATTENDEE doesn't have mailto: prefix and matches the organizer
     */
    private function isSelfAddressedRequest(\Sabre\VObject\ITip\Message $iTipMessage, string $recipientPrincipalUri, $senderPrincipalUri): bool {
        return $iTipMessage->method === 'REQUEST' &&
            $senderPrincipalUri &&
            $recipientPrincipalUri === $senderPrincipalUri;
    }

    /**
     * Locates the event in the recipient's calendar (it should exist now,
     * created by the schedule plugin), falling back to the default calendar
     * path when not found (e.g., CANCEL deleted it, or external REQUEST).
     *
     * @return array|null [$eventPath (relative), $upToDateEventIcs, $foundInCalendar],
     *                    or null when delivery cannot be resolved.
     */
    private function resolveEventDelivery(\Sabre\VObject\ITip\Message $iTipMessage, string $recipientPrincipalUri): ?array {
        list($eventPath, $upToDateEventIcs) = Utils::getEventObjectFromAnotherPrincipalHome($recipientPrincipalUri, $iTipMessage->uid, $iTipMessage->method, $this->server);

        // Track whether the event already existed in the recipient's calendar before delivery.
        // Used to choose between EVENT_CREATED and EVENT_UPDATED for search indexing.
        $foundInCalendar = ($eventPath !== null);

        if (!$eventPath) {
            $defaultDelivery = $this->buildDefaultEventDelivery($iTipMessage, $recipientPrincipalUri);
            if ($defaultDelivery === null) {
                return null;
            }
            list($eventPath, $upToDateEventIcs) = $defaultDelivery;
        }

        // Normalize to relative path — getEventObjectFromAnotherPrincipalHome() returns an
        // absolute path ('/calendars/...') but all downstream code assumes relative (no leading slash).
        return [ltrim($eventPath, '/'), $upToDateEventIcs, $foundInCalendar];
    }

    /**
     * Constructs the event path in the recipient's default calendar and uses
     * the iTip message itself as the event data.
     *
     * @return array|null [$eventPath, $eventIcs], or null when the recipient's
     *                    calendar home cannot be resolved.
     */
    private function buildDefaultEventDelivery(\Sabre\VObject\ITip\Message $iTipMessage, string $recipientPrincipalUri): ?array {
        $aclPlugin = $this->server->getPlugin('acl');
        if (!$aclPlugin) {
            return null;
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
            return null;
        }

        $homePath = $result[$caldavNS . 'calendar-home-set']->getHref();
        $defaultCalendarPath = $result[$caldavNS . 'schedule-default-calendar-URL']->getHref();

        // Extract calendar URI from the full path
        // defaultCalendarPath is like: /calendars/54b64eadf6d7d8e41d263e0f/events
        // Construct event path: calendars/homeId/calendarUri/uid.ics
        $eventPath = 'calendars/' . basename($homePath) . '/' . basename($defaultCalendarPath) . '/' . $iTipMessage->uid . '.ics';

        return [$eventPath, $iTipMessage->message->serialize()];
    }

    private function notifyAlarm(\Sabre\VObject\ITip\Message $iTipMessage, array $dataMessage): void {
        if ($iTipMessage->method === 'REQUEST' && $iTipMessage->significantChange) {
            // Only notify the alarm service if the recipient has accepted the event.
            // The ICS in $dataMessage is fetched after scheduleLocalDelivery has merged
            // the organizer's changes, so the recipient's PARTSTAT reflects the current state.
            if ($this->recipientHasAcceptedMaster($dataMessage['event'], strtolower($iTipMessage->recipient))) {
                $this->createMessage($this->EVENT_TOPICS['EVENT_ALARM_UPDATED'], $dataMessage);
            }
        } elseif ($iTipMessage->method === 'CANCEL') {
            $this->createMessage($this->EVENT_TOPICS['EVENT_ALARM_CANCEL'], $dataMessage);
        }
    }

    private function recipientHasAcceptedMaster($vcalendar, string $recipientUri): bool {
        $masterVEvent = $this->findMasterVEvent($vcalendar);

        if (!$masterVEvent || !isset($masterVEvent->ATTENDEE)) {
            return false;
        }

        return $this->attendeePartstat($masterVEvent, $recipientUri) === 'ACCEPTED';
    }

    private function findMasterVEvent($vcalendar) {
        foreach ($vcalendar->VEVENT as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                return $vevent;
            }
        }

        return null;
    }

    /**
     * Returns the uppercased PARTSTAT of the attendee matching the recipient
     * (NEEDS-ACTION when the parameter is absent), or null when the recipient
     * is not an attendee.
     */
    private function attendeePartstat($vevent, string $recipientUri): ?string {
        foreach ($vevent->ATTENDEE as $attendee) {
            if (strtolower($attendee->getValue()) === $recipientUri) {
                return isset($attendee['PARTSTAT'])
                    ? strtoupper(trim($attendee['PARTSTAT']->getValue()))
                    : 'NEEDS-ACTION';
            }
        }

        return null;
    }

    private function notifyResourceEventCreated(\Sabre\VObject\ITip\Message $iTipMessage, string $recipientPrincipalUri, string $eventPath, $upToDateEventIcs): void {
        if ($iTipMessage->method !== 'REQUEST' || !$iTipMessage->significantChange) {
            return;
        }
        if (!Utils::isResourceFromPrincipal($recipientPrincipalUri)) {
            return;
        }

        $pathExploded = explode('/', $eventPath);

        $this->createMessage($this->EVENT_TOPICS['RESOURCE_EVENT_CREATED'], [
            'resourceId' => $pathExploded[1],
            'eventId' => $pathExploded[3],
            'eventPath' => '/' . $eventPath,
            'ics' => $upToDateEventIcs
        ]);
    }

    /**
     * scheduleLocalDelivery writes directly to the backend (bypassing the HTTP layer),
     * so beforeCreateFile / beforeWriteContent never fire and the search index is never
     * notified.  Emit the appropriate search-indexing event explicitly here.
     *
     * • REQUEST → created (new invite) or updated (re-invite / modification)
     * • CANCEL  → deleted
     * REPLY is intentionally omitted: the organiser's calendar is updated via a normal
     * PUT on the attendee side which already triggers beforeWriteContent on the organiser.
     */
    private function emitSearchIndexingEvent(string $method, bool $foundInCalendar, array $dataMessage): void {
        if ($method === 'REQUEST') {
            $indexTopic = $foundInCalendar
                ? $this->EVENT_TOPICS['EVENT_UPDATED']
                : $this->EVENT_TOPICS['EVENT_CREATED'];
            $this->createMessage($indexTopic, $dataMessage);
        } elseif ($method === 'CANCEL') {
            $this->createMessage($this->EVENT_TOPICS['EVENT_DELETED'], $dataMessage);
        }
    }

    /**
     * When a resource replies, notifies the resource service of the accepted
     * or declined status. Returns false when the resource's own copy of the
     * event cannot be found, aborting the scheduling flow like before.
     */
    private function notifyResourceReplyStatus(\Sabre\VObject\ITip\Message $iTipMessage, $senderPrincipalUri): bool {
        if (!$senderPrincipalUri || $iTipMessage->method !== 'REPLY') {
            return true;
        }
        if (!Utils::isResourceFromPrincipal($senderPrincipalUri)) {
            return true;
        }

        list($eventPath, $upToDateEventIcs) = Utils::getEventObjectFromAnotherPrincipalHome($senderPrincipalUri, $iTipMessage->uid, $iTipMessage->method, $this->server);

        if (!$eventPath) {
            return false;
        }

        $explodedSenderPrincipalUri = explode('/', $senderPrincipalUri);

        foreach ($iTipMessage->message->VEVENT->ATTENDEE as $attendee) {
            if ($attendee->getValue() !== $iTipMessage->sender) {
                continue;
            }

            $topic = $this->resourceReplyTopic($attendee['PARTSTAT']->getValue());
            if ($topic !== null) {
                $this->createMessage($topic, [
                    'resourceId' => $explodedSenderPrincipalUri[2],
                    'eventId' => $iTipMessage->uid,
                    'eventPath' => '/' . $eventPath,
                    'ics' => $upToDateEventIcs
                ]);
            }
        }

        return true;
    }

    private function resourceReplyTopic(string $partstat): ?string {
        switch($partstat) {
            case 'ACCEPTED':
                return $this->EVENT_TOPICS['RESOURCE_EVENT_ACCEPTED'];
            case 'DECLINED':
                return $this->EVENT_TOPICS['RESOURCE_EVENT_DECLINED'];
        }

        return null;
    }

    function itip(\Sabre\VObject\ITip\Message $iTipMessage) {
        if ($this->schedule($iTipMessage)) {
            $this->publishMessages();
        }
    }
}
