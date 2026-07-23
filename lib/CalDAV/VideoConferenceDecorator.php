<?php

namespace ESN\CalDAV;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Component\VEvent;
use Sabre\VObject\Property;

/**
 * Mirrors the OpenPaaS specific X-OPENPAAS-VIDEOCONFERENCE property into the standard
 * RFC 7986 CONFERENCE property.
 *
 * X-OPENPAAS-VIDEOCONFERENCE is only understood by Twake clients: external clients
 * (Apple Calendar, iOS, Outlook, ...) only render a "join" button when the event carries a
 * CONFERENCE property. X-OPENPAAS-VIDEOCONFERENCE stays the source of truth and is left
 * untouched, CONFERENCE is derived from it.
 */
class VideoConferenceDecorator {
    private static string $VIDEOCONFERENCE_PROPERTY = 'X-OPENPAAS-VIDEOCONFERENCE';
    private static string $CONFERENCE_PROPERTY = 'CONFERENCE';
    private static string $CONFERENCE_LABEL = 'Join video call';
    private static array $CONFERENCE_FEATURES = ['AUDIO', 'VIDEO'];

    /**
     * Derives the CONFERENCE property of every VEVENT of the calendar object (recurrence
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
        if (!isset($vevent->{self::$VIDEOCONFERENCE_PROPERTY})) {
            // Nothing tells us whether the event has a video conference: leave any
            // CONFERENCE set by an external client alone.
            return false;
        }

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

    private static function hasConferenceFor(VEvent $vevent, string $videoConferenceUri): bool {
        $conferenceUris = array_map(fn(Property $conference) => trim((string) $conference), $vevent->select(self::$CONFERENCE_PROPERTY));

        return in_array($videoConferenceUri, $conferenceUris, true);
    }

    private static function removeOutdatedVideoConferences(VEvent $vevent, string $videoConferenceUri): bool {
        $outdated = array_filter(
            $vevent->select(self::$CONFERENCE_PROPERTY),
            fn(Property $conference) => self::advertisesVideo($conference) && trim((string) $conference) !== $videoConferenceUri
        );

        foreach ($outdated as $conference) {
            $vevent->remove($conference);
        }

        return $outdated !== [];
    }

    /**
     * Only the conferences this server derives from X-OPENPAAS-VIDEOCONFERENCE are video
     * ones: a phone bridge or a chat room added by another client is left alone.
     */
    private static function advertisesVideo(Property $conference): bool {
        $features = array_map('trim', explode(',', strtoupper((string) ($conference['FEATURE'] ?? ''))));

        return in_array('VIDEO', $features, true);
    }
}
