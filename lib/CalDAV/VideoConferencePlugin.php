<?php

namespace ESN\CalDAV;

use ESN\Utils\RepairedNameIndex;
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
    private static array $SCRIPT_SCHEMES = ['javascript', 'vbscript', 'data'];
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
        if ($this->filterCalendar($vCal)) {
            $modified = true;
        }
    }

    // Shared by CalDAV writes and iTIP delivery before either path stores the event.
    function filterCalendar(VCalendar $vCal): bool {
        $modified = false;
        // Sabre's name repair can turn "..CONFERENCE" into a CONFERENCE that select() misses.
        RepairedNameIndex::refresh($vCal);
        if ($this->removeScriptLinks($vCal)) {
            $modified = true;
        }
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
        return $modified;
    }

    /**
     * Whatever the trusted URL base, a link that runs a script or embeds a document when
     * opened is never a conference to join: it is not stored, nor mirrored by
     * VideoConferenceDecorator into a "Join video call" CONFERENCE. Other schemes are kept, as
     * CONFERENCE also carries phone bridges (tel:) or chat rooms (xmpp:).
     */
    private function removeScriptLinks(VCalendar $vCal): bool {
        $modified = false;
        foreach ($vCal->select('VEVENT') as $event) {
            foreach ([self::$VIDEOCONFERENCE_PROPERTY, self::$CONFERENCE_PROPERTY] as $name) {
                foreach ($event->select($name) as $link) {
                    if (in_array(self::schemeOf((string) $link), self::$SCRIPT_SCHEMES, true)) {
                        $event->remove($link);
                        $modified = true;
                    }
                }
            }
        }

        return $modified;
    }

    /**
     * The scheme a browser resolves: it ignores leading and trailing C0 controls and spaces,
     * and every tab and newline, so "  Java\tScript:" still runs a script.
     */
    private static function schemeOf(string $url): ?string {
        $url = str_replace(["\t", "\n", "\r"], '', trim($url, "\x00..\x20"));

        return preg_match('/^([a-z][a-z0-9+.-]*):/i', $url, $matches) ? strtolower($matches[1]) : null;
    }
}
