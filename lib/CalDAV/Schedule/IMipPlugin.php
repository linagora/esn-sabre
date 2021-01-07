<?php

namespace ESN\CalDAV\Schedule;

use DateTimeZone;
use \Sabre\DAV;
use Sabre\VObject\Component\VCalendar;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use \Sabre\VObject\ITip;
use \Sabre\VObject\Property;
use \ESN\Utils\Utils as Utils;
use Sabre\VObject\Reader;

class IMipPlugin extends \Sabre\CalDAV\Schedule\IMipPlugin {
    protected $httpClient;
    protected $server;
    protected $apiroot;
    protected $amqpPublisher;
    protected $newAttendees;

    const HIGHER_PRIORITY_BEFORE_SCHEDULE = 90;
    const SCHEDSTAT_SUCCESS_PENDING = '1.0';
    const SCHEDSTAT_SUCCESS_UNKNOWN = '1.1';
    const SCHEDSTAT_FAIL_TEMPORARY = '5.1';
    const SCHEDSTAT_FAIL_PERMANENT = '5.2';
    const SEND_NOTIFICATION_EMAIL_TOPIC = 'calendar:event:notificationEmail:send';

    function __construct($apiroot, $amqpPublisher) {
        $this->apiroot = $apiroot;
        $this->amqpPublisher = $amqpPublisher;
    }

    function calendarObjectChange(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        $newEventAttendees = $this->parseAttendees($vCal);

        $this->newAttendees = [];

        if (!$isNew) {
            $node = $this->server->tree->getNodeForPath($request->getPath());
            $oldObj = Reader::read($node->get());
            $oldEventAttendees = $this->parseAttendees($oldObj);

            $newAttendees = [];

            foreach ($newEventAttendees as $attendee => $instances) {
                if (!isset($oldEventAttendees[$attendee])) {
                    $newAttendees[$attendee] = 1;
                }
            }

            $this->newAttendees = $newAttendees;
        } else {
            $oldObj = null;

            $this->newAttendees = $newEventAttendees;
        }

        if ($oldObj) {
            // Destroy circular references so PHP will GC the object.
            $oldObj->destroy();
        }
    }

    private function parseAttendees(\Sabre\VObject\Document $vCal) {
        $attendees = [];

        foreach ($vCal->VEVENT as $vevent) {
            if(isset($vevent->ATTENDEE) && $vevent->ATTENDEE) {
                foreach ($vevent->ATTENDEE as $eventAttendee) {
                    if (!isset($attendees[$eventAttendee->getNormalizedValue()])) {
                        $attendees[$eventAttendee->getNormalizedValue()] = 1;
                    }
                }
            }
        }

        return $attendees;
    }

    function schedule(ITip\Message $iTipMessage) {
        $recipientPrincipalUri = Utils::getPrincipalByUri($iTipMessage->recipient, $this->server);
        $matched = preg_match("|/(calendars/.*/.*)/|", $_SERVER["REQUEST_URI"], $matches);

        if ($matched) {
            $calendarPath = $matches[1];
        }

        if (!($this->checkPreconditions($iTipMessage, $matched, $recipientPrincipalUri))) {
            return;
        }

        $calendarNode = $this->server->tree->getNodeForPath($calendarPath);

        list($eventPath, ) = Utils::getEventObjectFromAnotherPrincipalHome($recipientPrincipalUri, $iTipMessage->uid, $iTipMessage->method, $this->server);

        // If event doesn't exist in recipient home, we define event path
        if (!$eventPath) {
            $fullEventPath = '/' . $calendarPath . '/' . $iTipMessage->uid . '.ics';
        } else {
            $fullEventPath = '/' . $eventPath;
        }

        // No need to split iTip message for Sabre User
        // Sabre can handle multiple event iTip message
        if ($iTipMessage->method === 'COUNTER' || $recipientPrincipalUri) {
            $eventMessages = [$iTipMessage->message];
        } else {
            $eventMessages = $this->explodeItipMessageEvents($iTipMessage->message);
        }

        foreach ($eventMessages as $eventMessage) {
            $message = [
                'senderEmail' => substr($iTipMessage->sender, 7),
                'recipientEmail' => substr($iTipMessage->recipient, 7),
                'method' => $iTipMessage->method,
                'event' => $eventMessage->serialize(),
                'notify' => true,
                'calendarURI' => $calendarNode->getName(),
                'eventPath' => $fullEventPath
            ];

            if (isset($this->newAttendees[$iTipMessage->recipient])) {
                $message['isNewEvent'] = true;
            }

            $this->amqpPublisher->publish(self::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($message));
        }
    }

    private function explodeItipMessageEvents($message) {
        $messages = [];

        $vevents = $message->select('VEVENT');

        foreach($vevents as $vevent) {
            $currentMessage = clone $message;

            $currentMessage->remove('VEVENT');
            $currentMessage->add($vevent);

            $messages[] = $currentMessage;
        }

        return $messages;
    }

    function initialize(DAV\Server $server) {
        parent::initialize($server);
        $this->server = $server;

        $server->on('calendarObjectChange', [$this, 'calendarObjectChange'], self::HIGHER_PRIORITY_BEFORE_SCHEDULE);
    }

    private function checkPreconditions(ITip\Message $iTipMessage, int $matched, $principalUri): bool
    {
        if (!$this->apiroot) {
            $iTipMessage->scheduleStatus = self::SCHEDSTAT_FAIL_PERMANENT;
            return false;
        }

        // Not sending any emails if the system considers the update
        // insignificant.
        if (!$iTipMessage->significantChange && !$iTipMessage->hasChange) {
            if (!$iTipMessage->scheduleStatus) {
                $iTipMessage->scheduleStatus = self::SCHEDSTAT_SUCCESS_PENDING;
            }
            return false;
        }

        if (parse_url($iTipMessage->sender, PHP_URL_SCHEME) !== 'mailto') {
            return false;
        }

        if (parse_url($iTipMessage->recipient, PHP_URL_SCHEME) !== 'mailto') {
            return false;
        }

        if (!$matched) {
            $iTipMessage->scheduleStatus = self::SCHEDSTAT_FAIL_TEMPORARY;
            error_log("iTip Delivery could not be performed because calendar uri could not be found.");
            return false;
        }

        if (Utils::isResourceFromPrincipal($principalUri)) {
            $iTipMessage->scheduleStatus = self::SCHEDSTAT_FAIL_TEMPORARY;

            return false;
        }

        return true;
    }
}
