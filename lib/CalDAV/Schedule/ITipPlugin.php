<?php

namespace ESN\CalDAV\Schedule;

use ESN\CalDAV\SharedCalendar;
use ESN\CalDAV\TeamCalendarSchedulingRecipientPlugin;
use ESN\CalDAV\VideoConferenceDecorator;
use \Sabre\VObject;
use \Sabre\VObject\ITip\Message;
use \Sabre\DAV;
use ESN\Utils\Utils;
use ESN\DAV\Sharing\Plugin as SharingPlugin;


class ITipPlugin extends \Sabre\DAV\ServerPlugin {

    protected $server;

    function initialize(DAV\Server $server)
    {
        $this->server = $server;
        $server->on('method:ITIP', [$this, 'iTip'], 80);
        // Also handle POST /itip — the Twake Calendar Side Service consumer
        // uses standard POST rather than the custom ITIP verb. Priority 75 so
        // this runs before the handlers that resolve the request path in the
        // DAV tree: 'itip' is a virtual route, they would 404 on it.
        $server->on('method:POST', [$this, 'handlePost'], 75);
    }

    function handlePost($request, $response)
    {
        if ($request->getPath() !== 'itip') {
            return true;
        }
        return $this->iTip($request);
    }

    function getPluginName()
    {
        return 'ITipPlugin';
    }

    /**
     * Use this method to tell the server this plugin defines additional
     * HTTP methods.
     *
     * This method is passed a uri. It should only return HTTP methods that are
     * available for the specified uri.
     *
     * @param string $path
     * @return array
     */
    function getHTTPMethods($path)
    {
        return ['ITIP'];
    }

    /**
     * This is the method called when a user receives an invitation through EMAIL.
     */
    function iTip($request)
    {
        $payload = json_decode($request->getBodyAsString());
        if (!$this->isWellFormedPayload($payload)) {
            return $this->send(400, null);
        }
        $issetdef = $this->propertyOrDefault($payload);

        $calendar = $this->parseCalendar($payload->ical);
        if (!$calendar) {
            return $this->send(400, null);
        }

        $message = new Message();
        $message->component = 'VEVENT';
        $message->uid = $payload->uid;
        $message->method = $issetdef('method', 'REQUEST');
        $message->sequence = (int) $issetdef('sequence', 0);
        $message->message = $calendar;
        $message->sender = $this->resolveSenderFromItipMessage($message, $issetdef('replyTo', $payload->sender));
        $message->recipient = 'mailto:' . $payload->recipient;

        if (strtoupper((string)$message->method) === 'COUNTER') {
            if (!$this->authenticatedCanActAsSender($message, true)) {
                return $this->send(403, null);
            }
            if (!$this->recipientIsStoredOrganizer($message)) {
                return $this->send(403, null);
            }
            if (!$this->hasValidStarts($message->message) || $this->counterProposesPastStart($message->message)) {
                return $this->send(400, null);
            }
        } elseif (!$this->recipientMatchesCurrentUser($message)) {
            return $this->send(403, null);
        }

        // The target calendar is decided upstream by the mail layer: the request explicitly
        // targets the recipient's calendar, and recipientMatchesCurrentUser() above already
        // asserts the authenticated principal owns it. We deliberately do NOT re-derive
        // participation by string-matching the recipient address against the ICS
        // ORGANIZER/ATTENDEE: that address can legitimately be an alias, a group/list address
        // or any address the mail layer resolved to this user. The routing decision (the URL)
        // is authoritative, so we honor it and deliver onto the targeted calendar.

        // Local iTIP delivery writes straight to the recipient's calendar and inbox,
        // bypassing calendarObjectChange where PUT filters these links.
        $attachmentPlugin = $this->server->getPlugin('caldav-binary-attachment');
        if ($attachmentPlugin) {
            $attachmentPlugin->filterCalendar($message->message);
        }
        $videoPlugin = $this->server->getPlugin('caldav-videoconference');
        if ($videoPlugin) {
            $videoPlugin->filterCalendar($message->message);
        } else {
            // Keep video decoration for servers without the optional plugin.
            VideoConferenceDecorator::decorate($message->message);
        }

        if($message->method !== 'COUNTER'){
            $this->server->getPlugin('caldav-schedule')->scheduleLocalDelivery($message);
            $this->server->emit('iTip', [$message]);
        } else {
            $this->server->emit('schedule', [$message]);
        }

        return $this->send(204, null);
    }

    function send($code, $body, $setContentType = true)
    {
        if (!isset($code)) {
            return true;
        }

        if ($body) {
            if ($setContentType) {
                $this->server->httpResponse->setHeader('Content-Type', 'application/json; charset=utf-8');
            }
            $this->server->httpResponse->setBody(json_encode($body));
        }
        $this->server->httpResponse->setStatus($code);
        
        return false;
    }

    // Every field is client-controlled: the ical is a mail attachment relayed verbatim.
    private function isWellFormedPayload($payload): bool
    {
        if (!is_object($payload)) {
            return false;
        }

        $requiredStrings = ['uid', 'sender', 'recipient', 'ical'];
        $optionalScalars = ['method', 'sequence'];

        return array_reduce($requiredStrings, fn($valid, $key) => $valid && $this->isNonEmptyString($payload, $key), true)
            && (!isset($payload->replyTo) || $this->isNonEmptyString($payload, 'replyTo'))
            && array_reduce($optionalScalars, fn($valid, $key) => $valid && (!isset($payload->{$key}) || is_scalar($payload->{$key})), true);
    }

    private function isNonEmptyString($payload, string $key): bool
    {
        return isset($payload->{$key}) && is_string($payload->{$key}) && $payload->{$key} !== '';
    }

    private function parseCalendar(string $ical): ?VObject\Component\VCalendar
    {
        try {
            $document = VObject\Reader::read($ical);
        } catch (VObject\ParseException | VObject\InvalidDataException $e) {
            return null;
        }

        return $document instanceof VObject\Component\VCalendar ? $document : null;
    }

    private function hasValidStarts(VObject\Component\VCalendar $vcalendar): bool
    {
        $events = $vcalendar->select('VEVENT');
        return !array_filter($events, fn($event) => isset($event->DTSTART) && !$this->isParsableDateTime($event->DTSTART));
    }

    private function isParsableDateTime(VObject\Property $property): bool
    {
        if (!($property instanceof VObject\Property\ICalendar\DateTime)) {
            return false;
        }

        try {
            $property->getDateTime();
            return true;
        } catch (\Exception | \ValueError $e) {
            return false;
        }
    }

    private function propertyOrDefault($jsonData)
    {
        return function ($key, $default = null) use ($jsonData) {
            return isset($jsonData->{$key}) ? $jsonData->{$key} : $default;
        };
    }

    // Preserve the sender calendar address from the iTIP payload so REPLY matching does not break on MAILTO/mailto casing.
    private function resolveSenderFromItipMessage(Message $message, string $senderInput): string
    {
        if (!$message->message || !$message->message->VEVENT) {
            return 'mailto:' . $senderInput;
        }

        $vevent = $message->message->VEVENT;

        $organizer = (string) $vevent->ORGANIZER;
        if ($organizer !== '' && $this->matchesCalendarAddress($organizer, $senderInput)) {
            return $organizer;
        }

        if ($vevent->ATTENDEE) {
            foreach ($vevent->ATTENDEE as $attendee) {
                if ($this->matchesCalendarAddress($attendee->getValue(), $senderInput)) {
                    return $attendee->getValue();
                }
            }
        }

        return 'mailto:' . $senderInput;
    }

    private function matchesCalendarAddress(string $calendarAddress, string $emailOrAddress): bool
    {
        $calendarAddress = stripos($calendarAddress, 'mailto:') === 0 ? substr($calendarAddress, 7) : $calendarAddress;
        $emailOrAddress = stripos($emailOrAddress, 'mailto:') === 0 ? substr($emailOrAddress, 7) : $emailOrAddress;

        return strtolower($calendarAddress) === strtolower($emailOrAddress);
    }

    // A proposal for a slot already past can never be accepted by the organizer.
    // A counter on a whole series (RRULE) keeps the start of the series, which is legitimately
    // past for an ongoing series, so such a counter is not checked.
    private function counterProposesPastStart(VObject\Component\VCalendar $vcalendar): bool
    {
        $events = $vcalendar->select('VEVENT');
        if (array_filter($events, fn($event) => isset($event->RRULE))) {
            return false;
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        return (bool) array_filter($events, fn($event) => $this->startsBefore($event, $now));
    }

    private function startsBefore(VObject\Component $event, \DateTimeImmutable $now): bool
    {
        if (!isset($event->DTSTART)) {
            return false;
        }

        $start = $event->DTSTART->getDateTime();
        if ($event->DTSTART->hasTime()) {
            return $start < $now;
        }

        // An all-day proposal for today is still upcoming
        return $start->format('Ymd') < $now->format('Ymd');
    }

    // A COUNTER is only ever addressed to the organizer. The ORGANIZER is never read from the
    // submitted ICS: it is client-controlled.
    // A local recipient must organize the event in a calendar they own or in a Team Calendar they
    // write to, which the sender cannot write to. An external organizer has no copy here, so the
    // sender's copy is the only one available: verifying the COUNTER is then up to the
    // organizer's own server.
    private function recipientIsStoredOrganizer(Message $message): bool
    {
        $recipientPrincipal = $this->normalizePrincipalUri(Utils::getPrincipalByUri($message->recipient, $this->server));
        if ($recipientPrincipal) {
            $organizers = array_merge(
                array_filter([$this->findStoredOrganizer($recipientPrincipal, $message)]),
                $this->findTeamCalendarOrganizers($recipientPrincipal, $message)
            );
            foreach ($organizers as $organizer) {
                if ($this->addressBelongsToPrincipal($organizer, $message->recipient, $recipientPrincipal)) {
                    return true;
                }
            }
            return false;
        }

        $senderPrincipal = Utils::getPrincipalByUri($message->sender, $this->server);
        $organizer = $senderPrincipal ? $this->findStoredOrganizer($senderPrincipal, $message) : null;
        return $organizer && $this->matchesCalendarAddress($organizer, $message->recipient);
    }

    // Matches the address itself, or any alias resolving to the same principal.
    private function addressBelongsToPrincipal(string $address, string $knownAddress, string $principal): bool
    {
        if ($this->matchesCalendarAddress($address, $knownAddress)) {
            return true;
        }

        $addressPrincipal = $this->normalizePrincipalUri(Utils::getPrincipalByUri($address, $this->server));
        return $addressPrincipal && $this->principalsMatch($this->server->getPlugin('acl'), $addressPrincipal, $principal);
    }

    protected function findStoredOrganizer(string $principalUri, Message $message): ?string
    {
        try {
            $storedEvent = Utils::getEventObjectFromAnotherPrincipalHome($principalUri, $message->uid, $message->method, $this->server);
        } catch (DAV\Exception $e) {
            return null;
        }
        if (!$storedEvent) {
            return null;
        }

        return $this->organizerOf($storedEvent[1]);
    }

    // The organizer copy of a Team Calendar event is not in a calendar the organizer owns:
    // look it up like scheduling does for a REPLY, in the Team Calendars the principal writes to.
    protected function findTeamCalendarOrganizers(string $principalUri, Message $message): array
    {
        $plugin = $this->server->getPlugin(TeamCalendarSchedulingRecipientPlugin::PLUGIN_NAME);
        if (!$plugin) {
            return [];
        }

        $organizers = [];
        try {
            $paths = $plugin->findCalendarObjectPathsWithoutSchedulingRecipient('calendars/' . basename($principalUri), $message->uid);
            foreach ($paths as $path) {
                $data = $this->server->tree->getNodeForPath($path)->get();
                $organizers[] = $this->organizerOf(is_resource($data) ? stream_get_contents($data) : $data);
            }
        } catch (DAV\Exception $e) {
            return [];
        }

        return array_values(array_filter($organizers));
    }

    private function organizerOf(string $calendarData): ?string
    {
        $vevent = VObject\Reader::read($calendarData)->VEVENT;
        return $vevent && isset($vevent->ORGANIZER) ? (string) $vevent->ORGANIZER : null;
    }

    private function authenticatedCanActAsSender(Message $message, bool $allowDelegation): bool
    {
        $authPlugin = $this->server->getPlugin('auth');
        $aclPlugin = $this->server->getPlugin('acl');
        if (!$authPlugin || !$aclPlugin) {
            return false;
        }

        $currentPrincipal = $this->normalizePrincipalUri($authPlugin->getCurrentPrincipal());
        if (!$currentPrincipal) {
            return false;
        }

        $senderPrincipal = $this->normalizePrincipalUri(Utils::getPrincipalByUri($message->sender, $this->server));
        if (!$senderPrincipal) {
            return false;
        }

        if ($this->principalsMatch($aclPlugin, $senderPrincipal, $currentPrincipal)) {
            return true;
        }

        return $allowDelegation && $this->hasDelegatedWriteAccess($aclPlugin, $senderPrincipal, $currentPrincipal);
    }

    private function hasDelegatedWriteAccess($aclPlugin, string $principalUri, string $currentPrincipal): bool
    {
        if ($this->principalsMatch($aclPlugin, $principalUri . '/calendar-proxy-write', $currentPrincipal)) {
            return true;
        }

        return $this->hasWritableSharedCalendarFromSender($principalUri, $currentPrincipal);
    }

    private function hasWritableSharedCalendarFromSender(string $senderPrincipal, string $currentPrincipal): bool
    {
        $homePath = 'calendars/' . basename($currentPrincipal);

        try {
            $homeNode = $this->server->tree->getNodeForPath($homePath);
        } catch (\Throwable $e) {
            return false;
        }

        if (!method_exists($homeNode, 'getChildren')) {
            return false;
        }

        foreach ($homeNode->getChildren() as $child) {
            if (!($child instanceof SharedCalendar) || !$child->isSharedInstance()) {
                continue;
            }

            $shareOwner = $this->normalizePrincipalUri($child->getOwner());
            if (!$shareOwner || !$this->principalsMatch($this->server->getPlugin('acl'), $shareOwner, $senderPrincipal)) {
                continue;
            }

            $shareAccess = (int) $child->getShareAccess();
            if ($shareAccess === SharingPlugin::ACCESS_READWRITE || $shareAccess === SharingPlugin::ACCESS_ADMINISTRATION) {
                return true;
            }
        }

        return false;
    }

    private function recipientMatchesCurrentUser(Message $message): bool
    {
        $authPlugin = $this->server->getPlugin('auth');
        $aclPlugin = $this->server->getPlugin('acl');
        if (!$authPlugin || !$aclPlugin) {
            return false;
        }

        $currentPrincipal = $this->normalizePrincipalUri($authPlugin->getCurrentPrincipal());
        if (!$currentPrincipal) {
            return false;
        }

        $recipientPrincipal = $this->normalizePrincipalUri(Utils::getPrincipalByUri($message->recipient, $this->server));
        return $recipientPrincipal && $this->principalsMatch($aclPlugin, $recipientPrincipal, $currentPrincipal);
    }

    private function principalsMatch($aclPlugin, string $principal, string $currentPrincipal): bool
    {
        $a = ltrim($principal, '/');
        $b = ltrim($currentPrincipal, '/');

        return $aclPlugin->principalMatchesPrincipal($a, $b)
            || $aclPlugin->principalMatchesPrincipal("/$a", $b)
            || $aclPlugin->principalMatchesPrincipal($a, "/$b")
            || $aclPlugin->principalMatchesPrincipal("/$a", "/$b");
    }

    private function normalizePrincipalUri(?string $principal): ?string
    {
        if (!$principal) {
            return null;
        }

        return ltrim($principal, '/');
    }
}
