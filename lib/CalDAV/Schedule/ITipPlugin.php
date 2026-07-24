<?php

namespace ESN\CalDAV\Schedule;

use ESN\CalDAV\SharedCalendar;
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
        $issetdef = $this->propertyOrDefault($payload);

        if (!isset($payload->uid) || !$payload->sender || !$payload->recipient || !$payload->ical) {
            return $this->send(400, null);
        }

        $message = new Message();
        $message->component = 'VEVENT';
        $message->uid = $payload->uid;
        $message->method = $issetdef('method', 'REQUEST');
        $message->sequence = $issetdef('sequence', '0');
        $message->message = VObject\Reader::read($payload->ical);
        // Events coming from an external system carry the video conference link in the
        // OpenPaaS property only: expose it to the recipient's clients as well.
        VideoConferenceDecorator::decorate($message->message);
        $message->sender = $this->resolveSenderFromItipMessage($message, $issetdef('replyTo', $payload->sender));
        $message->recipient = 'mailto:' . $payload->recipient;

        if (strtoupper((string)$message->method) === 'COUNTER') {
            if (!$this->authenticatedCanActAsSender($message, true)) {
                return $this->send(403, null);
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
