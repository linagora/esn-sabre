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

    /**
     * Override to optimize cascading notifications for events with many attendees.
     *
     * Performance optimization for issue #128:
     * When an attendee accepts a recurring event with many attendees, SabreDAV's default
     * behavior generates and delivers REQUEST messages to all other attendees sequentially.
     * For an event with 100 attendees, this means 99 sequential local deliveries, each
     * performing multiple database queries and updates, causing timeout (504) errors.
     *
     * This override limits cascading notifications to a maximum number of attendees.
     * Attendees beyond this limit won't receive immediate notifications of status changes,
     * but they will still see updates when they next sync their calendars from the server.
     */
    protected function processICalendarChange($oldObject = null, VCalendar $newObject, array $addresses, array $ignore = [], &$modified = false) {
        $broker = new ITip\Broker();
        $messages = $broker->parseEvent($newObject, $addresses, $oldObject);

        if ($messages) $modified = true;

        // Limit the number of messages we deliver synchronously to prevent timeout
        // For events with many attendees, we'll skip notifications to some attendees
        $maxSyncDeliveries = 20;  // Deliver to max 20 attendees synchronously
        $deliveryCount = 0;

        foreach ($messages as $message) {
            if (in_array($message->recipient, $ignore)) {
                continue;
            }

            // Skip delivery if we've reached the limit
            if ($deliveryCount >= $maxSyncDeliveries) {
                $this->server->getLogger()->warning(
                    "Skipping delivery to " . $message->recipient .
                    " - exceeded max sync deliveries limit of " . $maxSyncDeliveries
                );
                continue;
            }

            $this->deliver($message);
            $deliveryCount++;

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

    /**
     * Determines if an IMIP message should be skipped due to insignificant changes.
     *
     * This implements the filtering logic suggested by chibenwa for issue #128:
     * 1. If there are no significant changes ($changes is empty) → skip
     * 2. If the recipient is an attendee and the change concerns another attendee's
     *    participation status → skip (only organizer needs this info)
     * 3. If the recipient is a resource → never skip (resources need all notifications)
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
}
