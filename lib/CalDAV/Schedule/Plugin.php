<?php
namespace ESN\CalDAV\Schedule;

use ESN\CalDAV\Schedule\Exception\ForbiddenAttendeeSchedulingObjectChange;
use ESN\CalDAV\VObjectPropertyRegistry;
use ESN\Utils\Utils;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Sabre\CalDAV\ICalendarObject;
use Sabre\CalDAV\Schedule\ISchedulingObject;
use Sabre\DAV\Server;
use
    Sabre\HTTP\RequestInterface,
    Sabre\HTTP\ResponseInterface,
    Sabre\VObject\Component\VCalendar,
    Sabre\VObject\ITip;
use Sabre\VObject\Reader;

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
#[\AllowDynamicProperties]
class Plugin extends \Sabre\CalDAV\Schedule\Plugin {
    private const DEFAULT_REPLY_PROPAGATION_THRESHOLD = 200;
    private const PRESERVABLE_RECIPIENT_LOCAL_PROPERTIES = ['VALARM', 'TRANSP', 'CLASS'];
    private const PRESERVABLE_RECIPIENT_LOCAL_PROPERTIES_WITH_MANAGED_ALARMS = ['TRANSP', 'CLASS'];
    private const FORBIDDEN_ATTENDEE_CHANGE_PROPERTIES = ['DTSTART', 'DTEND', 'LOCATION', 'SUMMARY', 'ORGANIZER'];
    private const PUBLIC_AGENDA_METADATA_PROPERTIES = ['X-PUBLICLY-CREATED', 'X-PUBLICLY-CREATOR', 'X-OPENPAAS-BOOKING-LINK'];
    private const ENFORCE_RFC_6638_ENV = 'SABRE_ENFORCE_RFC_6638';
    private const EMAIL_VALARM_RECIPIENT_SCHEDULING_ENV = 'SABRE_EMAIL_VALARM_RECIPIENT_SCHEDULING';

    private $logger;
    private $principalBackend;

    public function __construct($principalBackend = null) {
        $this->logger = new Logger('esn-sabre');
        $this->logger->pushHandler(new StreamHandler('php://stderr', Logger::DEBUG));
        $this->principalBackend = $principalBackend;
    }

    function initialize(Server $server) {
        VObjectPropertyRegistry::register();
        parent::initialize($server);
    }

    protected function scheduleReply(RequestInterface $request) {

        if ($request->getMethod() === 'PUT' && array_key_exists('import', $request->getQueryParameters())) {
            return false;
        }

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

        if (!is_string($iTipMessage->recipient)) {
            $iTipMessage->recipient = '';
        }

        parent::deliver($iTipMessage);
    }

    /**
     * Override scheduleLocalDelivery to skip inbox creation for non-significant
     * REQUEST messages (e.g. pure PARTSTAT propagation after an attendee replies).
     *
     * When an attendee updates their PARTSTAT, Sabre fans out REQUEST messages to
     * all other attendees so they see the updated status. These messages have
     * hasChange=false (PARTSTAT is not in significantChangeProperties/changeProperties).
     * Creating an inbox item for each attendee is unnecessary in this case and causes
     * O(n) inbox writes. The calendar object is still updated so the PARTSTAT change
     * is visible to all attendees.
     */
    function scheduleLocalDelivery(ITip\Message $iTipMessage) {
        $aclPlugin = $this->server->getPlugin('acl');

        if (!$aclPlugin) {
            return;
        }

        $deliveryPaths = $this->resolveRecipientDeliveryPaths($aclPlugin, $iTipMessage);
        if (!$deliveryPaths) {
            return;
        }
        list($homePath, $inboxPath, $calendarPath) = $deliveryPaths;

        if (!$this->hasDeliveryPrivilege($aclPlugin, $inboxPath, $iTipMessage)) {
            return;
        }

        $newFileName = 'sabredav-' . \Sabre\DAV\UUIDUtil::getUUID() . '.ics';

        list($objectNode, $oldICalendarData, $currentObject) = $this->loadExistingCalendarObject($homePath, $iTipMessage->uid);

        if ($currentObject) {
            $this->normalizeIncomingReplyMessage($iTipMessage, $currentObject);
        }

        $broker = new ITip\Broker();
        $newObject = $broker->processMessage($iTipMessage, $currentObject);

        $this->createInboxItemIfSignificant($inboxPath, $newFileName, $iTipMessage);

        if (!$newObject) {
            $iTipMessage->scheduleStatus = '5.0;iTip message was not processed by the server, likely because we didn\'t understand it.';
            return;
        }

        if (!$objectNode) {
            $this->deliverToNewObject($iTipMessage, $calendarPath, $newFileName, $newObject);
        } else {
            $this->deliverToExistingObject($iTipMessage, $objectNode, $oldICalendarData, $newObject);
        }
        $iTipMessage->scheduleStatus = '1.2;Message delivered locally';
    }

    /**
     * Loads the recipient's existing calendar object matching the iTIP UID.
     *
     * @return array [$objectNode, $oldICalendarData, $currentObject], all null
     *               when the recipient has no copy of the event yet.
     */
    private function loadExistingCalendarObject(string $homePath, string $uid): array {
        $home = $this->server->tree->getNodeForPath($homePath);

        $result = $home->getCalendarObjectByUID($uid);
        if (!$result) {
            return [null, null, null];
        }

        $objectNode = $this->server->tree->getNodeForPath($homePath . '/' . $result);
        $oldICalendarData = $objectNode->get();

        return [$objectNode, $oldICalendarData, Reader::read($oldICalendarData)];
    }

    private function normalizeIncomingReplyMessage(ITip\Message $iTipMessage, VCalendar $currentObject): void {
        if ($iTipMessage->method === 'REPLY') {
            $this->normalizeReplyRecurrenceId($iTipMessage, $currentObject);
        }
    }

    /**
     * Skip inbox creation for non-significant REQUEST messages (pure PARTSTAT
     * propagation). The calendar object is still updated by the caller so
     * attendees see the PARTSTAT change without polluting everyone's inbox.
     */
    private function createInboxItemIfSignificant(string $inboxPath, string $newFileName, ITip\Message $iTipMessage): void {
        if ($iTipMessage->method === 'REQUEST' && !$iTipMessage->hasChange) {
            return;
        }

        $inbox = $this->server->tree->getNodeForPath($inboxPath);
        $inbox->createFile($newFileName, $iTipMessage->message->serialize());
    }

    /**
     * Resolves the recipient's calendar-home, inbox and default calendar paths,
     * setting the iTIP schedule status and returning null when any of them
     * cannot be found.
     */
    private function resolveRecipientDeliveryPaths($aclPlugin, ITip\Message $iTipMessage): ?array {
        $caldavNS = '{' . self::NS_CALDAV . '}';

        $principalUri = $aclPlugin->getPrincipalByUri($iTipMessage->recipient);
        if (!$principalUri) {
            $iTipMessage->scheduleStatus = '3.7;Could not find principal.';
            return null;
        }

        $this->server->removeListener('propFind', [$aclPlugin, 'propFind']);

        $result = $this->server->getProperties(
            $principalUri,
            [
                '{DAV:}principal-URL',
                 $caldavNS . 'calendar-home-set',
                 $caldavNS . 'schedule-inbox-URL',
                 $caldavNS . 'schedule-default-calendar-URL',
                '{http://sabredav.org/ns}email-address',
            ]
        );

        $this->server->on('propFind', [$aclPlugin, 'propFind'], 20);

        $requiredProperties = [
            $caldavNS . 'schedule-inbox-URL' => '5.2;Could not find local inbox',
            $caldavNS . 'calendar-home-set' => '5.2;Could not locate a calendar-home-set',
            $caldavNS . 'schedule-default-calendar-URL' => '5.2;Could not find a schedule-default-calendar-URL property',
        ];
        foreach ($requiredProperties as $property => $failureStatus) {
            if (!isset($result[$property])) {
                $iTipMessage->scheduleStatus = $failureStatus;
                return null;
            }
        }

        return [
            $result[$caldavNS . 'calendar-home-set']->getHref(),
            $result[$caldavNS . 'schedule-inbox-URL']->getHref(),
            $result[$caldavNS . 'schedule-default-calendar-URL']->getHref(),
        ];
    }

    private function hasDeliveryPrivilege($aclPlugin, string $inboxPath, ITip\Message $iTipMessage): bool {
        $privilege = $iTipMessage->method === 'REPLY' ? 'schedule-deliver-reply' : 'schedule-deliver-invite';

        // On the ITIP path (POST /itip) the Twake Side Service is an internal trusted caller
        // and recipientMatchesCurrentUser() in ITipPlugin already validated the recipient.
        // Skip the inbox ACL privilege check:
        //   • The Side Service may authenticate via a token that does not map to a Sabre
        //     principal, so getCurrentUserPrincipal() returns null.
        //   • DAVACL\Plugin::checkPrivileges() ignores $throwExceptions=false when the user is
        //     unauthenticated and allowUnauthenticatedAccess=true — it always throws
        //     NotAuthenticated (401) in that branch (Plugin.php:208).
        //   • This surfaces as a hard 401 for resource/room recipients whose inbox ACL does not
        //     explicitly grant schedule-deliver-* to {DAV:}unauthenticated.
        $req = $this->server->httpRequest;
        $isItipPath = $req->getMethod() === 'ITIP' || $req->getPath() === 'itip';
        if ($isItipPath) {
            return true;
        }

        $caldavNS = '{' . self::NS_CALDAV . '}';
        if (!$aclPlugin->checkPrivileges($inboxPath, $caldavNS . $privilege, \Sabre\DAVACL\Plugin::R_PARENT, false)) {
            $iTipMessage->scheduleStatus = '3.8;insufficient privileges: ' . $privilege . ' is required on the recipient schedule inbox.';
            return false;
        }

        return true;
    }

    private function deliverToNewObject(ITip\Message $iTipMessage, string $calendarPath, string $newFileName, VCalendar $newObject): void {
        // Do not re-create the event when the attendee already declined (issue-347).
        // An attendee who deleted their copy sends REPLY DECLINED; the organizer's
        // copy then reflects PARTSTAT=DECLINED.  Subsequent REQUEST messages (e.g.
        // reschedule, new attendee added, partstat propagation) must not resurrect
        // the event in the attendee's calendar.
        if ($iTipMessage->method === 'REQUEST' && $this->recipientHasDeclinedInMessage($iTipMessage)) {
            return;
        }
        $calendar = $this->server->tree->getNodeForPath($calendarPath);
        $calendar->createFile($newFileName, $newObject->serialize());
    }

    private function deliverToExistingObject(ITip\Message $iTipMessage, $objectNode, $oldICalendarData, VCalendar $newObject): void {
        if ($iTipMessage->method === 'REPLY' && !$this->shouldSkipReplyPropagation($oldICalendarData)) {
            $this->processICalendarChange(
                $oldICalendarData,
                $newObject,
                [$iTipMessage->recipient],
                [$iTipMessage->sender]
            );
        }
        if ($iTipMessage->method === 'REQUEST') {
            $this->preserveRecipientLocalProperties($oldICalendarData, $newObject);
        }
        $objectNode->put($newObject->serialize());
    }

    /**
     * Returns true when the iTIP message recipient already appears as DECLINED
     * in the message's master VEVENT (no RECURRENCE-ID), indicating that they
     * previously declined the event and the event must not be re-created.
     */
    private function recipientHasDeclinedInMessage(ITip\Message $iTipMessage): bool {
        foreach ($iTipMessage->message->VEVENT as $vevent) {
            if (isset($vevent->{'RECURRENCE-ID'})) {
                continue; // skip overrides; check master only
            }
            $partstat = CalendarObjectHelper::attendeePartStat($vevent, $iTipMessage->recipient);
            if ($partstat !== null) {
                return $partstat === 'DECLINED';
            }
        }

        return false;
    }

    private function preserveRecipientLocalProperties(?string $oldICalendarData, VCalendar $newObject): void {
        $oldObject = CalendarObjectHelper::readCalendarObject($oldICalendarData);
        if (!$oldObject) {
            return;
        }

        $oldEvents = CalendarObjectHelper::indexEventsByRecurrenceKey($oldObject);

        foreach ($newObject->select('VEVENT') as $newEvent) {
            $oldEvent = $oldEvents[CalendarObjectHelper::recurrenceKey($newEvent)] ?? null;
            if (!$oldEvent) {
                continue;
            }

            if ($this->shouldEnableEmailValarmRecipientScheduling()) {
                $this->preserveRecipientLocalVALARMs($oldEvent, $newEvent);
                $preservableProperties = self::PRESERVABLE_RECIPIENT_LOCAL_PROPERTIES_WITH_MANAGED_ALARMS;
            } else {
                $preservableProperties = self::PRESERVABLE_RECIPIENT_LOCAL_PROPERTIES;
            }

            foreach ($preservableProperties as $property) {
                $newEvent->remove($property);

                foreach ($oldEvent->select($property) as $oldProperty) {
                    $newEvent->add(clone $oldProperty);
                }
            }
        }

        $oldObject->destroy();
    }

    // Email VALARM recipient scheduling
    protected function shouldEnableEmailValarmRecipientScheduling(): bool {
        return $this->envBoolean(self::EMAIL_VALARM_RECIPIENT_SCHEDULING_ENV, true);
    }

    protected function ensureValarmUids(VCalendar $calendarObject): bool {
        $modified = false;

        foreach ($calendarObject->select('VEVENT') as $event) {
            foreach ($event->select('VALARM') as $alarm) {
                if (!isset($alarm->UID) || trim($alarm->UID->getValue()) === '') {
                    $alarm->UID = 'alarm-' . \Sabre\DAV\UUIDUtil::getUUID();
                    $modified = true;
                }
            }
        }

        return $modified;
    }

    private function preserveRecipientLocalVALARMs($oldEvent, $newEvent): void {
        $newEmailAlarms = array_map(fn ($alarm) => clone $alarm, array_filter($newEvent->select('VALARM'), [$this, 'isEmailAlarm']));

        $newEvent->remove('VALARM');
        foreach ($newEmailAlarms as $alarm) {
            $newEvent->add($alarm);
        }

        $newEmailAlarmUids = array_filter(array_map(fn ($alarm) => isset($alarm->UID) ? $alarm->UID->getValue() : null, $newEmailAlarms));
        $eventAttendees = array_map(fn ($attendee) => strtolower($attendee->getNormalizedValue()), $newEvent->select('ATTENDEE'));

        foreach ($oldEvent->select('VALARM') as $oldAlarm) {
            if ($this->isEmailAlarm($oldAlarm) && $this->isOrganizerManagedEmailAlarm($oldAlarm, $newEmailAlarmUids, $eventAttendees)) {
                continue;
            }

            $newEvent->add(clone $oldAlarm);
        }
    }

    private function isEmailAlarm($alarm): bool {
        return isset($alarm->ACTION) && strcasecmp($alarm->ACTION->getValue(), 'EMAIL') === 0;
    }

    private function isOrganizerManagedEmailAlarm($alarm, array $newEmailAlarmUids, array $eventAttendees): bool {
        if (isset($alarm->UID) && in_array($alarm->UID->getValue(), $newEmailAlarmUids, true)) {
            return true;
        }

        $alarmAttendees = array_map(fn ($attendee) => strtolower($attendee->getNormalizedValue()), $alarm->select('ATTENDEE'));

        return !empty($alarmAttendees) && empty(array_diff($alarmAttendees, $eventAttendees));
    }

    /**
     * EMAIL alarms are recipient-specific: only deliver alarms that explicitly
     * name the iTIP recipient in their own ATTENDEE properties.
     */
    private function filterEmailAlarmsForRecipient(ITip\Message $message, VCalendar $sourceCalendar): void {
        $sourceEvents = CalendarObjectHelper::indexEventsByRecurrenceKey($sourceCalendar);

        foreach ($message->message->select('VEVENT') as $vevent) {
            $sourceEvent = $sourceEvents[CalendarObjectHelper::recurrenceKey($vevent)] ?? null;

            // The broker may rewrite VALARM attendees to the message recipient,
            // so rebuild EMAIL alarms from the organizer's original calendar.
            foreach (array_filter($vevent->select('VALARM'), [$this, 'isEmailAlarm']) as $alarm) {
                $vevent->remove($alarm);
            }

            if (!$sourceEvent) {
                continue;
            }

            foreach (array_filter($sourceEvent->select('VALARM'), [$this, 'isEmailAlarm']) as $alarm) {
                $recipientAlarm = clone $alarm;
                // Organizer aliases must not leak into an attendee's copied event.
                foreach ($recipientAlarm->select('ATTENDEE') as $attendee) {
                    if (strcasecmp($attendee->getNormalizedValue(), $message->recipient) !== 0) {
                        $recipientAlarm->remove($attendee);
                    }
                }
                if ($recipientAlarm->select('ATTENDEE')) {
                    $vevent->add($recipientAlarm);
                }
            }
        }
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

        if (PublicAgendaScheduleUtils::isPubliclyCreatedAndChairOrganizerNotAccepted($vCal)) {
            return;
        }

        if ($this->shouldEnableEmailValarmRecipientScheduling() && $this->ensureValarmUids($vCal)) {
            $modified = true;
        }

        $addresses = $this->fetchCalendarOwnerAddresses($calendarPath);

        if (!$isNew) {
            $node = $this->server->tree->getNodeForPath($request->getPath());
            $oldObj = Reader::read($node->get());
        } else {
            $oldObj = null;
        }

        if ($oldObj) {
            // RFC 6638 permits attendee-local updates, but not organizer-controlled event fields.
            $this->assertAllowedAttendeeSchedulingObjectChange($oldObj, $vCal, $addresses);
        }

        $this->processICalendarChange($oldObj, $vCal, $addresses, [], $modified);
    }

    protected function assertAllowedAttendeeSchedulingObjectChange(VCalendar $oldObject, VCalendar $newObject, array $addresses): void {
        if (!$this->shouldEnforceRfc6638() || !$this->isAttendeeSchedulingObject($oldObject, $addresses)) {
            return;
        }

        $oldEvents = CalendarObjectHelper::indexEventsByRecurrenceKey($oldObject);

        foreach ($newObject->select('VEVENT') as $newEvent) {
            $oldEvent = $oldEvents[CalendarObjectHelper::recurrenceKey($newEvent)] ?? null;
            if (!$oldEvent) {
                continue;
            }
            foreach (self::FORBIDDEN_ATTENDEE_CHANGE_PROPERTIES as $propertyName) {
                if (CalendarObjectHelper::propertySignatures($oldEvent, $propertyName) !== CalendarObjectHelper::propertySignatures($newEvent, $propertyName)) {
                    throw new ForbiddenAttendeeSchedulingObjectChange($propertyName);
                }
            }
        }
    }

    private function isAttendeeSchedulingObject(VCalendar $calendarObject, array $addresses): bool {
        $normalizedAddresses = array_map('strtolower', $addresses);
        $isAttendee = false;
        $hasOrganizer = false;

        foreach ($calendarObject->select('VEVENT') as $event) {
            if (isset($event->ORGANIZER)) {
                $hasOrganizer = true;
                if (in_array(strtolower($event->ORGANIZER->getNormalizedValue()), $normalizedAddresses, true)) {
                    return false;
                }
            }
            $isAttendee = $isAttendee || CalendarObjectHelper::hasAttendeeInAddresses($event, $normalizedAddresses);
        }

        return $hasOrganizer && $isAttendee;
    }

    private function shouldEnforceRfc6638(): bool {
        return $this->envBoolean(self::ENFORCE_RFC_6638_ENV, true);
    }

    private function envBoolean(string $name, bool $default): bool {
        $value = getenv($name);
        return $value === false || trim($value) === ''
            ? $default
            : filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
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

        $oldObject = CalendarObjectHelper::asFilterableRecurringCalendar($oldObject);
        if (!$oldObject) {
            return false;
        }

        $occurrencePair = CalendarObjectHelper::findMessageOccurrencePair($message, $oldObject, $newObject);
        if (!$occurrencePair) {
            return false;
        }
        list($recurrenceId, $oldVEvent, $newVEvent) = $occurrencePair;

        if ($recurrenceId === CalendarObjectHelper::MASTER_EVENT
            && $this->masterRequiresDelivery($message->message->VEVENT, $newVEvent, $oldObject, $newObject)) {
            return false;
        }

        if (CalendarObjectHelper::recipientAttendanceChanged($message->recipient, $oldVEvent, $newVEvent)) {
            return false;
        }

        if (CalendarObjectHelper::invitedExceptionCountChanged($message->recipient, $oldObject, $newObject)) {
            return false;
        }

        // Skip the message only when the occurrence hasn't changed significantly
        return !CalendarObjectHelper::occurrenceContentChanged($oldVEvent, $newVEvent);
    }

    private function masterRequiresDelivery($messageEvent, $newVEvent, $oldObject, VCalendar $newObject): bool {
        return CalendarObjectHelper::exDatesDiffer($messageEvent, $newVEvent)
            // Public Agenda rule: organizer chair transition to ACCEPTED must trigger notification delivery.
            || PublicAgendaScheduleUtils::isChairOrganizerAcceptedTransition($oldObject, $newObject);
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
    protected function processICalendarChange($oldObject, VCalendar $newObject, array $addresses, array $ignore = [], &$modified = false) {
        $messages = $this->createBroker()->parseEvent($newObject, $addresses, $oldObject);

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

            $this->preservePublicAgendaMetadata($message, $newObject);

            // When a new attendee is added only to a RECURRENCE-ID override (not the master),
            // the Broker iterates only $attendee['newInstances'] which contains no 'master' key,
            // so it builds a message with just the override VEVENT. The attendee then receives
            // an orphaned exception with no knowledge of the recurring series.
            // Fix: inject the master VEVENT from $newObject so the attendee gets a complete
            // iTIP payload. Also strip RRULE from override VEVENTs (RFC 5545 §3.8.5.3 forbids
            // RRULE in a component that has RECURRENCE-ID; a misbehaving client may send it).
            if ($message->method === 'REQUEST') {
                $this->sanitizeOutgoingRequestMessage($message, $newObject);
            }

            $this->deliver($message);

            CalendarObjectHelper::updateScheduleStatus($newObject, $message);
        }
    }

    /**
     * Builds an iTIP broker with the ESN-specific significant change properties.
     */
    private function createBroker(): ITip\Broker {
        $broker = new ITip\Broker();
        // Add SUMMARY, LOCATION, DESCRIPTION to significant change properties
        // These are important for email notifications even though they're not in RFC5546 list
        $broker->significantChangeProperties = array_merge(
            $broker->significantChangeProperties,
            ['SUMMARY', 'LOCATION', 'DESCRIPTION']
        );

        // OpenPaas extension properties (video conference / booking links) are neither
        // significant nor change properties in the RFC5546 default lists. A change limited
        // to one of them therefore yields hasChange=false, so the update never reaches the
        // attendee's synchronised calendar and per-attendee links can diverge. Track them as
        // change (not significant) properties so a link change propagates without resetting
        // participation status or re-sending the whole invitation.
        $broker->changeProperties = array_merge(
            $broker->changeProperties,
            ['X-OPENPAAS-VIDEOCONFERENCE', 'X-OPENPAAS-BOOKING-LINK']
        );

        return $broker;
    }

    private function getReplyPropagationThreshold(): int {
        $raw = getenv('TW_CAL_REPLY_PROPAGATION_THRESHOLD');

        if ($raw === false || $raw === '') {
            return self::DEFAULT_REPLY_PROPAGATION_THRESHOLD;
        }

        $threshold = (int)$raw;
        if ($threshold < 0) {
            return 0;
        }

        return $threshold;
    }

    private function shouldSkipReplyPropagation($oldObject): bool {
        $threshold = $this->getReplyPropagationThreshold();
        if ($threshold <= 0) {
            return false;
        }

        return $this->countEventAttendees($oldObject) >= $threshold;
    }

    private function countEventAttendees($calendarObject): int {
        $calendarObject = CalendarObjectHelper::readCalendarObject($calendarObject);
        if (!$calendarObject) {
            return 0;
        }

        $masterEvent = CalendarObjectHelper::findMasterEvent($calendarObject);
        if (!$masterEvent || !isset($masterEvent->ATTENDEE)) {
            return 0;
        }

        return CalendarObjectHelper::countUniqueAttendees($masterEvent->ATTENDEE);
    }

    /**
     * Correct a RECURRENCE-ID mismatch in incoming REPLY messages.
     *
     * Some CalDAV clients (e.g. Twake) create a new exception override when the
     * attendee accepts a moved occurrence.  Because the stored DTSTART is already
     * the moved time (e.g. 05:30 UTC), the client sets RECURRENCE-ID = DTSTART
     * (05:30 UTC) rather than preserving the original occurrence time (05:00 UTC)
     * that is the canonical RECURRENCE-ID in the organiser's calendar.
     *
     * Result: processMessageReply() receives RECURRENCE-ID:T053000Z but the
     * organiser's exception has RECURRENCE-ID:T050000Z → no match → silent no-op.
     *
     * Heuristic fix: if the REPLY carries a RECURRENCE-ID that does not match any
     * exception in the organiser's calendar, but that value equals the DTSTART of
     * one of the organiser's exceptions, replace it with the canonical RECURRENCE-ID
     * so the broker can route the update correctly.
     */
    private function normalizeReplyRecurrenceId(ITip\Message $iTipMessage, VCalendar $organizerCalendar): void {
        foreach ($iTipMessage->message->VEVENT as $replyVevent) {
            if (!isset($replyVevent->{'RECURRENCE-ID'})) {
                continue;
            }

            $replyRecurTs = $replyVevent->{'RECURRENCE-ID'}->getDateTime()->getTimestamp();

            if (CalendarObjectHelper::hasExceptionWithRecurrenceTimestamp($organizerCalendar, $replyRecurTs)) {
                continue; // Exact match — no correction needed.
            }

            // No match by RECURRENCE-ID.  Try to find an exception whose DTSTART
            // equals the REPLY's RECURRENCE-ID (the client used the moved time as key).
            $matchingException = CalendarObjectHelper::findExceptionByStartTimestamp($organizerCalendar, $replyRecurTs);
            if ($matchingException) {
                // Correct: replace client's wrong RECURRENCE-ID with the canonical one.
                $replyVevent->{'RECURRENCE-ID'} = clone $matchingException->{'RECURRENCE-ID'};
            }
        }
    }

    protected function preservePublicAgendaMetadata(ITip\Message $message, VCalendar $sourceCalendar): void {
        $sourceEvents = CalendarObjectHelper::indexEventsByRecurrenceKey($sourceCalendar);

        foreach ($message->message->select('VEVENT') as $messageEvent) {
            $sourceEvent = $sourceEvents[CalendarObjectHelper::recurrenceKey($messageEvent)] ?? null;
            if (!$sourceEvent) {
                continue;
            }

            foreach (self::PUBLIC_AGENDA_METADATA_PROPERTIES as $propertyName) {
                if ($messageEvent->select($propertyName)) {
                    continue;
                }

                foreach ($sourceEvent->select($propertyName) as $property) {
                    $messageEvent->add(clone $property);
                }
            }
        }
    }

    /**
     * Sanitises an outgoing REQUEST message that concerns a RECURRENCE-ID
     * override whose attendee is not present in the master VEVENT.
     *
     * The Sabre ITip\Broker builds each attendee's message by iterating only
     * the instances that attendee is invited to.  An attendee added exclusively
     * to an override (not the master) therefore receives a message with only
     * the override VEVENT — no master, no RRULE context.
     *
     * Two strategies are possible:
     *  A) Inject the master VEVENT → the attendee sees the whole recurring
     *     series in their calendar, including occurrences they are NOT part of.
     *     This confuses most calendar frontends.
     *  B) Strip RECURRENCE-ID (and any invalid RRULE) from the override VEVENT
     *     → the attendee receives a clean, standalone event for the one
     *     occurrence they were actually invited to.  If they are later invited
     *     to the master, the subsequent iTIP will carry the full master VEVENT
     *     and processMessage() will upgrade their entry naturally (same UID).
     *
     * Strategy B is applied here.
     *
     * As a secondary sanitisation, RRULE is stripped from any override VEVENT
     * (RFC 5545 §3.8.5.3: RRULE MUST NOT appear in a component that has
     * RECURRENCE-ID; some clients send it anyway).
     */
    private function sanitizeOutgoingRequestMessage(ITip\Message $message, VCalendar $sourceCalendar): void {
        if ($this->shouldEnableEmailValarmRecipientScheduling()) {
            $this->filterEmailAlarmsForRecipient($message, $sourceCalendar);
        }

        if (!CalendarObjectHelper::hasMasterEvent($message->message)) {
            // Override-only message: strip RRULE only (invalid per RFC 5545 §3.8.5.3
            // in a component that has RECURRENCE-ID), but KEEP RECURRENCE-ID.
            //
            // RECURRENCE-ID must be preserved so that when the attendee replies
            // (PARTSTAT change), the broker can route the REPLY back to the correct
            // occurrence in the organiser's calendar.  Without it, processMessage()
            // treats the REPLY as targeting the master VEVENT, propagating the
            // PARTSTAT change to all occurrences — which is wrong.
            //
            // Calendar clients that receive a VEVENT with RECURRENCE-ID but no
            // master VEVENT display it as a plain standalone event (the series
            // context is absent), so the attendee sees exactly one occurrence.
            foreach ($message->message->VEVENT as $vevent) {
                unset($vevent->RRULE);
            }
            return;
        }

        CalendarObjectHelper::stripRruleFromOverrides($message->message);
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

        $oldObject = Reader::read($node->get());
        $messages = $this->createBroker()->parseEvent(null, $addresses, $oldObject);

        foreach ($messages as $message) {
            $this->preservePublicAgendaMetadata($message, $oldObject);
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
    protected function fetchCalendarOwnerAddresses($calendarPath): array {
        $calendarNode = $this->server->tree->getNodeForPath($calendarPath);

        if ($calendarNode === null || !method_exists($calendarNode, 'getOwner')) {
            return [];
        }

        $owner = $calendarNode->getOwner();
        if ($owner === null) {
            return [];
        }

        $resourceAddresses = $this->getResourceOwnerAddresses($owner);
        if ($resourceAddresses !== null) {
            return $resourceAddresses;
        }

        return $this->getAddressesForPrincipalSafely($owner);
    }

    /**
     * For resource principals, gets the email address directly from principal
     * properties to avoid issues with getAddressesForPrincipal() failing
     * (issue #195). Returns null when the owner is not a resource or the
     * lookup fails, so the caller can fall back to the standard method.
     */
    private function getResourceOwnerAddresses($owner): ?array {
        if (!$this->canResolveResourceEmail($owner)) {
            return null;
        }

        try {
            $principalInfo = $this->principalBackend->getPrincipalByPath($owner);
            $email = $principalInfo['{http://sabredav.org/ns}email-address'] ?? null;
            if (is_string($email) && !empty($email)) {
                return ['mailto:' . $email];
            }
        } catch (\Throwable $e) {
            // Log but continue to try standard method
            $this->logger->debug('Failed to get resource email from principal backend', [
                'principal' => $owner,
                'exception' => get_class($e) . ': ' . $e->getMessage()
            ]);
        }

        return null;
    }

    private function canResolveResourceEmail($owner): bool {
        return strpos($owner, 'principals/resources/') === 0
            && $this->principalBackend
            && method_exists($this->principalBackend, 'getPrincipalByPath');
    }

    private function getAddressesForPrincipalSafely($owner): array {
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
}
