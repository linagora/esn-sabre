<?php

namespace ESN\CalDAV\Schedule\Exception;

use Sabre\CalDAV\Schedule\Plugin as SabreSchedulePlugin;
use Sabre\DAV\Exception\Forbidden;
use Sabre\DAV\Server;

class ForbiddenAttendeeSchedulingObjectChange extends Forbidden {
    public function __construct(string $propertyName) {
        parent::__construct('Attendees are not allowed to change ' . $propertyName . ' on scheduling objects');
    }

    public function serialize(Server $server, \DOMElement $errorNode) {
        $errorNode->appendChild($errorNode->ownerDocument->createElementNS(SabreSchedulePlugin::NS_CALDAV, 'cal:allowed-attendee-scheduling-object-change'));
    }
}
