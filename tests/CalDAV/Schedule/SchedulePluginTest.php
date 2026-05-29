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

    private function invokeAssertAllowedAttendeeSchedulingObjectChange($oldObject, $newObject, array $addresses) {
        $method = new \ReflectionMethod(Plugin::class, 'assertAllowedAttendeeSchedulingObjectChange');
        $method->setAccessible(true);

        $method->invoke($this->plugin, $oldObject, $newObject, $addresses);
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
