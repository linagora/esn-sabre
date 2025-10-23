<?php
namespace ESN\CalDAV\Schedule;

use ESN\Utils\Utils;
use Sabre\CalDAV\ICalendarObject;
use Sabre\CalDAV\Schedule\ISchedulingObject;
use
    Sabre\HTTP\RequestInterface,
    Sabre\HTTP\ResponseInterface,
    Sabre\VObject\Component\VCalendar,
    Sabre\VObject\ITip;

// @codeCoverageIgnoreEnd

/**
 * This is a hack for making email invitations work. SabreDAV doesn't find a
 * valid attendee or organizer because the group calendar doesn't have the
 * right owner. Using the currently authenticated user is not technically
 * correct, because in case of delegated access it will be the wrong user, but
 * for the ESN we assume that the user accessing is also the user being
 * processed.
 *
 * Most of this code is copied from SabreDAV, therefore we opt to not cover it
 * @codeCoverageIgnore
 */
class Plugin extends \Sabre\CalDAV\Schedule\Plugin {

    private function scheduleReply(RequestInterface $request) {

        $scheduleReply = $request->getHeader('Schedule-Reply');
        return $scheduleReply!=='F';

    }

    /**
     * Used to perform healthchecks on the Message before delivery.
     *
     * @param ITip\Message $iTipMessage The Message to deliver.
     */
    function deliver(ITip\Message $iTipMessage) {
        if ($iTipMessage->message->VEVENT->SEQUENCE && !$iTipMessage->message->VEVENT->SEQUENCE->getValue()) {
            $iTipMessage->message->VEVENT->SEQUENCE->setValue(0);
        } else if(!$iTipMessage->message->VEVENT->SEQUENCE) {
            $iTipMessage->message->VEVENT->SEQUENCE =0;
        }

        parent::deliver($iTipMessage);
    }

    /**
     *
     * Override default method because:
     *  * ITIP operations must not be processed
     *  * user addresses must be the calendar owner ones to handle delegation
     *
     */
    function calendarObjectChange(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        // ITIP operations are silent -> no email should be sent
        if ($request->getMethod() === 'ITIP' || !$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        $addresses = $this->fetchCalendarOwnerAddresses($calendarPath);

        if (!$isNew) {
            $node = $this->server->tree->getNodeForPath($request->getPath());
            $oldObj = \Sabre\VObject\Reader::read($node->get());
        } else {
            $oldObj = null;
        }

        $this->processICalendarChange($oldObj, $vCal, $addresses, [], $modified);
    }

    /**
     * Check if a message should be skipped for an unchanged occurrence
     *
     * When modifying one occurrence in a recurring event, SabreDAV's broker creates
     * messages for ALL occurrences, even those that haven't changed. This method
     * filters out messages for unchanged occurrences.
     *
     * @param ITip\Message $message The iTIP message to check
     * @param VCalendar $oldObject The old event
     * @param VCalendar $newObject The new event
     * @return bool True if the message should be skipped
     */
    protected function shouldSkipUnchangedOccurrence(ITip\Message $message, $oldObject, VCalendar $newObject) {
        // Only apply this filter to REQUEST messages for recurring events
        if ($message->method !== 'REQUEST') {
            return false;
        }

        // Parse oldObject if it's a string (raw iCalendar data)
        if (is_string($oldObject)) {
            $oldObject = \Sabre\VObject\Reader::read($oldObject);
        }

        // Ensure oldObject has VEVENT
        if (!isset($oldObject->VEVENT)) {
            return false;
        }

        // Only apply this filter to recurring events (must have RRULE or RECURRENCE-ID)
        $hasRecurrence = false;
        foreach ($oldObject->VEVENT as $vevent) {
            if (isset($vevent->RRULE) || isset($vevent->{'RECURRENCE-ID'})) {
                $hasRecurrence = true;
                break;
            }
        }
        if (!$hasRecurrence) {
            return false;
        }

        // Only filter messages with a single VEVENT (single occurrence)
        // Messages with multiple VEVENTs (bundled occurrences) should not be filtered
        // as they represent legitimate multi-occurrence invitations
        $veventCount = count($message->message->VEVENT);
        if ($veventCount !== 1) {
            return false;
        }

        // Get the VEVENT from the message to identify which occurrence this is about
        $messageEvent = $message->message->VEVENT;
        if (!$messageEvent) {
            return false;
        }

        // Determine the recurrence ID of this message
        $recurrenceId = isset($messageEvent->{'RECURRENCE-ID'})
            ? $messageEvent->{'RECURRENCE-ID'}->getValue()
            : 'master';

        // Find the corresponding VEVENTs in old and new objects
        $oldVEvent = null;
        $newVEvent = null;

        foreach ($oldObject->VEVENT as $vevent) {
            $oldRecurId = isset($vevent->{'RECURRENCE-ID'})
                ? $vevent->{'RECURRENCE-ID'}->getValue()
                : 'master';
            if ($oldRecurId === $recurrenceId) {
                $oldVEvent = $vevent;
                break;
            }
        }

        foreach ($newObject->VEVENT as $vevent) {
            $newRecurId = isset($vevent->{'RECURRENCE-ID'})
                ? $vevent->{'RECURRENCE-ID'}->getValue()
                : 'master';
            if ($newRecurId === $recurrenceId) {
                $newVEvent = $vevent;
                break;
            }
        }

        // If this is a new occurrence (wasn't in oldObject), don't skip
        if (!$oldVEvent || !$newVEvent) {
            return false;
        }

        // Check if recipient was attending this occurrence before
        $wasAttendingBefore = false;
        if (isset($oldVEvent->ATTENDEE)) {
            foreach ($oldVEvent->ATTENDEE as $attendee) {
                if ($attendee->getNormalizedValue() === $message->recipient) {
                    $wasAttendingBefore = true;
                    break;
                }
            }
        }

        // Check if recipient is attending this occurrence now
        $isAttendingNow = false;
        if (isset($newVEvent->ATTENDEE)) {
            foreach ($newVEvent->ATTENDEE as $attendee) {
                if ($attendee->getNormalizedValue() === $message->recipient) {
                    $isAttendingNow = true;
                    break;
                }
            }
        }

        // If recipient wasn't and isn't attending, skip (already handled by broker)
        // If recipient was attending but isn't now, don't skip (it's a removal)
        // If recipient wasn't attending but is now, don't skip (it's an addition)
        // Only skip if recipient was AND is still attending
        if (!$wasAttendingBefore || !$isAttendingNow) {
            return false;
        }

        // Check if the occurrence has actually changed
        // Compare SEQUENCE, DTSTART, DTEND, SUMMARY, LOCATION, DESCRIPTION, etc.
        $oldSequence = isset($oldVEvent->SEQUENCE) ? $oldVEvent->SEQUENCE->getValue() : 0;
        $newSequence = isset($newVEvent->SEQUENCE) ? $newVEvent->SEQUENCE->getValue() : 0;

        if ($oldSequence != $newSequence) {
            return false; // Sequence changed, don't skip
        }

        // Compare key properties (including EXDATE for occurrence exclusion detection)
        $properties = ['DTSTART', 'DTEND', 'SUMMARY', 'LOCATION', 'DESCRIPTION', 'STATUS', 'EXDATE'];
        foreach ($properties as $prop) {
            $oldValue = isset($oldVEvent->$prop) ? (string)$oldVEvent->$prop : '';
            $newValue = isset($newVEvent->$prop) ? (string)$newVEvent->$prop : '';
            if ($oldValue !== $newValue) {
                return false; // Property changed, don't skip
            }
        }

        // Occurrence hasn't changed significantly, skip the message
        return true;
    }

    protected function processICalendarChange($oldObject = null, VCalendar $newObject, array $addresses, array $ignore = [], &$modified = false) {
        $broker = new ITip\Broker();
        $messages = $broker->parseEvent($newObject, $addresses, $oldObject);

        if ($messages) $modified = true;

        foreach ($messages as $message) {
            if (in_array($message->recipient, $ignore)) {
                continue;
            }

            // Fix for issue #152: Skip delivery for unchanged occurrences
            // When modifying one occurrence (e.g. creating exception #3), SabreDAV re-processes
            // all occurrences including unchanged ones (e.g. exception #2). We need to skip
            // delivering messages for occurrences that haven't actually changed.
            if ($oldObject && $this->shouldSkipUnchangedOccurrence($message, $oldObject, $newObject)) {
                continue;
            }

            $this->deliver($message);

            // Update schedule status for organizer or attendee
            if (isset($newObject->VEVENT->ORGANIZER) && ($newObject->VEVENT->ORGANIZER->getNormalizedValue() === $message->recipient)) {
                if ($message->scheduleStatus) {
                    $newObject->VEVENT->ORGANIZER['SCHEDULE-STATUS'] = $message->getScheduleStatus();
                }
                unset($newObject->VEVENT->ORGANIZER['SCHEDULE-FORCE-SEND']);
            } else {
                if (isset($newObject->VEVENT->ATTENDEE)) {
                    foreach ($newObject->VEVENT->ATTENDEE as $attendee) {
                        if ($attendee->getNormalizedValue() === $message->recipient) {
                            if ($message->scheduleStatus) {
                                $attendee['SCHEDULE-STATUS'] = $message->getScheduleStatus();
                            }
                            unset($attendee['SCHEDULE-FORCE-SEND']);
                            break;
                        }
                    }
                }
            }
        }
    }

    /**
     *
     * Override default method because:
     *  * user addresses must be the calendar owner ones to handle delegation
     *
     */
    function beforeUnbind($path) {

        // FIXME: We shouldn't trigger this functionality when we're issuing a
        // MOVE. This is a hack.
        if ($this->server->httpRequest->getMethod() === 'MOVE') return;

        $node = $this->server->tree->getNodeForPath($path);

        if (!$node instanceof ICalendarObject || $node instanceof ISchedulingObject) {
            return;
        }

        if (!$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        list($calendarPath,) = Utils::splitEventPath('/'.$path);

        if (!$calendarPath) {
            return;
        }

        $addresses = $this->fetchCalendarOwnerAddresses($calendarPath);

        if (empty($addresses)) {
            return;
        }

        $broker = new ITip\Broker();
        $messages = $broker->parseEvent(null, $addresses, $node->get());

        foreach ($messages as $message) {
            $this->deliver($message);
        }
    }

    /**
     * Fetches calendar owner email addresses
     *
     * @param $calendarPath
     * @return array
     * @throws \Sabre\DAV\Exception\NotFound
     */
    private function fetchCalendarOwnerAddresses($calendarPath): array {
        $calendarNode = $this->server->tree->getNodeForPath($calendarPath);

        if ($calendarNode === null || !method_exists($calendarNode, 'getOwner')) {
            return [];
        }

        return $this->getAddressesForPrincipal($calendarNode->getOwner());
    }
}
