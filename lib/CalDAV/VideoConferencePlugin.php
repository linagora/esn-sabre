<?php

namespace ESN\CalDAV;

use Sabre\DAV\Server;
use Sabre\DAV\ServerPlugin;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component\VCalendar;

/**
 * Exposes the video conference link of an event through the standard RFC 7986 CONFERENCE
 * property, so that clients which do not know about X-OPENPAAS-VIDEOCONFERENCE still
 * display a join button.
 *
 * @see VideoConferenceDecorator
 */
class VideoConferencePlugin extends ServerPlugin {

    function initialize(Server $server) {
        VObjectPropertyRegistry::register();

        // Decorate before scheduling: the iTIP messages sent to the attendees are built
        // from this very object, so they carry the CONFERENCE property as well.
        $server->on('calendarObjectChange', [$this, 'calendarObjectChange'], Plugin::PRIORITY_BEFORE_SCHEDULING - 20);
    }

    function getPluginName() {
        return 'caldav-videoconference';
    }

    function calendarObjectChange(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        if (VideoConferenceDecorator::decorate($vCal)) {
            $modified = true;
        }
    }
}
