<?php

namespace ESN\CalDAV\Schedule;

use \Sabre\DAV\Exception;
use \Sabre\VObject;
use \Sabre\VObject\ITip\Message;
use \Sabre\DAV;


class ITipPlugin extends \Sabre\DAV\ServerPlugin {

    protected $server;

    function initialize(DAV\Server $server)
    {
        $this->server = $server;
        $server->on('method:ITIP', [$this, 'iTip'], 80);
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
        $message->sender = $this->resolveSenderFromItipMessage($message, $issetdef('replyTo', $payload->sender));
        $message->recipient = 'mailto:' . $payload->recipient;

        // we need to check that the current user ($message->recipient) is related to the event,
        // because he's either organizer, or attendee, or both.
        //
        // Some use cases, like a user forwarding an invite email to another user, brings a recipient
        // that is not, at all, in the event. We ignore it
        if (!$this->assertRecipientIsConcernedByEvent($message)) {
            $this->server->getLogger()->error("Recipient ". $message->recipient ." is not organizer, not attendee of event ". (string)$message->message->VEVENT->UID .": skipping");
            return $this->send(400, null);
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

    private function assertRecipientIsConcernedByEvent(Message $message)
    {
        $vevent = $message->message->VEVENT;
        $recipient = $message->recipient;

        $isConcerned = false;
        $hasOrganizer = false;
        $senderIsAttendee = false;
        try {
            $organizer = (string)$vevent->ORGANIZER;
            $hasOrganizer = trim((string)$organizer) !== '';
            if (strtolower($organizer) === strtolower($recipient)) {
                $isConcerned = true;
            }
        } catch (Exception $e) {
            error_log("Error while trying to fetch event organizer: " . (string)$e);
        }
        if ($vevent->ATTENDEE) {
            foreach ($vevent->ATTENDEE as $attendee) {
                if (strtolower((string)$attendee) === strtolower($recipient)) {
                    $isConcerned = true;
                } else if (strtolower((string)$attendee) === strtolower((string)$message->sender)) {
                    $senderIsAttendee = true;
                }
            }
        }

        if (!$isConcerned
            && strtoupper((string)$message->method) === 'REPLY'
            && !$hasOrganizer
            && $senderIsAttendee) {
            $isConcerned = true;
        }

        return $isConcerned;
    }
}
