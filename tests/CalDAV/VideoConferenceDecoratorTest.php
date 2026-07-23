<?php

namespace ESN\CalDAV;

use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

/**
 * @medium
 */
class VideoConferenceDecoratorTest extends \PHPUnit\Framework\TestCase {

    const VIDEO_CONFERENCE_URL = 'https://meet.example.com/room';

    function setUp(): void {
        VObjectPropertyRegistry::register();
    }

    function testShouldAddConferencePropertyWhenEventHasAVideoConferenceLink() {
        $vCal = $this->readEvent([
            'X-OPENPAAS-VIDEOCONFERENCE;VALUE=UNKNOWN:' . self::VIDEO_CONFERENCE_URL
        ]);

        $this->assertTrue(VideoConferenceDecorator::decorate($vCal));

        $conference = $vCal->VEVENT->CONFERENCE;
        $this->assertSame(self::VIDEO_CONFERENCE_URL, (string) $conference);
        $this->assertSame('URI', (string) $conference['VALUE']);
        $this->assertSame('AUDIO,VIDEO', (string) $conference['FEATURE']);
        $this->assertSame('Join video call', (string) $conference['LABEL']);
    }

    function testShouldKeepTheOpenPaasPropertyForBackwardCompatibility() {
        $vCal = $this->readEvent([
            'X-OPENPAAS-VIDEOCONFERENCE;VALUE=UNKNOWN:' . self::VIDEO_CONFERENCE_URL
        ]);

        VideoConferenceDecorator::decorate($vCal);

        $this->assertSame(self::VIDEO_CONFERENCE_URL, (string) $vCal->VEVENT->{'X-OPENPAAS-VIDEOCONFERENCE'});
    }

    function testShouldSerializeTheConferencePropertyAsRfc7986() {
        $vCal = $this->readEvent([
            'X-OPENPAAS-VIDEOCONFERENCE;VALUE=UNKNOWN:' . self::VIDEO_CONFERENCE_URL
        ]);

        VideoConferenceDecorator::decorate($vCal);
        $reparsed = Reader::read($vCal->serialize());

        $conference = $reparsed->VEVENT->CONFERENCE;
        $this->assertSame(self::VIDEO_CONFERENCE_URL, (string) $conference);
        $this->assertSame('URI', (string) $conference['VALUE']);
        $this->assertSame('AUDIO,VIDEO', (string) $conference['FEATURE']);
        $this->assertSame('Join video call', (string) $conference['LABEL']);
    }

    function testShouldNotModifyEventWithoutVideoConferenceLink() {
        $vCal = $this->readEvent([]);

        $this->assertFalse(VideoConferenceDecorator::decorate($vCal));
        $this->assertCount(0, $vCal->VEVENT->select('CONFERENCE'));
    }

    function testShouldNotTouchAConferenceSetByAClientWhenEventHasNoVideoConferenceLink() {
        $vCal = $this->readEvent([
            'CONFERENCE;VALUE=URI;FEATURE=AUDIO,VIDEO;LABEL=Join:https://teams.example.com/room'
        ]);

        $this->assertFalse(VideoConferenceDecorator::decorate($vCal));
        $this->assertSame('https://teams.example.com/room', (string) $vCal->VEVENT->CONFERENCE);
    }

    function testShouldNotDuplicateAnAlreadyExistingConference() {
        $vCal = $this->readEvent([
            'X-OPENPAAS-VIDEOCONFERENCE;VALUE=UNKNOWN:' . self::VIDEO_CONFERENCE_URL,
            'CONFERENCE;VALUE=URI;FEATURE=AUDIO,VIDEO;LABEL=Join video call:' . self::VIDEO_CONFERENCE_URL
        ]);

        $this->assertFalse(VideoConferenceDecorator::decorate($vCal));
        $this->assertCount(1, $vCal->VEVENT->select('CONFERENCE'));
    }

    function testShouldReplaceTheConferenceOfAnOutdatedVideoConferenceLink() {
        $vCal = $this->readEvent([
            'X-OPENPAAS-VIDEOCONFERENCE;VALUE=UNKNOWN:' . self::VIDEO_CONFERENCE_URL,
            'CONFERENCE;VALUE=URI;FEATURE=AUDIO,VIDEO;LABEL=Join video call:https://meet.example.com/old-room'
        ]);

        $this->assertTrue(VideoConferenceDecorator::decorate($vCal));

        $conferences = $vCal->VEVENT->select('CONFERENCE');
        $this->assertCount(1, $conferences);
        $this->assertSame(self::VIDEO_CONFERENCE_URL, (string) reset($conferences));
    }

    function testShouldRemoveOutdatedConferencesEvenWhenTheCurrentLinkIsAlreadyPresent() {
        $vCal = $this->readEvent([
            'X-OPENPAAS-VIDEOCONFERENCE;VALUE=UNKNOWN:' . self::VIDEO_CONFERENCE_URL,
            'CONFERENCE;VALUE=URI;FEATURE=AUDIO,VIDEO;LABEL=Join video call:https://meet.example.com/old-room',
            'CONFERENCE;VALUE=URI;FEATURE=AUDIO,VIDEO;LABEL=Join video call:' . self::VIDEO_CONFERENCE_URL
        ]);

        $this->assertTrue(VideoConferenceDecorator::decorate($vCal));

        $conferences = $vCal->VEVENT->select('CONFERENCE');
        $this->assertCount(1, $conferences);
        $this->assertSame(self::VIDEO_CONFERENCE_URL, (string) reset($conferences));
    }

    function testShouldRemoveTheConferenceWhenTheVideoConferenceLinkIsCleared() {
        $vCal = $this->readEvent([
            'X-OPENPAAS-VIDEOCONFERENCE;VALUE=UNKNOWN:',
            'CONFERENCE;VALUE=URI;FEATURE=AUDIO,VIDEO;LABEL=Join video call:' . self::VIDEO_CONFERENCE_URL
        ]);

        $this->assertTrue(VideoConferenceDecorator::decorate($vCal));
        $this->assertCount(0, $vCal->VEVENT->select('CONFERENCE'));
    }

    function testShouldKeepConferencesOfOtherFeaturesUntouched() {
        $vCal = $this->readEvent([
            'X-OPENPAAS-VIDEOCONFERENCE;VALUE=UNKNOWN:' . self::VIDEO_CONFERENCE_URL,
            'CONFERENCE;VALUE=URI;FEATURE=PHONE;LABEL=Dial in:tel:+33-123-456'
        ]);

        $this->assertTrue(VideoConferenceDecorator::decorate($vCal));

        $conferenceUris = array_map(fn($conference) => (string) $conference, $vCal->VEVENT->select('CONFERENCE'));
        $this->assertCount(2, $conferenceUris);
        $this->assertContains('tel:+33-123-456', $conferenceUris);
        $this->assertContains(self::VIDEO_CONFERENCE_URL, $conferenceUris);
    }

    function testShouldDecorateTheRecurrenceMasterAndItsOverriddenInstances() {
        $vCal = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:recurring-event
DTSTART:20260322T090000Z
DTEND:20260322T100000Z
RRULE:FREQ=DAILY;COUNT=3
SUMMARY:Daily meeting
X-OPENPAAS-VIDEOCONFERENCE;VALUE=UNKNOWN:https://meet.example.com/master-room
END:VEVENT
BEGIN:VEVENT
UID:recurring-event
RECURRENCE-ID:20260323T090000Z
DTSTART:20260323T090000Z
DTEND:20260323T100000Z
SUMMARY:Daily meeting
X-OPENPAAS-VIDEOCONFERENCE;VALUE=UNKNOWN:https://meet.example.com/instance-room
END:VEVENT
END:VCALENDAR
ICS
        );

        $this->assertTrue(VideoConferenceDecorator::decorate($vCal));

        $vevents = $vCal->select('VEVENT');
        $this->assertSame('https://meet.example.com/master-room', (string) $vevents[0]->CONFERENCE);
        $this->assertSame('https://meet.example.com/instance-room', (string) $vevents[1]->CONFERENCE);
    }

    function testShouldNotAddAConferenceToAnOverriddenInstanceWithoutItsOwnVideoConferenceLink() {
        $vCal = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:recurring-event
DTSTART:20260322T090000Z
DTEND:20260322T100000Z
RRULE:FREQ=DAILY;COUNT=3
SUMMARY:Daily meeting
END:VEVENT
BEGIN:VEVENT
UID:recurring-event
RECURRENCE-ID:20260323T090000Z
DTSTART:20260323T110000Z
DTEND:20260323T120000Z
SUMMARY:Daily meeting
X-OPENPAAS-VIDEOCONFERENCE;VALUE=UNKNOWN:https://meet.example.com/instance-room
END:VEVENT
END:VCALENDAR
ICS
        );

        $this->assertTrue(VideoConferenceDecorator::decorate($vCal));

        $vevents = $vCal->select('VEVENT');
        $this->assertCount(0, $vevents[0]->select('CONFERENCE'));
        $this->assertSame('https://meet.example.com/instance-room', (string) $vevents[1]->CONFERENCE);
    }

    function testShouldIgnoreCalendarObjectsWithoutEvent() {
        $vCal = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VTODO
UID:a-todo
SUMMARY:Something to do
END:VTODO
END:VCALENDAR
ICS
        );

        $this->assertFalse(VideoConferenceDecorator::decorate($vCal));
    }

    private function readEvent(array $extraProperties): VCalendar {
        $lines = array_merge([
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'BEGIN:VEVENT',
            'UID:simple-event',
            'DTSTART:20260322T090000Z',
            'DTEND:20260322T100000Z',
            'SUMMARY:Meeting'
        ], $extraProperties, [
            'END:VEVENT',
            'END:VCALENDAR'
        ]);

        return Reader::read(implode("\r\n", $lines));
    }
}
