<?php

namespace ESN\CalDAV;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;

/**
 * Keeps the OpenPaaS specific X-OPENPAAS-VIDEOCONFERENCE property and the standard
 * RFC 7986 CONFERENCE property in sync, in both directions.
 *
 * X-OPENPAAS-VIDEOCONFERENCE is only understood by Twake clients: external clients
 * (Apple Calendar, iOS, Outlook, ...) only render a "join" button when the event carries a
 * CONFERENCE property. An event carrying the OpenPaaS property gets its CONFERENCE derived
 * from it, an event created by an external client with a video CONFERENCE and no OpenPaaS
 * property gets the latter derived from the former: whichever client created the event, both
 * families see its video conference link.
 */
class VideoConferenceDecorator {
    private static string $VIDEOCONFERENCE_PROPERTY = 'X-OPENPAAS-VIDEOCONFERENCE';
    private static string $CONFERENCE_PROPERTY = 'CONFERENCE';
    private static string $CONFERENCE_LABEL = 'Join video call';
    private static array $CONFERENCE_FEATURES = ['AUDIO', 'VIDEO'];

    /**
     * Syncs the video conference properties of every VEVENT of the calendar object (recurrence
     * master and overridden instances alike), in place. Each VEVENT is handled on its own:
     * a component without a video conference link of its own does not get one.
     *
     * @return bool true when the calendar object was modified.
     */
    public static function decorate(VCalendar $vCal): bool {
        $modifications = array_map(fn(VEvent $vevent) => self::decorateEvent($vevent), $vCal->select('VEVENT'));

        return in_array(true, $modifications, true);
    }

    private static function decorateEvent(VEvent $vevent): bool {
        return isset($vevent->{self::$VIDEOCONFERENCE_PROPERTY})
            ? self::deriveConference($vevent)
            : self::deriveVideoConferenceLink($vevent);
    }

    /**
     * The OpenPaaS property is the source of truth: it is left untouched and the CONFERENCE
     * property is aligned on it.
     */
    private static function deriveConference(VEvent $vevent): bool {
        $videoConferenceUri = trim((string) $vevent->{self::$VIDEOCONFERENCE_PROPERTY});

        // Conferences of a previous link have to go, otherwise clients would offer to join
        // a room which is no longer the one of the event.
        $modified = self::removeOutdatedVideoConferences($vevent, $videoConferenceUri);

        // An empty link means the video conference was removed from the event.
        if ($videoConferenceUri === '' || self::hasConferenceFor($vevent, $videoConferenceUri)) {
            return $modified;
        }

        $vevent->add(self::$CONFERENCE_PROPERTY, $videoConferenceUri, [
            'VALUE' => 'URI',
            'FEATURE' => self::$CONFERENCE_FEATURES,
            'LABEL' => self::$CONFERENCE_LABEL
        ]);

        return true;
    }

    /**
     * Reverse direction: an event created by an external client advertises its video
     * conference through CONFERENCE only. Twake clients read X-OPENPAAS-VIDEOCONFERENCE, so
     * it is derived from the first video conference of the event.
     */
    private static function deriveVideoConferenceLink(VEvent $vevent): bool {
        $videoConferenceUris = array_filter(
            array_map(fn(Property $conference) => trim((string) $conference), self::videoConferences($vevent)),
            fn(string $uri) => $uri !== ''
        );

        if ($videoConferenceUris === []) {
            return false;
        }

        $vevent->add(self::$VIDEOCONFERENCE_PROPERTY, reset($videoConferenceUris));

        return true;
    }

    private static function hasConferenceFor(VEvent $vevent, string $videoConferenceUri): bool {
        $conferenceUris = array_map(fn(Property $conference) => trim((string) $conference), $vevent->select(self::$CONFERENCE_PROPERTY));

        return in_array($videoConferenceUri, $conferenceUris, true);
    }

    private static function removeOutdatedVideoConferences(VEvent $vevent, string $videoConferenceUri): bool {
        $outdated = array_filter(
            self::videoConferences($vevent),
            fn(Property $conference) => trim((string) $conference) !== $videoConferenceUri
        );

        foreach ($outdated as $conference) {
            $vevent->remove($conference);
        }

        return $outdated !== [];
    }

    /**
     * Only the conferences advertising the VIDEO feature are video ones: a phone bridge or a
     * chat room added by another client is neither replaced nor mirrored back.
     *
     * @return Property[]
     */
    private static function videoConferences(VEvent $vevent): array {
        return array_filter($vevent->select(self::$CONFERENCE_PROPERTY), fn(Property $conference) => self::advertisesVideo($conference));
    }

    private static function advertisesVideo(Property $conference): bool {
        $features = array_map('trim', explode(',', strtoupper((string) ($conference['FEATURE'] ?? ''))));

        return in_array('VIDEO', $features, true);
    }
}
