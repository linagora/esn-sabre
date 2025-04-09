<?php
namespace ESN\CalDAV;

use \ESN\Utils\Utils;
use \Sabre\DAV\Server;
use \Sabre\DAV\ServerPlugin;
use \Sabre\CalDAV\ICalendarObject;
use \Sabre\CalDAV\ICalendar;
use \Sabre\Uri;
use \Sabre\HTTP\RequestInterface;

class ParticipationPlugin extends ServerPlugin {

    /**
     * This is the official CalDAV namespace
     */
    const NS_CALDAV = 'urn:ietf:params:xml:ns:caldav';

    function initialize(Server $server) {
        $this->server = $server;
        $server->on('beforeWriteContent', [$this, 'beforeWriteContent'], 1);
    }

    function beforeWriteContent($path, \Sabre\DAV\IFile $node, &$data, &$modified) {
        if (!$this->scheduleReply($this->server->httpRequest)) {
            return;
        }

        if (!$node instanceof ICalendarObject) {
            return;
        }

        // We're only interested in ICalendarObject nodes that are inside of a
        // real calendar. This is to avoid triggering validation and scheduling
        // for non-calendars (such as an inbox).
        list($parent) = Uri\split($path);
        $parentNode = $this->server->tree->getNodeForPath($parent);

        if (!$parentNode instanceof ICalendar) {
            return;
        }

        $oldCal = \Sabre\VObject\Reader::read($node->get());

        $this->processICalendarParticipation(
            $node,
            $data,
            $oldCal,
            $modified
        );
    }

    protected function processICalendarParticipation($node, &$data, $oldCal, &$modified) {
        list($data, $modified) = Utils::formatIcal($data, $modified);

        $addresses = $this->getAddressesForPrincipal(
            $this->server->getPlugin('auth')->getCurrentPrincipal()
        );
        
        $newInstances = $this->getAllInstancePartstatForAttendee($data, $addresses[0]);
        $oldInstances = $this->getAllInstancePartstatForAttendee($oldCal, $addresses[0]);

        if (isset($newInstances['master']) && isset($oldInstances['master'])) {
            if ($newInstances['master']['partstat'] && $oldInstances['master']['partstat'] && $newInstances['master']['partstat'] !== $oldInstances['master']['partstat']) {
                foreach ($data->VEVENT as $vevent) {
                    if (!isset($vevent->{'RECURRENCE-ID'})) {
                        continue;
                    }

                    foreach ($vevent->ATTENDEE as $attendee) {
                        if (strtolower($attendee->getValue()) == $addresses[0]) {
                            isset($attendee['PARTSTAT']) ? $attendee['PARTSTAT']->setValue($newInstances['master']['partstat']) : $attendee['PARTSTAT'] = $newInstances['master']['partstat'];
                        }
                    }
                }
            }
        }

        $data = $data->serialize();

        return;
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