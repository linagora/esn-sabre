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
        if ($this->server->httpRequest->getMethod() === 'ITIP') {
            // ITIP call from Twake Calendar Side Service — use synchronous delivery.
            // EventRealTimePlugin will fire via the 'iTip' hook and publish real-time notifications.
            parent::scheduleLocalDelivery($iTipMessage);
            return;
        }

        $key = $iTipMessage->method . '|' . $iTipMessage->uid;

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
        if ($request->getMethod() === 'ITIP' || !$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        if (PublicAgendaScheduleUtils::isPubliclyCreatedAndChairOrganizerNotAccepted($vCal)) {
            return;
        }

        $addresses = $this->fetchCalendarOwnerAddresses($calendarPath);

        $this->currentOldMessage = null;
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

        $addresses = $this->fetchCalendarOwnerAddresses($calendarPath);
        if (empty($addresses)) return;

        $broker = new ITip\Broker();
        $broker->significantChangeProperties = array_merge(
            $broker->significantChangeProperties,
            ['SUMMARY', 'LOCATION', 'DESCRIPTION']
        );
        $messages = $broker->parseEvent(null, $addresses, $node->get());

        foreach ($messages as $message) {
            $this->deliver($message);
        }

        $this->flushDeliveries();
    }

    /**
     * Publish one AMQP message per group (method+uid) and reset the buffer.
     */
    private function flushDeliveries() {
        foreach ($this->pendingDeliveries as $delivery) {
            if (!empty($this->currentOldMessage)) {
                $delivery['oldMessage'] = $this->currentOldMessage;
            }
            $this->amqpPublisher->publish(
                self::TOPIC_LOCAL_DELIVERY,
                json_encode($delivery)
            );
        }
        $this->pendingDeliveries  = [];
        $this->currentOldMessage  = null;
    }
}
