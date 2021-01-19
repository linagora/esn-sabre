<?php

namespace ESN\CalDAV\Schedule;

use DateTimeZone;
use \Sabre\DAV;
use Sabre\VObject\Component\VCalendar;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Document;
use \Sabre\VObject\ITip;
use \Sabre\VObject\Property;
use \ESN\Utils\Utils as Utils;
use Sabre\VObject\Reader;

class IMipPlugin extends \Sabre\CalDAV\Schedule\IMipPlugin {
    protected $server;
    protected $amqpPublisher;

    protected $isNewEvent = false;
    protected $formerEventICal;

    const HIGHER_PRIORITY_BEFORE_SCHEDULE = 90;
    const SCHEDSTAT_SUCCESS_PENDING = '1.0';
    const SCHEDSTAT_SUCCESS_UNKNOWN = '1.1';
    const SCHEDSTAT_FAIL_TEMPORARY = '5.1';
    const SCHEDSTAT_FAIL_PERMANENT = '5.2';
    const SEND_NOTIFICATION_EMAIL_TOPIC = 'calendar:event:notificationEmail:send';

    const MASTER_EVENT = 'master';

    function __construct($amqpPublisher) {
        $this->amqpPublisher = $amqpPublisher;
    }

    function initialize(DAV\Server $server) {
        parent::initialize($server);
        $this->server = $server;

        $server->on('calendarObjectChange', [$this, 'calendarObjectChange'], self::HIGHER_PRIORITY_BEFORE_SCHEDULE);
    }

    /**
     * Save previous version of the modified event
     *
     * @param RequestInterface $request
     * @param ResponseInterface $response
     * @param VCalendar $vCal
     * @param $calendarPath
     * @param $modified
     * @param $isNew
     */
    function calendarObjectChange(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        $this->isNewEvent = $isNew;

        if (!$isNew) {
            $node = $this->server->tree->getNodeForPath($request->getPath());

            $this->formerEventICal = $node->get();
        }
    }

    /**
     * Handle IMip notification
     *
     * @param ITip\Message $iTipMessage
     */
    function schedule(ITip\Message $iTipMessage) {
        $recipientPrincipalUri = Utils::getPrincipalByUri($iTipMessage->recipient, $this->server);
        $matched = preg_match("|/(calendars/.*/.*)/|", $_SERVER["REQUEST_URI"], $matches);

        if ($matched) {
            $calendarPath = $matches[1];
            // TODO Handle unmatched calendar error
        }

        if (!($this->checkPreconditions($iTipMessage, $matched, $recipientPrincipalUri))) {
            return;
        }

        // No need to split iTip message for Sabre User
        // Sabre can handle multiple event iTip message
        if ($iTipMessage->method === 'COUNTER') {
            $eventMessages = [['message' => $iTipMessage->message]];
        } else {
            if ($this->isNewEvent || $iTipMessage->method !== 'REQUEST') {
                $eventMessages = $this->splitItipMessageEvents($iTipMessage->message, $this->isNewEvent);
            } else {
                $formerEvent = Reader::read($this->formerEventICal);
                $eventMessages = $this->computeModifiedEventMessages($iTipMessage->message, $formerEvent, $iTipMessage->recipient);
            }
        }

        $fullEventPath = $this->getEventFullPath($recipientPrincipalUri, $iTipMessage, $calendarPath);
        $calendarNode = $this->server->tree->getNodeForPath($calendarPath);

        foreach ($eventMessages as $eventMessage) {
            $message = [
                'senderEmail' => substr($iTipMessage->sender, 7),
                'recipientEmail' => substr($iTipMessage->recipient, 7),
                'method' => $eventMessage['message']->METHOD->getValue(),
                'event' => $eventMessage['message']->serialize(),
                'notify' => true,
                'calendarURI' => $calendarNode->getName(),
                'eventPath' => $fullEventPath
            ];

            if (isset($eventMessage['newEvent'])) {
                $message['isNewEvent'] = true;
            }

            $this->amqpPublisher->publish(self::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($message));

            $iTipMessage->scheduleStatus = self::SCHEDSTAT_SUCCESS_UNKNOWN;
        }
    }

    /**
     * Split ITip message in one message per VEVENT
     *
     * @param VCalendar $message ITip\Message Scheduled iTip message
     * @param bool $isNewEvent Are we scheduling a new event ?
     * @return array message list to send
     */
    private function splitItipMessageEvents(VCalendar $message, bool $isNewEvent) {
        $messagesToSend = [];

        $vevents = $message->select('VEVENT');

        foreach($vevents as $vevent) {
            $currentMessage = clone $message;

            $currentMessage->remove('VEVENT');
            $currentMessage->add($vevent);

            $messageToSend = ['message' => $currentMessage];
            if ($isNewEvent) {
                $messageToSend['newEvent'] = 1;
            }

            $messagesToSend[] = $messageToSend;
        }

        return $messagesToSend;
    }

    /**
     * Check if IMip notification should be done
     *
     * @param ITip\Message $iTipMessage
     * @param int $matched
     * @param $principalUri
     * @return bool
     */
    private function checkPreconditions(ITip\Message $iTipMessage, int $matched, $principalUri) {
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

    /**
     * Is the recipient attending the event ?
     *
     * @param $recipient
     * @param VEvent $vEvent
     * @return bool
     */
    function isAttending($recipient, VEvent $vEvent) {
        if (isset($vEvent->ATTENDEE) && $vEvent->ATTENDEE) {
            foreach ($vEvent->ATTENDEE as $eventAttendee) {
                if ($eventAttendee->getNormalizedValue() === $recipient) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Retrieve sequence and VEVENT for each recurrence instance
     *
     * @param \Sabre\VObject\Document|null $eventObject
     * @return array[$eventSequence, $eventVEvent]
     */
    private function getSequencePerVEvent(\Sabre\VObject\Document $eventObject = null) {
        $eventVEvent = [];

        if (!empty($eventObject)) {
            foreach ($eventObject->VEVENT as $vEvent) {
                $recurrenceId = isset($vEvent->{'RECURRENCE-ID'}) ? $this->getDateIdentifier($vEvent->{'RECURRENCE-ID'}) : self::MASTER_EVENT;
                $eventVEvent[$recurrenceId] = $vEvent;
            }
        }

        return $eventVEvent;
    }

    /**
     * Scheduled event can contain multiple VEVENT in case of recurring event
     *
     * If one instance have been modified added or removed,
     * Sabre sends the whole series with in a REQUEST ITip
     *
     * This method checks whether each VEVENT have been modified
     * and sends a message only if the instance has been changed, added or deleted
     *
     * In case of non-recurring event, the only VEVENT will be treated a master instance of a recurring event
     *
     * @param Document $scheduledEvent
     * @param Document $formerEvent
     * @param string $recipient
     * @return array messages to send
     */
    private function computeModifiedEventMessages(Document $scheduledEvent, Document $formerEvent, string $recipient) {
        $modifiedInstances = [];
        $cancelledEvents = [];

        $previousEventVEvents = $this->getSequencePerVEvent($formerEvent);
        $currentEventVEvents = $this->getSequencePerVEvent($scheduledEvent);

        foreach ($currentEventVEvents as $recurrenceId => $sequence) {
            if ($recurrenceId == self::MASTER_EVENT && isset($currentEventVEvents[$recurrenceId]->RRULE)) {
                // TODO Add RRULE checking to avoid processing non-recurring event
                list($cancelledEvents, $cancelledInstancesId) = $this->computeMasterEventExDateMessage($previousEventVEvents, $currentEventVEvents, $scheduledEvent);
            }

            // Create message if instance have been created or modified
            if (!isset($cancelledInstancesId[$recurrenceId]) && (
                    !isset($previousEventVEvents[$recurrenceId]) ||
                    $this->hasInstanceChanged($previousEventVEvents[$recurrenceId], $currentEventVEvents[$recurrenceId])
                )) {
                $currentMessage = clone($scheduledEvent);

                $currentMessage->remove('VEVENT');
                $currentMessage->add($currentEventVEvents[$recurrenceId]);

                $modifiedInstance['message'] = $currentMessage;

                // Check if recipient was attending before
                // If an exception was created, we check is recipient was attending whole series
                $previousVEvent = $previousEventVEvents[$recurrenceId] ?? $previousEventVEvents[self::MASTER_EVENT];
                if (!isset($previousVEvent) || !$this->isAttending($recipient, $previousVEvent)) {
                    $modifiedInstance['newEvent'] = 1;
                }

                $modifiedInstances[] = $modifiedInstance;
            }
        }

        if ($formerEvent) {
            $formerEvent->destroy();
        }

        return array_merge($modifiedInstances, $cancelledEvents);
    }

    /**
     * Retrieve ExDates in hashmap format
     *
     * @param array $exDates
     * @return array
     */
    private function formatExDates($exDates = []) {
        $formattedExDates = [];

        if (!empty($exDates)) {
            foreach ($exDates as $exDate) {
                $formattedExDates[$this->getDateIdentifier($exDate)] = $exDate;
            }
        }

        return $formattedExDates;
    }

    /**
     * Check if recurring event EXDATE have been modified and add CANCEL message if needed
     *
     * @param array $previousEventVEvents
     * @param array $currentEventVEvents
     * @param $message
     * @return array
     */
    private function computeMasterEventExDateMessage(array $previousEventVEvents, array $currentEventVEvents, $message) {
        $cancelledInstances = [];
        $cancelledInstancesId = [];

        if (isset($previousEventVEvents[self::MASTER_EVENT]) && isset($currentEventVEvents[self::MASTER_EVENT])) {
            $previousExDates = isset($previousEventVEvents[self::MASTER_EVENT]->EXDATE) ? $previousEventVEvents[self::MASTER_EVENT]->EXDATE : null;
            $currentExDates = isset($currentEventVEvents[self::MASTER_EVENT]->EXDATE) ? $currentEventVEvents[self::MASTER_EVENT]->EXDATE : null;

            $previousExDatesFormatted = $this->formatExDates($previousExDates);
            $currentExDatesFormatted = $this->formatExDates($currentExDates);

            $newExDates = array_diff(array_keys($currentExDatesFormatted), array_keys($previousExDatesFormatted));

            foreach ($newExDates as $newExDate) {

                if (isset($previousEventVEvents[$newExDate])) {
                    $eventToCancel = clone $previousEventVEvents[$newExDate];
                    $eventToCancel->STATUS = 'CANCELLED';
                } else {
                    $eventToCancel = clone $previousEventVEvents[self::MASTER_EVENT];
                    $eventToCancel->DTSTART = $currentExDatesFormatted[$newExDate]->getDateTime();
                    $eventToCancel->DTEND = clone $currentExDatesFormatted[$newExDate]->getDateTime();
                    $eventToCancel->{'RECURRENCE-ID'} = clone $currentExDatesFormatted[$newExDate]->getDateTime();
                    $eventToCancel->STATUS = 'CANCELLED';
                    $eventToCancel->remove('RRULE');
                    $eventToCancel->remove('EXDATE');
                }

                $currentMessage = clone $message;
                $currentMessage->METHOD = 'CANCEL';
                $currentMessage->remove('VEVENT');
                $currentMessage->add($eventToCancel);

                $cancelledInstancesId[$newExDate] = 1;
                $cancelledInstances[] = ['message' => $currentMessage];
            }
        }
        return [$cancelledInstances, $cancelledInstancesId];
    }

    /**
     * @param $recipientPrincipalUri
     * @param ITip\Message $iTipMessage
     * @param $calendarPath
     * @return string
     */
    private function getEventFullPath($recipientPrincipalUri, ITip\Message $iTipMessage, $calendarPath) {
        list($eventPath,) = Utils::getEventObjectFromAnotherPrincipalHome($recipientPrincipalUri, $iTipMessage->uid, $iTipMessage->method, $this->server);

        // If event doesn't exist in recipient home, we define event path
        if (!$eventPath) {
            $fullEventPath = '/' . $calendarPath . '/' . $iTipMessage->uid . '.ics';
        } else {
            $fullEventPath = '/' . $eventPath;
        }

        return $fullEventPath;
    }

    /**
     * Retrieve TimeZone-safe date identifier
     *
     * @param $date
     * @return mixed
     */
    function getDateIdentifier($date) {
        return $date->getDateTime()->getTimeStamp();
    }

    /**
     * Check if instance has changes that need to be notified to attendee
     *
     * @param $previousEvent
     * @param $currentEvent
     * @return bool
     */
    private function hasInstanceChanged($previousEvent, $currentEvent) {
        $previousEventSequence = isset($previousEvent->SEQUENCE) ? $previousEvent->SEQUENCE->getValue() : 0;
        $currentEventSequence = isset($currentEvent->SEQUENCE) ? $currentEvent->SEQUENCE->getValue() : 0;

        return $previousEventSequence < $currentEventSequence;
    }
}
