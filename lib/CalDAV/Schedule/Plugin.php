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

        try {
            $addresses = $this->getAddressesForPrincipal($owner);
            // getAddressesForPrincipal may return null, ensure we return an array
            return $addresses ?: [];
        } catch (\Exception $e) {
            // If we can't get addresses for the principal, return empty array
            // This can happen when accessing shared calendars
            return [];
        }
    }
}
