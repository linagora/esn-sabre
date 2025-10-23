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
    protected function shouldSkipUnchangedOccurrence(ITip\Message $message, VCalendar $oldObject, VCalendar $newObject) {
        // Only apply this filter to REQUEST messages for recurring events
        if ($message->method !== 'REQUEST') {
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

        // Compare key properties
        $properties = ['DTSTART', 'DTEND', 'SUMMARY', 'LOCATION', 'DESCRIPTION', 'STATUS'];
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

    /**
     * Override to filter IMIP messages for partstat-only changes.
     *
     * Performance optimization for issue #128:
     * When an attendee changes their PARTSTAT (accepts/declines), SabreDAV generates
     * IMIP messages to notify all other attendees. However, attendees don't need to
     * be notified when another attendee's participation status changes - only the
     * organizer needs this information.
     *
     * This override filters out IMIP messages that:
     * 1. Have no significant changes (empty $changes)
     * 2. Are sent to attendees about another attendee's participation change
     *
     * As suggested by chibenwa in PR #142 review.
     */
    protected function processICalendarChange($oldObject = null, VCalendar $newObject, array $addresses, array $ignore = [], &$modified = false) {
        $broker = new ITip\Broker();
        $messages = $broker->parseEvent($newObject, $addresses, $oldObject);

        if ($messages) $modified = true;

        foreach ($messages as $message) {
            if (in_array($message->recipient, $ignore)) {
                continue;
            }

            // Skip delivery if there are no significant changes
            // This happens when only PARTSTAT changes for attendees
            if ($this->hasNoSignificantChanges($message, $oldObject, $newObject)) {
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
     * Determines if an IMIP message should be skipped due to lack of significant changes.
     *
     * @param ITip\Message $message The IMIP message to evaluate
     * @param VCalendar|null $oldObject The original calendar object
     * @param VCalendar $newObject The updated calendar object
     * @return bool True if message should be skipped, false if it should be delivered
     */
    private function hasNoSignificantChanges(ITip\Message $message, $oldObject, VCalendar $newObject): bool {
        // For new events, always send notifications
        if ($oldObject === null) {
            return false;
        }

        // Parse oldObject if it's a string (raw iCalendar data)
        if (is_string($oldObject)) {
            $oldObject = \Sabre\VObject\Reader::read($oldObject);
        }

        // Ensure oldObject has VEVENT
        if (!isset($oldObject->VEVENT) || !isset($newObject->VEVENT)) {
            return false;
        }

        // Fix for issue #154: For recurring events with occurrence exceptions,
        // check if the number of occurrences changed (exception added/removed)
        $oldEventCount = count($oldObject->VEVENT);
        $newEventCount = count($newObject->VEVENT);
        if ($oldEventCount !== $newEventCount) {
            return false; // New occurrence exception added, always send notification
        }

        // Check if recipient is a resource - resources need all notifications
        $recipientPrincipalUri = \ESN\Utils\Utils::getPrincipalByUri($message->recipient, $this->server);
        if ($recipientPrincipalUri && \ESN\Utils\Utils::isResourceFromPrincipal($recipientPrincipalUri)) {
            return false; // Never skip for resources
        }

        // Get the organizer email
        $organizerEmail = null;
        if (isset($newObject->VEVENT->ORGANIZER)) {
            $organizerEmail = $newObject->VEVENT->ORGANIZER->getNormalizedValue();
        }

        // Check if recipient is the organizer
        $isOrganizer = ($organizerEmail === $message->recipient);

        // Get significant property changes between old and new
        $hasSignificantChanges = $this->hasSignificantPropertyChanges($oldObject->VEVENT, $newObject->VEVENT);

        // Rule 1: If no significant changes, skip for everyone except organizer
        // (Organizer should still receive PARTSTAT updates from attendees)
        if (!$hasSignificantChanges && !$isOrganizer) {
            return true; // Skip: attendee receiving notification about another attendee's PARTSTAT
        }

        // Rule 2: If there are significant changes, send to everyone
        if ($hasSignificantChanges) {
            return false; // Don't skip: significant changes need to be communicated
        }

        // Organizer receives all changes (including PARTSTAT)
        return false;
    }

    /**
     * Checks if there are significant property changes between old and new event.
     *
     * Significant changes are those that affect event details (time, location, etc.),
     * not just participation status.
     *
     * @param \Sabre\VObject\Component $oldEvent
     * @param \Sabre\VObject\Component $newEvent
     * @return bool True if there are significant changes
     */
    private function hasSignificantPropertyChanges(\Sabre\VObject\Component $oldEvent, \Sabre\VObject\Component $newEvent): bool {
        $significantProperties = [
            'DTSTART', 'DTEND', 'DURATION', 'SUMMARY', 'DESCRIPTION',
            'LOCATION', 'RRULE', 'EXDATE', 'RDATE', 'SEQUENCE'
        ];

        foreach ($significantProperties as $prop) {
            $oldValue = isset($oldEvent->$prop) ? (string)$oldEvent->$prop : null;
            $newValue = isset($newEvent->$prop) ? (string)$newEvent->$prop : null;

            if ($oldValue !== $newValue) {
                return true; // Found a significant change
            }
        }

        // Check if attendee list changed (added or removed)
        $oldAttendees = [];
        $newAttendees = [];

        if (isset($oldEvent->ATTENDEE)) {
            foreach ($oldEvent->ATTENDEE as $attendee) {
                $oldAttendees[] = $attendee->getNormalizedValue();
            }
        }

        if (isset($newEvent->ATTENDEE)) {
            foreach ($newEvent->ATTENDEE as $attendee) {
                $newAttendees[] = $attendee->getNormalizedValue();
            }
        }

        sort($oldAttendees);
        sort($newAttendees);

        if ($oldAttendees !== $newAttendees) {
            return true; // Attendee list changed
        }

        return false; // No significant changes found
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
