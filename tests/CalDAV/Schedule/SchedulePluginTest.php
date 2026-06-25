<?php

namespace ESN\CalDAV\Schedule;

use ESN\CalDAV\Schedule\Exception\ForbiddenAttendeeSchedulingObjectChange;
use Sabre\DAV\Server;
use Sabre\VObject\ITip\Message;
use Sabre\VObject\Reader;

#[\AllowDynamicProperties]
class SchedulePluginTest extends \PHPUnit\Framework\TestCase {
    private $plugin;

    function setUp(): void {
        $server = new Server();

        $this->plugin = new Plugin();
        $this->plugin->initialize($server);
    }

    function testDeliverShouldSetSequenceTo0WhenNotPresent() {
        $message = $this->newItipMessage('');

        $this->plugin->deliver($message);

        $this->assertEquals('0', $message->message->VEVENT->SEQUENCE->getValue());
    }

    function testDeliverShouldNotSetSequenceWhen0() {
        $message = $this->newItipMessage('0');

        $this->plugin->deliver($message);

        $this->assertEquals('0', $message->message->VEVENT->SEQUENCE->getValue());
    }

    function testDeliverShouldNotSetSequenceWhenPresent() {
        $message = $this->newItipMessage('1');

        $this->plugin->deliver($message);

        $this->assertEquals('1', $message->message->VEVENT->SEQUENCE->getValue());
    }

    function testShouldDetectPubliclyCreatedWhenChairOrganizerNeedsAction() {
        $vcalendar = Reader::read($this->newCalendarWithOrganizerChairPartstat('NEEDS-ACTION', true));

        $shouldSkip = PublicAgendaScheduleUtils::isPubliclyCreatedAndChairOrganizerNotAccepted($vcalendar);

        $this->assertTrue($shouldSkip);
    }

    function testShouldNotSkipWhenChairOrganizerAcceptedEvenIfPubliclyCreated() {
        $vcalendar = Reader::read($this->newCalendarWithOrganizerChairPartstat('ACCEPTED', true));

        $shouldSkip = PublicAgendaScheduleUtils::isPubliclyCreatedAndChairOrganizerNotAccepted($vcalendar);

        $this->assertFalse($shouldSkip);
    }

    function testDeliverShouldNotCrashWhenRecipientIsNull() {
        $message = $this->newItipMessage('1');
        $message->recipient = null;

        $this->plugin->deliver($message);

        $this->assertSame('', $message->recipient);
    }

    function testShouldEnableEmailVALARMRecipientSchedulingByDefault() {
        $this->withEmailValarmRecipientSchedulingEnv(null, function () {
            $this->assertTrue($this->invokeShouldEnableEmailValarmRecipientScheduling());
        });
    }

    function testShouldAllowDisablingEmailVALARMRecipientScheduling() {
        $this->withEmailValarmRecipientSchedulingEnv('false', function () {
            $this->assertFalse($this->invokeShouldEnableEmailValarmRecipientScheduling());
        });
    }

    function testShouldNotSkipMasterRequestWhenBrokerProjectsExdateForRecipientRemoval() {
        $oldObject = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-300
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
RRULE:FREQ=DAILY;COUNT=5
SUMMARY:Recurring Meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:bob@example.org
ATTENDEE:mailto:cedric@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $newObject = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-300
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
RRULE:FREQ=DAILY;COUNT=5
SUMMARY:Recurring Meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:bob@example.org
ATTENDEE:mailto:cedric@example.org
END:VEVENT
BEGIN:VEVENT
UID:event-300
RECURRENCE-ID:20351006T090000Z
DTSTART:20351006T090000Z
DTEND:20351006T100000Z
SUMMARY:Recurring Meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:bob@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $message = new Message();
        $message->method = 'REQUEST';
        $message->recipient = 'mailto:cedric@example.org';
        $message->message = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
METHOD:REQUEST
BEGIN:VEVENT
UID:event-300
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
RRULE:FREQ=DAILY;COUNT=5
EXDATE:20351006T090000Z
SUMMARY:Recurring Meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:bob@example.org
ATTENDEE:mailto:cedric@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $shouldSkip = $this->invokeShouldSkipUnchangedOccurrence($message, $oldObject, $newObject);
        $this->assertFalse($shouldSkip);
    }

    function testShouldSkipUnchangedOccurrenceForRecurringRequest() {
        $oldObject = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-152
DTSTART:20260322T090000Z
DTEND:20260322T100000Z
RRULE:FREQ=DAILY;COUNT=3
SUMMARY:Daily meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:bob@example.org
END:VEVENT
BEGIN:VEVENT
UID:event-152
RECURRENCE-ID:20260323T090000Z
DTSTART:20260323T090000Z
DTEND:20260323T100000Z
SUMMARY:Daily meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:bob@example.org
ATTENDEE:mailto:cedric@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $newObject = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-152
DTSTART:20260322T090000Z
DTEND:20260322T100000Z
RRULE:FREQ=DAILY;COUNT=3
SUMMARY:Daily meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:bob@example.org
END:VEVENT
BEGIN:VEVENT
UID:event-152
RECURRENCE-ID:20260323T090000Z
DTSTART:20260323T090000Z
DTEND:20260323T100000Z
SUMMARY:Daily meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:bob@example.org
ATTENDEE:mailto:cedric@example.org
END:VEVENT
BEGIN:VEVENT
UID:event-152
RECURRENCE-ID:20260324T090000Z
DTSTART:20260324T090000Z
DTEND:20260324T100000Z
SUMMARY:Updated instance (day 3)
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:bob@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $message = new Message();
        $message->method = 'REQUEST';
        $message->recipient = 'mailto:cedric@example.org';
        $message->message = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
METHOD:REQUEST
BEGIN:VEVENT
UID:event-152
RECURRENCE-ID:20260323T090000Z
DTSTART:20260323T090000Z
DTEND:20260323T100000Z
SUMMARY:Daily meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:bob@example.org
ATTENDEE:mailto:cedric@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $shouldSkip = $this->invokeShouldSkipUnchangedOccurrence($message, $oldObject, $newObject);
        $this->assertTrue($shouldSkip);
    }

    function testShouldCountAttendeesForSingleEvent() {
        $calendar = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-single-count
DTSTART:20260322T090000Z
DTEND:20260322T100000Z
SUMMARY:Single meeting
ATTENDEE:mailto:alice@example.org
ATTENDEE:mailto:bob@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $this->assertSame(2, $this->invokeCountEventAttendees($calendar));
    }

    function testShouldCountOnlyMasterAttendeesForRecurringEvent() {
        $calendar = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-recurring-count
DTSTART:20260322T090000Z
DTEND:20260322T100000Z
RRULE:FREQ=DAILY;COUNT=3
SUMMARY:Recurring meeting
ATTENDEE:mailto:alice@example.org
ATTENDEE:mailto:bob@example.org
END:VEVENT
BEGIN:VEVENT
UID:event-recurring-count
RECURRENCE-ID:20260323T090000Z
DTSTART:20260323T090000Z
DTEND:20260323T100000Z
SUMMARY:Recurring meeting override
ATTENDEE:mailto:alice@example.org
ATTENDEE:mailto:bob@example.org
ATTENDEE:mailto:cedric@example.org
ATTENDEE:mailto:dina@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $this->assertSame(2, $this->invokeCountEventAttendees($calendar));
    }

    function testShouldDeduplicateMasterAttendeesByNormalizedValue() {
        $calendar = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-dedupe-count
DTSTART:20260322T090000Z
DTEND:20260322T100000Z
SUMMARY:Single meeting
ATTENDEE:mailto:alice@example.org
ATTENDEE:MAILTO:ALICE@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $this->assertSame(1, $this->invokeCountEventAttendees($calendar));
    }

    function testShouldPreserveRecipientLocalPropertiesByDefault() {
        $oldData = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-local-properties
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Original meeting
CLASS:PRIVATE
TRANSP:TRANSPARENT
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT5M
DESCRIPTION:Local reminder
END:VALARM
END:VEVENT
END:VCALENDAR
ICS;

        $newObject = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-local-properties
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Updated meeting
CLASS:PUBLIC
TRANSP:OPAQUE
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT30M
DESCRIPTION:Organizer reminder
END:VALARM
END:VEVENT
END:VCALENDAR
ICS
        );

        $this->invokePreserveRecipientLocalProperties($oldData, $newObject);

        $this->assertEquals('Updated meeting', $newObject->VEVENT->SUMMARY->getValue());
        $this->assertEquals('PRIVATE', $newObject->VEVENT->CLASS->getValue());
        $this->assertEquals('TRANSPARENT', $newObject->VEVENT->TRANSP->getValue());
        $this->assertEquals('-PT5M', $newObject->VEVENT->VALARM->TRIGGER->getValue());
        $this->assertEquals('Local reminder', $newObject->VEVENT->VALARM->DESCRIPTION->getValue());
    }

    function testShouldReplaceOrganizerManagedEmailVALARMAndPreservePersonalEmailVALARM() {
        $this->withEmailValarmRecipientSchedulingEnv('true', function () {
            $oldData = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-email-alarm-merge
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Original meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
BEGIN:VALARM
UID:organizer-reminder@example.org
ACTION:EMAIL
ATTENDEE:mailto:alice@example.org
TRIGGER:-PT5M
DESCRIPTION:Organizer reminder
SUMMARY:Alarm notification
END:VALARM
BEGIN:VALARM
UID:alice-personal-reminder@example.org
ACTION:EMAIL
ATTENDEE:mailto:alias@example.org
TRIGGER:-PT10M
DESCRIPTION:Personal reminder
SUMMARY:Personal alarm notification
END:VALARM
END:VEVENT
END:VCALENDAR
ICS;

            $newObject = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-email-alarm-merge
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Updated meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
BEGIN:VALARM
UID:organizer-reminder@example.org
ACTION:EMAIL
ATTENDEE:mailto:alice@example.org
TRIGGER:-PT20M
DESCRIPTION:Organizer reminder
SUMMARY:Alarm notification
END:VALARM
END:VEVENT
END:VCALENDAR
ICS
            );

            $this->invokePreserveRecipientLocalProperties($oldData, $newObject);

            $alarms = $newObject->VEVENT->select('VALARM');
            $alarmsByUid = [];
            foreach ($alarms as $alarm) {
                $alarmsByUid[$alarm->UID->getValue()] = $alarm;
            }

            $this->assertCount(2, $alarms);
            $this->assertEquals('-PT20M', $alarmsByUid['organizer-reminder@example.org']->TRIGGER->getValue());
            $this->assertEquals('mailto:alice@example.org', $alarmsByUid['organizer-reminder@example.org']->ATTENDEE->getNormalizedValue());
            $this->assertEquals('-PT10M', $alarmsByUid['alice-personal-reminder@example.org']->TRIGGER->getValue());
            $this->assertEquals('mailto:alias@example.org', $alarmsByUid['alice-personal-reminder@example.org']->ATTENDEE->getNormalizedValue());
        });
    }

    function testShouldRemoveOrganizerManagedEmailVALARMWhenNoLongerProjected() {
        $this->withEmailValarmRecipientSchedulingEnv('true', function () {
            $oldData = <<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-email-alarm-removal
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Original meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
BEGIN:VALARM
UID:organizer-reminder@example.org
ACTION:EMAIL
ATTENDEE:mailto:alice@example.org
TRIGGER:-PT5M
DESCRIPTION:Organizer reminder
SUMMARY:Alarm notification
END:VALARM
END:VEVENT
END:VCALENDAR
ICS;

            $newObject = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-email-alarm-removal
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Updated meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
END:VEVENT
END:VCALENDAR
ICS
            );

            $this->invokePreserveRecipientLocalProperties($oldData, $newObject);

            $this->assertCount(0, $newObject->VEVENT->select('VALARM'));
        });
    }

    function testShouldGenerateMissingVALARMUIDsAndPreserveExistingVALARMUIDs() {
        $calendar = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-valarm-uid
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Meeting with reminders
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT5M
DESCRIPTION:Missing UID reminder
END:VALARM
BEGIN:VALARM
UID:client-reminder
ACTION:EMAIL
ATTENDEE:mailto:alice@example.org
TRIGGER:-PT10M
DESCRIPTION:Client UID reminder
SUMMARY:Alarm notification
END:VALARM
END:VEVENT
END:VCALENDAR
ICS
        );

        $modified = $this->invokeEnsureValarmUids($calendar);

        $alarms = $calendar->VEVENT->select('VALARM');
        $this->assertTrue($modified);
        $this->assertMatchesRegularExpression('/^alarm-[0-9a-fA-F-]{36}$/', $alarms[0]->UID->getValue());
        $this->assertEquals('client-reminder', $alarms[1]->UID->getValue());
    }

    function testShouldRejectForbiddenAttendeeSchedulingObjectChanges() {
        $forbiddenChanges = [
            'DTSTART' => [
                'originalLine' => 'DTSTART:20351005T090000Z',
                'updatedLine' => 'DTSTART:20351005T093000Z',
            ],
            'DTEND' => [
                'originalLine' => 'DTEND:20351005T100000Z',
                'updatedLine' => 'DTEND:20351005T110000Z',
            ],
            'LOCATION' => [
                'originalLine' => 'LOCATION:Room A',
                'updatedLine' => 'LOCATION:Room B',
            ],
            'SUMMARY' => [
                'originalLine' => 'SUMMARY:Original meeting',
                'updatedLine' => 'SUMMARY:Updated by attendee',
            ],
            'ORGANIZER' => [
                'originalLine' => 'ORGANIZER:mailto:bob@example.org',
                'updatedLine' => 'ORGANIZER:mailto:mallory@example.org',
            ],
        ];

        $this->withRfc6638EnforcementEnv(null, function () use ($forbiddenChanges) {
            foreach ($forbiddenChanges as $propertyName => $change) {
                $oldObject = Reader::read($this->newSchedulingObject());
                $newObject = Reader::read(str_replace(
                    $change['originalLine'],
                    $change['updatedLine'],
                    $this->newSchedulingObject()
                ));

                try {
                    $this->invokeAssertAllowedAttendeeSchedulingObjectChange($oldObject, $newObject, ['mailto:alice@example.org']);
                    $this->fail($propertyName . ' should be rejected for attendee scheduling object changes');
                } catch (ForbiddenAttendeeSchedulingObjectChange $e) {
                    $this->assertStringContainsString($propertyName, $e->getMessage());
                }
            }
        });
    }

    function testShouldAllowForbiddenAttendeeSchedulingObjectChangesWhenRfc6638EnforcementDisabled() {
        $oldObject = Reader::read($this->newSchedulingObject());
        $newObject = Reader::read(str_replace(
            'SUMMARY:Original meeting',
            'SUMMARY:Updated while enforcement disabled',
            $this->newSchedulingObject()
        ));

        $this->withRfc6638EnforcementEnv('false', function () use ($oldObject, $newObject) {
            $this->invokeAssertAllowedAttendeeSchedulingObjectChange($oldObject, $newObject, ['mailto:alice@example.org']);
        });

        $this->assertEquals('Updated while enforcement disabled', $newObject->VEVENT->SUMMARY->getValue());
    }

    function testShouldAllowOrganizerLessSchedulingObjectChanges() {
        $oldObject = Reader::read($this->newOrganizerLessSchedulingObject());
        $newObject = Reader::read(str_replace(
            [
                'DTSTART:20351005T090000Z',
                'DTEND:20351005T100000Z',
                'LOCATION:Room A',
                'SUMMARY:Original meeting',
            ],
            [
                'DTSTART:20351005T093000Z',
                'DTEND:20351005T110000Z',
                'LOCATION:Room B',
                'SUMMARY:Updated organizer-less meeting',
            ],
            $this->newOrganizerLessSchedulingObject()
        ));

        $this->withRfc6638EnforcementEnv(null, function () use ($oldObject, $newObject) {
            $this->invokeAssertAllowedAttendeeSchedulingObjectChange($oldObject, $newObject, ['mailto:alice@example.org']);
        });

        $this->assertEquals('20351005T093000Z', $newObject->VEVENT->DTSTART->getValue());
        $this->assertEquals('20351005T110000Z', $newObject->VEVENT->DTEND->getValue());
        $this->assertEquals('Room B', $newObject->VEVENT->LOCATION->getValue());
        $this->assertEquals('Updated organizer-less meeting', $newObject->VEVENT->SUMMARY->getValue());
    }

    function testShouldAllowOrganizerSchedulingObjectChanges() {
        $oldObject = Reader::read($this->newSchedulingObject());
        $newObject = Reader::read(str_replace(
            'SUMMARY:Original meeting',
            'SUMMARY:Updated by organizer',
            $this->newSchedulingObject()
        ));

        $this->invokeAssertAllowedAttendeeSchedulingObjectChange($oldObject, $newObject, ['mailto:bob@example.org']);

        $this->assertEquals('Updated by organizer', $newObject->VEVENT->SUMMARY->getValue());
    }

    function testShouldAllowAttendeeLocalVALARMAndTRANSPChanges() {
        $oldObject = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-allowed-attendee-local-properties
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Original meeting
LOCATION:Room A
TRANSP:OPAQUE
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT15M
DESCRIPTION:Original reminder
END:VALARM
END:VEVENT
END:VCALENDAR
ICS
        );
        $newObject = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-allowed-attendee-local-properties
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Original meeting
LOCATION:Room A
TRANSP:TRANSPARENT
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT5M
DESCRIPTION:Local reminder
END:VALARM
END:VEVENT
END:VCALENDAR
ICS
        );

        $this->invokeAssertAllowedAttendeeSchedulingObjectChange($oldObject, $newObject, ['mailto:alice@example.org']);

        $this->assertEquals('TRANSPARENT', $newObject->VEVENT->TRANSP->getValue());
        $this->assertEquals('-PT5M', $newObject->VEVENT->VALARM->TRIGGER->getValue());
        $this->assertEquals('Local reminder', $newObject->VEVENT->VALARM->DESCRIPTION->getValue());
    }

    function testShouldAllowAttendeePersonalEmailVALARMWithAlias() {
        $oldObject = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-allowed-attendee-personal-email-alarm
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Original meeting
LOCATION:Room A
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
END:VEVENT
END:VCALENDAR
ICS
        );
        $newObject = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-allowed-attendee-personal-email-alarm
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Original meeting
LOCATION:Room A
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
BEGIN:VALARM
ACTION:EMAIL
ATTENDEE:mailto:alias@example.org
TRIGGER:-PT5M
DESCRIPTION:Local reminder
SUMMARY:Alarm notification
END:VALARM
END:VEVENT
END:VCALENDAR
ICS
        );

        $this->invokeAssertAllowedAttendeeSchedulingObjectChange($oldObject, $newObject, ['mailto:alice@example.org']);

        $this->assertEquals('-PT5M', $newObject->VEVENT->VALARM->TRIGGER->getValue());
        $this->assertEquals('mailto:alias@example.org', $newObject->VEVENT->VALARM->ATTENDEE->getNormalizedValue());
    }

    function testShouldFilterEmailVALARMsByRequestRecipient() {
        $this->withEmailValarmRecipientSchedulingEnv('true', function () {
            // Given an outgoing request with recipient-specific email alarms and a display alarm
            $sourceCalendar = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
METHOD:REQUEST
BEGIN:VEVENT
UID:event-recipient-specific-alarms
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
ATTENDEE:mailto:bob@example.org
BEGIN:VALARM
ACTION:EMAIL
ATTENDEE:MAILTO:ALICE@EXAMPLE.ORG
ATTENDEE:mailto:alias@example.org
TRIGGER:-PT10M
DESCRIPTION:Alice reminder
SUMMARY:Alarm notification
END:VALARM
BEGIN:VALARM
ACTION:EMAIL
ATTENDEE:mailto:bob@example.org
TRIGGER:-PT5M
DESCRIPTION:Bob reminder
SUMMARY:Alarm notification
END:VALARM
BEGIN:VALARM
ACTION:DISPLAY
TRIGGER:-PT15M
DESCRIPTION:Display reminder
END:VALARM
END:VEVENT
END:VCALENDAR
ICS
            );
            $message = new Message();
            $message->recipient = 'mailto:alice@example.org';
            $message->message = clone $sourceCalendar;
            $message->message->VEVENT->select('VALARM')[1]->ATTENDEE = 'mailto:alice@example.org';

            // When the broker-rewritten request is sanitized for Alice from the organizer source
            $this->invokeSanitizeOutgoingRequestMessage($message, $sourceCalendar);

            // Then Alice's email alarm and the non-email alarm remain, while Bob's email alarm is removed
            $alarms = $message->message->VEVENT->select('VALARM');
            $this->assertCount(2, $alarms);
            $this->assertEqualsCanonicalizing(['-PT10M', '-PT15M'], array_values(array_map(
                fn ($alarm) => $alarm->TRIGGER->getValue(),
                $alarms
            )));
            $emailAlarms = array_values(array_filter(
                $alarms,
                fn ($alarm) => isset($alarm->ACTION) && strcasecmp($alarm->ACTION->getValue(), 'EMAIL') === 0
            ));
            $this->assertCount(1, $emailAlarms);
            $this->assertSame(
                ['mailto:alice@example.org'],
                array_values(array_map(
                    fn ($attendee) => $attendee->getNormalizedValue(),
                    $emailAlarms[0]->select('ATTENDEE')
                ))
            );
        });
    }

    function testForbiddenAttendeeSchedulingObjectChangeShouldSerializeCalDAVPrecondition() {
        $server = new Server();
        $document = new \DOMDocument('1.0', 'utf-8');
        $errorNode = $document->createElementNS('DAV:', 'd:error');
        $document->appendChild($errorNode);

        (new ForbiddenAttendeeSchedulingObjectChange('SUMMARY'))->serialize($server, $errorNode);

        $this->assertSame(
            1,
            $errorNode->getElementsByTagNameNS(
                \Sabre\CalDAV\Schedule\Plugin::NS_CALDAV,
                'allowed-attendee-scheduling-object-change'
            )->length
        );
    }

    private function newItipMessage($sequence) {
        $message = new Message();
        $ical = "BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
CREATED:20120313T142342Z
UID:event1
DTEND;TZID=Europe/Berlin:20120227T000000
TRANSP:OPAQUE
SUMMARY:Monday 0h
DTSTART;TZID=Europe/Berlin:20120227T000000
DTSTAMP:20120313T142416Z
SEQUENCE:$sequence
END:VEVENT
END:VCALENDAR
";

        $message->component = 'VEVENT';
        $message->uid = 'UID';
        $message->sequence = $sequence;
        $message->method = 'REQUEST';
        $message->sender = 'mailto:a@a.com';
        $message->recipient = 'mailto:b@b.com';
        $message->message = Reader::read($ical);

        return $message;
    }

    private function newCalendarWithOrganizerChairPartstat($partstat, $publiclyCreated) {
        $publiclyCreatedValue = $publiclyCreated ? 'true' : 'false';

        return "BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:test-publicly-created
DTSTART:20260227T000000Z
DTEND:20260227T003000Z
SUMMARY:Test event
X-PUBLICLY-CREATED:$publiclyCreatedValue
ORGANIZER:mailto:alice@example.org
ATTENDEE;PARTSTAT=$partstat;ROLE=CHAIR:mailto:alice@example.org
ATTENDEE;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT:mailto:bob@example.org
END:VEVENT
END:VCALENDAR
";
    }

    private function invokeShouldSkipUnchangedOccurrence($message, $oldObject, $newObject) {
        $method = new \ReflectionMethod(Plugin::class, 'shouldSkipUnchangedOccurrence');
        $method->setAccessible(true);

        return $method->invoke($this->plugin, $message, $oldObject, $newObject);
    }

    private function invokeCountEventAttendees($calendarObject) {
        $method = new \ReflectionMethod(Plugin::class, 'countEventAttendees');
        $method->setAccessible(true);

        return $method->invoke($this->plugin, $calendarObject);
    }

    private function invokePreserveRecipientLocalProperties($oldICalendarData, $newObject) {
        $method = new \ReflectionMethod(Plugin::class, 'preserveRecipientLocalProperties');
        $method->setAccessible(true);

        $method->invoke($this->plugin, $oldICalendarData, $newObject);
    }

    private function invokeSanitizeOutgoingRequestMessage(Message $message, $sourceCalendar): void {
        $method = new \ReflectionMethod(Plugin::class, 'sanitizeOutgoingRequestMessage');
        $method->setAccessible(true);

        $method->invoke($this->plugin, $message, $sourceCalendar);
    }

    private function invokeEnsureValarmUids($calendarObject): bool {
        $method = new \ReflectionMethod(Plugin::class, 'ensureValarmUids');
        $method->setAccessible(true);

        return $method->invoke($this->plugin, $calendarObject);
    }

    private function invokeShouldEnableEmailValarmRecipientScheduling(): bool {
        $method = new \ReflectionMethod(Plugin::class, 'shouldEnableEmailValarmRecipientScheduling');
        $method->setAccessible(true);

        return $method->invoke($this->plugin);
    }

    private function invokeAssertAllowedAttendeeSchedulingObjectChange($oldObject, $newObject, array $addresses) {
        $method = new \ReflectionMethod(Plugin::class, 'assertAllowedAttendeeSchedulingObjectChange');
        $method->setAccessible(true);

        $method->invoke($this->plugin, $oldObject, $newObject, $addresses);
    }

    private function withRfc6638EnforcementEnv(?string $value, callable $callback) {
        $previousValue = getenv('SABRE_ENFORCE_RFC_6638');

        try {
            if ($value === null) {
                putenv('SABRE_ENFORCE_RFC_6638');
            } else {
                putenv('SABRE_ENFORCE_RFC_6638=' . $value);
            }

            return $callback();
        } finally {
            if ($previousValue === false) {
                putenv('SABRE_ENFORCE_RFC_6638');
            } else {
                putenv('SABRE_ENFORCE_RFC_6638=' . $previousValue);
            }
        }
    }

    private function withEmailValarmRecipientSchedulingEnv(?string $value, callable $callback) {
        $previousValue = getenv('SABRE_EMAIL_VALARM_RECIPIENT_SCHEDULING');

        try {
            if ($value === null) {
                putenv('SABRE_EMAIL_VALARM_RECIPIENT_SCHEDULING');
            } else {
                putenv('SABRE_EMAIL_VALARM_RECIPIENT_SCHEDULING=' . $value);
            }

            return $callback();
        } finally {
            if ($previousValue === false) {
                putenv('SABRE_EMAIL_VALARM_RECIPIENT_SCHEDULING');
            } else {
                putenv('SABRE_EMAIL_VALARM_RECIPIENT_SCHEDULING=' . $previousValue);
            }
        }
    }

    private function newSchedulingObject(): string {
        return "BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-forbidden-attendee-change
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Original meeting
LOCATION:Room A
ORGANIZER:mailto:bob@example.org
ATTENDEE:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
END:VEVENT
END:VCALENDAR
";
    }

    private function newOrganizerLessSchedulingObject(): string {
        return "BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:event-organizer-less-change
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Original meeting
LOCATION:Room A
ATTENDEE:mailto:bob@example.org
ATTENDEE:mailto:alice@example.org
END:VEVENT
END:VCALENDAR
";
    }

    function testRecipientHasDeclinedInMessageReturnsTrueWhenDeclined() {
        $message = new Message();
        $message->method = 'REQUEST';
        $message->recipient = 'mailto:alice@example.org';
        $message->message = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
METHOD:REQUEST
BEGIN:VEVENT
UID:event-declined
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.org
ATTENDEE;PARTSTAT=DECLINED:mailto:alice@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $this->assertTrue($this->invokeRecipientHasDeclinedInMessage($message));
    }

    function testRecipientHasDeclinedInMessageReturnsFalseWhenNeedsAction() {
        $message = new Message();
        $message->method = 'REQUEST';
        $message->recipient = 'mailto:alice@example.org';
        $message->message = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
METHOD:REQUEST
BEGIN:VEVENT
UID:event-pending
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.org
ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:alice@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $this->assertFalse($this->invokeRecipientHasDeclinedInMessage($message));
    }

    function testRecipientHasDeclinedInMessageReturnsFalseWhenAccepted() {
        $message = new Message();
        $message->method = 'REQUEST';
        $message->recipient = 'mailto:alice@example.org';
        $message->message = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
METHOD:REQUEST
BEGIN:VEVENT
UID:event-accepted
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
SUMMARY:Meeting
ORGANIZER:mailto:bob@example.org
ATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.org
ATTENDEE;PARTSTAT=ACCEPTED:mailto:alice@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $this->assertFalse($this->invokeRecipientHasDeclinedInMessage($message));
    }

    function testRecipientHasDeclinedInMessageIgnoresOverrideVeventForRecurring() {
        // Recipient is DECLINED in the override but not present in the master — should return false
        // (override-only check; we only guard on master)
        $message = new Message();
        $message->method = 'REQUEST';
        $message->recipient = 'mailto:alice@example.org';
        $message->message = Reader::read(<<<'ICS'
BEGIN:VCALENDAR
VERSION:2.0
METHOD:REQUEST
BEGIN:VEVENT
UID:event-recurring
DTSTART:20351005T090000Z
DTEND:20351005T100000Z
RRULE:FREQ=DAILY;COUNT=3
SUMMARY:Recurring
ORGANIZER:mailto:bob@example.org
ATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.org
ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:alice@example.org
END:VEVENT
BEGIN:VEVENT
UID:event-recurring
RECURRENCE-ID:20351006T090000Z
DTSTART:20351006T090000Z
DTEND:20351006T100000Z
SUMMARY:Recurring
ORGANIZER:mailto:bob@example.org
ATTENDEE;PARTSTAT=ACCEPTED:mailto:bob@example.org
ATTENDEE;PARTSTAT=DECLINED:mailto:alice@example.org
END:VEVENT
END:VCALENDAR
ICS
        );

        $this->assertFalse($this->invokeRecipientHasDeclinedInMessage($message));
    }

    private function invokeRecipientHasDeclinedInMessage(Message $message): bool {
        $method = new \ReflectionMethod(Plugin::class, 'recipientHasDeclinedInMessage');
        $method->setAccessible(true);

        return $method->invoke($this->plugin, $message);
    }
}
