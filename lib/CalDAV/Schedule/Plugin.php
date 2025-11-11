<?php
namespace ESN\CalDAV\Schedule;

use ESN\Utils\Utils;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
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
    const ITIP_DELIVERY_TOPIC = 'calendar:itip:deliver';
    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    private $logger;
    private $principalBackend;
    protected $amqpPublisher;
    protected $scheduleAsync;
    protected $server;

    public function __construct($principalBackend = null, $amqpPublisher = null, $scheduleAsync = false) {
        $this->logger = new Logger('esn-sabre');
        $this->logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));
        $this->principalBackend = $principalBackend;
        $this->amqpPublisher = $amqpPublisher;
        $this->scheduleAsync = $scheduleAsync;
    }

    public function initialize(\Sabre\DAV\Server $server) {
        parent::initialize($server);
        $this->server = $server;
    }

    /**
     * Set the AMQP publisher after initialization.
     * This is needed when Schedule plugin is registered before AMQP is initialized.
     *
     * @param $amqpPublisher
     */
    public function setAmqpPublisher($amqpPublisher) {
        $this->amqpPublisher = $amqpPublisher;
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

        if ($this->scheduleAsync && $this->amqpPublisher) {
            // Serialize the ITip message and publish to AMQP for asynchronous processing
            $message = [
                'sender' => $iTipMessage->sender,
                'recipient' => $iTipMessage->recipient,
                'message' => $iTipMessage->message->serialize(),
                'method' => $iTipMessage->method,
                'significantChange' => $iTipMessage->significantChange ?? false,
                'hasChange' => $iTipMessage->hasChange ?? false,
                'uid' => $iTipMessage->uid,
                'component' => $iTipMessage->component
            ];
            $messageBody = json_encode($message);

            $authPlugin = $this->server->getPlugin('auth');
            if (!$authPlugin) {
                $this->logger->warning('Schedule async fallback: auth plugin missing');
                parent::deliver($iTipMessage);
                return;
            }

            $currentPrincipal = $authPlugin->getCurrentPrincipal();
            if (!$currentPrincipal) {
                parent::deliver($iTipMessage);
                return;
            }

            $addresses = $this->getAddressesForPrincipal($currentPrincipal);
            if (empty($addresses)) {
                // Fallback to synchronous delivery if we can't determine connectedUser
                parent::deliver($iTipMessage);
                return;
            }
            $connectedUser = preg_replace('/^mailto:/i', '', $addresses[0]);
            $requestURI = $this->server->httpRequest->getPath();
            $properties = [
                'application_headers' => new \PhpAmqpLib\Wire\AMQPTable([
                    'connectedUser' => $connectedUser,
                    'requestURI' => $requestURI
                ])
            ];

            $this->amqpPublisher->publishWithProperties(self::ITIP_DELIVERY_TOPIC, $messageBody, $properties);

            // Mark as pending since we're processing asynchronously
            $iTipMessage->scheduleStatus = '1.0'; // SCHEDSTAT_SUCCESS_PENDING
        } else {
            parent::deliver($iTipMessage);
        }
    }

    /**
     * Returns a list of addresses that are associated with a principal.
     *
     * @param string $principal
     * @return array
     */
    function getAddressesForPrincipal($principal) {
        $CUAS = '{' . self::NS_CALDAV . '}calendar-user-address-set';

        $properties = $this->server->getProperties(
            $principal,
            [$CUAS]
        );

        // If we can't find this information, we'll stop processing
        if (!isset($properties[$CUAS])) {
            return [];
        }

        $addresses = $properties[$CUAS]->getHrefs();

        return $addresses;
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

        // Check if the number of occurrences (exceptions) the recipient is invited to has changed
        // Only count exceptions where the recipient is an attendee
        $oldExceptionCount = 0;
        $newExceptionCount = 0;
        foreach ($oldObject->VEVENT as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'})) {
                // Check if recipient is attending this occurrence
                if (isset($vevent->ATTENDEE)) {
                    foreach ($vevent->ATTENDEE as $attendee) {
                        if ($attendee->getNormalizedValue() === $message->recipient) {
                            $oldExceptionCount++;
                            break;
                        }
                    }
                }
            }
        }
        foreach ($newObject->VEVENT as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'})) {
                // Check if recipient is attending this occurrence
                if (isset($vevent->ATTENDEE)) {
                    foreach ($vevent->ATTENDEE as $attendee) {
                        if ($attendee->getNormalizedValue() === $message->recipient) {
                            $newExceptionCount++;
                            break;
                        }
                    }
                }
            }
        }
        if ($oldExceptionCount !== $newExceptionCount) {
            return false; // Number of exceptions recipient is invited to changed, don't skip
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

    /*
     * Override to filter IMIP messages for partstat-only changes.
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

        $owner = $calendarNode->getOwner();
        if ($owner === null) {
            return [];
        }

        // For resource principals, get email address directly from principal properties
        // to avoid issues with getAddressesForPrincipal() failing (issue #195)
        if (strpos($owner, 'principals/resources/') === 0 && $this->principalBackend) {
            if (method_exists($this->principalBackend, 'getPrincipalByPath')) {
                try {
                    $principalInfo = $this->principalBackend->getPrincipalByPath($owner);
                    if ($principalInfo && isset($principalInfo['{http://sabredav.org/ns}email-address'])) {
                        $email = $principalInfo['{http://sabredav.org/ns}email-address'];
                        if (is_string($email) && !empty($email)) {
                            return ['mailto:' . $email];
                        }
                    }
                } catch (\Throwable $e) {
                    // Log but continue to try standard method
                    $this->logger->debug('Failed to get resource email from principal backend', [
                        'principal' => $owner,
                        'exception' => get_class($e) . ': ' . $e->getMessage()
                    ]);
                }
            }
        }

        try {
            $addresses = $this->getAddressesForPrincipal($owner);
            // getAddressesForPrincipal may return null, ensure we return an array
            return $addresses ?: [];
        } catch (\Sabre\DAV\Exception $e) {
            // If we can't get addresses for the principal (e.g. NotFound), log and return empty array
            $this->logger->warning('Failure getting address for principal (DAV Exception)', [
                'principal' => $owner,
                'exception' => get_class($e) . ': ' . $e->getMessage()
            ]);
            return [];
        } catch (\Throwable $e) {
            // Catch any other errors (TypeError, etc.) that might occur
            // This can happen with resources or malformed data
            $this->logger->warning('Failure getting address for principal (Throwable)', [
                'principal' => $owner,
                'exception' => get_class($e) . ': ' . $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Deliver iTIP message synchronously by calling parent implementation.
     * This is used by the IMIP callback endpoint to process async messages.
     *
     * @param ITip\Message $iTipMessage
     */
    public function deliverSync(ITip\Message $iTipMessage) {
        parent::deliver($iTipMessage);
    }
}
