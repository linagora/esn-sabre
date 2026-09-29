<?php

namespace ESN\CalDAV;

use ESN\Utils\TrustedUrlBase;
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
    private static string $VIDEOCONFERENCE_PROPERTY = 'X-OPENPAAS-VIDEOCONFERENCE';
    private static string $CONFERENCE_PROPERTY = 'CONFERENCE';
    private ?TrustedUrlBase $trustedUrlBase;

    function __construct(?string $trustedUrlBase = null) {
        $this->trustedUrlBase = $trustedUrlBase !== null && $trustedUrlBase !== ''
            ? new TrustedUrlBase($trustedUrlBase) : null;
    }

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
        if ($this->trustedUrlBase !== null) {
            foreach ($vCal->select('VEVENT') as $event) {
                foreach ($event->select(self::$VIDEOCONFERENCE_PROPERTY) as $link) {
                    if (trim((string) $link) !== '' && !$this->trustedUrlBase->accepts((string) $link)) {
                        $event->remove($link);
                        $modified = true;
                    }
                }

                // CONFERENCE can recreate the OpenPaaS link, so filter video links in both forms.
                foreach ($event->select(self::$CONFERENCE_PROPERTY) as $conference) {
                    $features = array_map('trim', explode(',', strtoupper((string) ($conference['FEATURE'] ?? ''))));
                    if (in_array('VIDEO', $features, true) && !$this->trustedUrlBase->accepts((string) $conference)) {
                        $event->remove($conference);
                        $modified = true;
                    }
                }
            }
        }

        if (VideoConferenceDecorator::decorate($vCal)) {
            $modified = true;
        }
    }
}
