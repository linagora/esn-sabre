<?php
namespace ESN\CalDAV\Schedule;

use ESN\Utils\Utils;
use Sabre\CalDAV\ICalendarObject;
use Sabre\CalDAV\Schedule\ISchedulingObject;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\ITip;

/**
 * Async scheduling plugin — replaces ESN\CalDAV\Schedule\Plugin when
 * AMQP_SCHEDULING_ENABLED=true.
 *
 * Instead of writing directly into attendee calendars and inboxes,
 * this plugin buffers all recipients and publishes a single AMQP message
 * to 'calendar:itip:localDelivery'. Twake Calendar Side Service consumes
 * that message and fans out individual ITIP calls back to Sabre.
 *
 * Extends ESN\CalDAV\Schedule\Plugin to inherit:
 *   - fetchCalendarOwnerAddresses()      [changed to protected]
 *   - processICalendarChange()
 *   - shouldSkipUnchangedOccurrence()
 *   - deliver() healthcheck overrides
 *   - Public Agenda logic
 */
class AMQPSchedulePlugin extends Plugin {

    const TOPIC_LOCAL_DELIVERY = 'calendar:itip:localDelivery';

    private $amqpPublisher;
    private $pendingDeliveries = [];
    private $currentOldMessage = null;
    private $currentCalendarId = null;

    public function __construct($amqpPublisher, $principalBackend = null) {
        parent::__construct($principalBackend);
        $this->amqpPublisher = $amqpPublisher;
    }

    /**
     * Buffer recipients for AMQP publish on PUT/POST.
     *
     * Falls back to synchronous parent delivery on ITIP requests (from
     * Twake Calendar Side Service) to prevent an infinite loop:
     *   consumer → POST /itip → scheduleLocalDelivery → AMQP → consumer → ...
     *
     * ITipPlugin calls this method directly (ITipPlugin.php:73):
     *   $this->server->getPlugin('caldav-schedule')->scheduleLocalDelivery($message)
     * so the loop guard must live here, not in calendarObjectChange.
     */
    function scheduleLocalDelivery(ITip\Message $iTipMessage) {
        $req = $this->server->httpRequest;
        if ($req->getMethod() === 'ITIP' || $req->getPath() === 'itip') {
            if (strtoupper((string)$iTipMessage->method) === 'COUNTER') {
                $this->handleItipCounterLocalDelivery($iTipMessage, $req);
                return;
            }

            // ITIP call from Twake Calendar Side Service (via custom ITIP verb or
            // POST /itip) — use synchronous delivery.
            // EventRealTimePlugin will fire via the 'iTip' hook and publish real-time notifications.
            parent::scheduleLocalDelivery($iTipMessage);
            return;
        }

        // Key includes recipient: CANCEL (and other methods) generate a personalized ICS per
        // attendee (only that attendee in the ATTENDEE field). Grouping by method|uid only would
        // store the first attendee's ICS and discard the rest, causing assertRecipientIsConcernedByEvent
        // to reject delivery for all subsequent attendees (their email not in the stored ATTENDEE list).
        $key = $iTipMessage->method . '|' . $iTipMessage->uid . '|' . $iTipMessage->recipient;

        if (!isset($this->pendingDeliveries[$key])) {
            $this->pendingDeliveries[$key] = [
                'sender'     => $iTipMessage->sender,
                'method'     => $iTipMessage->method,
                'uid'        => $iTipMessage->uid,
                'message'    => $iTipMessage->message->serialize(),
                'hasChange'  => $iTipMessage->hasChange,
                'recipients' => [],
            ];
        }

        $this->pendingDeliveries[$key]['recipients'][] = $iTipMessage->recipient;

        // Exact '1.0' (no description text) — short-circuits EventRealTimePlugin.schedule()
        // which checks scheduleStatus with a strict string comparison against the constant.
        $iTipMessage->scheduleStatus = '1.0';
    }

    private function handleItipCounterLocalDelivery(ITip\Message $iTipMessage, RequestInterface $req): void {
        list($calendarPath,) = Utils::splitEventPath('/' . ltrim($req->getPath(), '/'));
        $calendarId = $calendarPath ? basename($calendarPath) : null;
        $oldMessage = $this->resolveCounterOldMessage($iTipMessage, $req);

        // Keep synchronous delivery for ITIP path.
        parent::scheduleLocalDelivery($iTipMessage);

        $payload = [
            'sender' => $iTipMessage->sender,
            'method' => $iTipMessage->method,
            'uid' => $iTipMessage->uid,
            'message' => $iTipMessage->message->serialize(),
            'hasChange' => $iTipMessage->hasChange,
            'recipients' => [$iTipMessage->recipient],
        ];
        if ($calendarId !== null) {
            $payload['calendarId'] = $calendarId;
        }
        if ($oldMessage !== null) {
            $payload['oldMessage'] = $oldMessage;
        }

        $this->amqpPublisher->publish(self::TOPIC_LOCAL_DELIVERY, json_encode($payload));
    }

    private function resolveCounterOldMessage(ITip\Message $iTipMessage, RequestInterface $req): ?string {
        $recipientPrincipal = Utils::getPrincipalByUri($iTipMessage->recipient, $this->server);

        if ($recipientPrincipal) {
            $result = Utils::getEventObjectFromAnotherPrincipalHome(
                $recipientPrincipal,
                $iTipMessage->uid,
                $iTipMessage->method,
                $this->server
            );
            if ($result) {
                list(, $oldMessage) = $result;
                if ($oldMessage !== null) {
                    return $oldMessage;
                }
            }
        }

        try {
            return $this->server->tree->getNodeForPath($req->getPath())->get();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Override calendarObjectChange to capture oldMessage and flush deliveries.
     */
    function calendarObjectChange(
        RequestInterface $request,
        ResponseInterface $response,
        VCalendar $vCal,
        $calendarPath,
        &$modified,
        $isNew
    ) {
        if ($request->getMethod() === 'ITIP' || $request->getPath() === 'itip' || !$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        if (PublicAgendaScheduleUtils::isPubliclyCreatedAndChairOrganizerNotAccepted($vCal)) {
            return;
        }

        $addresses = $this->fetchCalendarOwnerAddresses($calendarPath);

        $this->currentOldMessage = null;
        $this->currentCalendarId = basename($calendarPath);
        $oldObj = null;
        if (!$isNew) {
            $node = $this->server->tree->getNodeForPath($request->getPath());
            $this->currentOldMessage = $node->get();
            $oldObj = \Sabre\VObject\Reader::read($this->currentOldMessage);
        }

        $this->processICalendarChange($oldObj, $vCal, $addresses, [], $modified);

        $this->flushDeliveries();
    }

    /**
     * Override beforeUnbind to buffer CANCEL recipients on DELETE via AMQP.
     *
     * Without this override the parent's synchronous beforeUnbind would fire
     * and write directly into attendee calendars, bypassing AMQP entirely.
     */
    function beforeUnbind($path) {
        if ($this->server->httpRequest->getMethod() === 'MOVE') return;

        $node = $this->server->tree->getNodeForPath($path);

        if (!$node instanceof ICalendarObject || $node instanceof ISchedulingObject) {
            return;
        }

        if (!$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        list($calendarPath,) = Utils::splitEventPath('/' . $path);
        if (!$calendarPath) return;

        $this->currentCalendarId = basename($calendarPath);
        $addresses = $this->fetchCalendarOwnerAddresses($calendarPath);
        if (empty($addresses)) return;

        $nodeIcs = $node->get();

        // The ITip Broker crashes on exception-only calendars (VEVENT with RECURRENCE-ID but no
        // master VEVENT): it tries DateTimeParser::parse("master") → InvalidDataException.
        // This happens when an attendee invited to a single occurrence deletes it — their stored
        // calendar object has no master VEVENT (RRULE stripped by sanitizeOutgoingRequestMessage).
        //
        // Fix: fetch the organizer's full calendar (which has the master VEVENT + the override
        // with this attendee) and pass it to the Broker so it can generate a proper REPLY.
        $nodeIcs = $this->resolveFullCalendarForBroker($nodeIcs) ?: $nodeIcs;

        $broker = new ITip\Broker();
        $broker->significantChangeProperties = array_merge(
            $broker->significantChangeProperties,
            ['SUMMARY', 'LOCATION', 'DESCRIPTION']
        );
        $messages = $broker->parseEvent(null, $addresses, $nodeIcs);

        foreach ($messages as $message) {
            $this->deliver($message);
        }

        $this->flushDeliveries();
    }

    /**
     * When a calendar ICS has no master VEVENT (exception-only, e.g. an attendee invited to
     * a single occurrence), fetch the organizer's full calendar for the same UID so the ITip
     * Broker has a complete event to work with.
     *
     * Returns the organizer's ICS string on success, or null if unavailable (external organizer,
     * permission error, not found) — callers must fall back to the original ICS.
     */
    private function resolveFullCalendarForBroker(string $ics): ?string {
        $vCal = \Sabre\VObject\Reader::read($ics);

        $hasMaster = false;
        foreach ($vCal->VEVENT as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                $hasMaster = true;
                break;
            }
        }

        if ($hasMaster) {
            $vCal->destroy();
            return null; // Nothing to fix — caller uses original ICS as-is.
        }

        // Pick organizer and UID from the first (only) exception VEVENT.
        $exceptionVevent = $vCal->VEVENT;
        $organizerRaw    = isset($exceptionVevent->ORGANIZER) ? (string)$exceptionVevent->ORGANIZER : null;
        $uid             = isset($exceptionVevent->UID)       ? (string)$exceptionVevent->UID       : null;
        $vCal->destroy();

        if (!$organizerRaw || !$uid) {
            return null;
        }

        $organizerPrincipal = Utils::getPrincipalByUri($organizerRaw, $this->server);
        if (!$organizerPrincipal) {
            return null; // External organizer — can't fetch their calendar.
        }

        $result = Utils::getEventObjectFromAnotherPrincipalHome(
            $organizerPrincipal, $uid, 'REQUEST', $this->server
        );

        if (!$result) {
            return null;
        }

        list(, $organizerIcs) = $result;
        return $organizerIcs ?: null;
    }

    /**
     * Publish one AMQP message per group (method+uid) and reset the buffer.
     */
    private function flushDeliveries() {
        foreach ($this->pendingDeliveries as $delivery) {
            if (!empty($this->currentOldMessage)) {
                $delivery['oldMessage'] = $this->currentOldMessage;
            }
            if ($this->currentCalendarId !== null) {
                $delivery['calendarId'] = $this->currentCalendarId;
            }
            $this->amqpPublisher->publish(
                self::TOPIC_LOCAL_DELIVERY,
                json_encode($delivery)
            );
        }
        $this->pendingDeliveries  = [];
        $this->currentOldMessage  = null;
        $this->currentCalendarId  = null;
    }
}
