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
        $message->sender = 'mailto:' . $issetdef('replyTo', $payload->sender);
        $message->recipient = 'mailto:' . $payload->recipient;
        $message->message = VObject\Reader::read($payload->ical);

        // we need to check that the current user ($message->recipient) is related to the event,
        // because he's either organizer, or attendee, or both.
        //
        // Some use cases, like a user forwarding an invite email to another user, brings a recipient
        // that is not, at all, in the event. We ignore it
        if (!$this->assertRecipientIsConcernedByEvent($message->message->vevent, $message->recipient)) {
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

    private function assertRecipientIsConcernedByEvent($vevent, $recipient)
    {
        $isConcerned = false;
        try {
            $organizer = (string)$vevent->ORGANIZER;
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
                    break;
                }
            }
        }

        return $isConcerned;
    }
}
