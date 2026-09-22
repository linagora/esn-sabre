<?php
namespace ESN\CalDAV;

use \ESN\DAV\VObjectCachePlugin;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\CalDAV\ICalendarObject;
use \Sabre\HTTP\RequestInterface;
use \Sabre\HTTP\ResponseInterface;
use \Sabre\VObject\Component\VCalendar;

#[\AllowDynamicProperties]
class ParticipationPlugin extends ServerPlugin {

    /**
     * This is the official CalDAV namespace
     */
    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    protected $server;

    function initialize(Server $server) {
        $this->server = $server;

        // calendarObjectChange gives us the object Sabre has already parsed and
        // lets it do the one re-serialization at the end, instead of parsing and
        // serializing the payload again here on every write.
        //
        // Runs just before scheduling, so a propagated participation status is
        // part of what gets scheduled.
        $server->on('calendarObjectChange', [$this, 'calendarObjectChange'], Plugin::PRIORITY_BEFORE_SCHEDULING - 5);
    }

    /**
     * Propagates a change of participation status on the series to the
     * overrides that still follow it.
     *
     * @param VCalendar $vCal     the parsed object, mutated in place
     * @param bool      $modified set when an override was updated
     */
    function calendarObjectChange(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        // Nothing to propagate on a creation: there is no previous answer to
        // compare the new one against.
        if ($isNew || !$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        $node = $this->server->tree->getNodeForPath($request->getPath());

        if (!$node instanceof ICalendarObject) {
            return;
        }

        // Shared instance: we only compare participation status against it.
        $oldCal = VObjectCachePlugin::cacheFor($this->server)->read($node->get());

        $this->processICalendarParticipation($vCal, $oldCal, $modified);
    }

    protected function processICalendarParticipation(VCalendar $vCal, VCalendar $oldCal, &$modified) {
        $addresses = $this->getAddressesForPrincipal(
            $this->server->getPlugin('auth')->getCurrentPrincipal()
        );

        if (empty($addresses)) {
            return;
        }

        $newInstances = $this->getAllInstancePartstatForAttendee($vCal, $addresses[0]);
        $oldInstances = $this->getAllInstancePartstatForAttendee($oldCal, $addresses[0]);

        if (!isset($newInstances['master']) || !isset($oldInstances['master'])) {
            return;
        }

        $partstat = $newInstances['master']['partstat'];

        if (!$partstat || !$oldInstances['master']['partstat'] || $partstat === $oldInstances['master']['partstat']) {
            return;
        }

        $now = new \DateTimeImmutable();

        foreach ($vCal->VEVENT as $vevent) {
            if (!isset($vevent->{'RECURRENCE-ID'})) {
                continue;
            }

            // Past overrides keep their explicit response when the series response changes.
            if ($this->isPastRecurrence($vevent->{'RECURRENCE-ID'}, $now)) {
                continue;
            }

            if (!isset($vevent->ATTENDEE)) {
                continue;
            }

            foreach ($vevent->ATTENDEE as $attendee) {
                if (strtolower($attendee->getValue()) == $addresses[0]) {
                    isset($attendee['PARTSTAT']) ? $attendee['PARTSTAT']->setValue($partstat) : $attendee['PARTSTAT'] = $partstat;
                    $modified = true;
                }
            }
        }
    }

    private function isPastRecurrence($recurrenceId, $now) {
        $recurrenceDateTime = $recurrenceId->getDateTime();

        if (!$recurrenceId->hasTime()) {
            return $recurrenceDateTime->format('Ymd') < $now->setTimezone($recurrenceDateTime->getTimezone())->format('Ymd');
        }

        return $recurrenceDateTime < $now;
    }

    /**
     * This method checks the 'Schedule-Reply' header
     * and returns false if it's 'F', otherwise true.
     *
     * @param RequestInterface $request
     * @return bool
     */
    private function scheduleReply(RequestInterface $request) {
        $scheduleReply = $request->getHeader('Schedule-Reply');
        return $scheduleReply !== 'F';
    }

    /**
     * Returns a list of addresses that are associated with a principal.
     *
     * @param string $principal
     * @return array
     */
    private function getAddressesForPrincipal($principal) {
        $CUAS = '{' . self::NS_CALDAV . '}calendar-user-address-set';

        $properties = $this->server->getProperties(
            $principal,
            [$CUAS]
        );

        // If we can't find this information, we'll stop processing
        if (!isset($properties[$CUAS])) {
            return;
        }

        $addresses = $properties[$CUAS]->getHrefs();

        return $addresses;
    }

    private function getAllInstancePartstatForAttendee($vcal, $email) {
        $instances = [];

        foreach ($vcal->VEVENT as $vevent) {
            $recurId = isset($vevent->{'RECURRENCE-ID'}) ? $vevent->{'RECURRENCE-ID'}->getValue() : 'master';

            $instances[$recurId] = [
                'id' => $recurId,
                'partstat' => $this->getParstatFromEmail($vevent, $email)
            ];
        }

        return $instances;
    }

    private function getParstatFromEmail($event, $email) {
        $partstat= null;

        if ($event->ATTENDEE) {
            foreach ($event->ATTENDEE as $attendee) {
                if (strtolower($attendee->getValue()) == $email) {
                    $partstat = isset($attendee['PARTSTAT']) ? $attendee['PARTSTAT']->getValue() : null;
                }
            }
        }

        return $partstat;
    }
}
