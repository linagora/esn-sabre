<?php

namespace ESN\CalDAV\Schedule;

use Sabre\VObject\Reader;

require_once ESN_TEST_BASE. '/CalDAV/Schedule/IMipPluginTestBase.php';

class IMipPluginStandardEventTest extends IMipPluginTestBase {
    function testCreateEventWithAttendee()
    {
        $plugin = $this->getPlugin();
        $plugin->setNewEvent(true);

        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART:'. $this->afterCurrentDate.'T170000Z',
            'DTEND:'. $this->afterCurrentDate.'T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201029T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $itipMessage = new \Sabre\VObject\ITip\Message();
        $itipMessage->uid = 'ab9e450a-3080-4274-affd-fdd0e9eefdcc';
        $itipMessage->component = 'VEVENT';
        $itipMessage->method = 'REQUEST';
        $itipMessage->sequence = null;
        $itipMessage->sender = 'mailto:' . $this->user1Email;
        $itipMessage->recipient = 'mailto:' . $this->user2Email;
        $itipMessage->recipientName = 'John2 Doe2';
        $itipMessage->scheduleStatus = 'null';
        $itipMessage->significantChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher($itipMessage, true)));

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    function testAddAttendeeToAnEvent()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART:'. $this->afterCurrentDate.'T170000Z',
            'DTEND:'. $this->afterCurrentDate.'T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);

        $plugin->setFormerEventICal($user1ExistingEvent);
        $plugin->setNewEvent(false);

        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART:'. $this->afterCurrentDate.'T170000Z',
            'DTEND:'. $this->afterCurrentDate.'T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $itipMessage = new \Sabre\VObject\ITip\Message();
        $itipMessage->uid = 'ab9e450a-3080-4274-affd-fdd0e9eefdcc';
        $itipMessage->component = 'VEVENT';
        $itipMessage->method = 'REQUEST';
        $itipMessage->sequence = 1;
        $itipMessage->sender = 'mailto:' . $this->user1Email;
        $itipMessage->recipient = 'mailto:' . $this->user2Email;
        $itipMessage->scheduleStatus = null;
        $itipMessage->significantChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        $requestCalled = false;

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher($itipMessage, true)));

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    function testModifyEventWithAttendee()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);
        $plugin->setFormerEventICal($user1ExistingEvent);
        $plugin->setNewEvent(false);

        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART:'. $this->afterCurrentDate.'T170000Z',
            'DTEND:'. $this->afterCurrentDate.'T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201027T182723Z',
            'SEQUENCE:1',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $itipMessage = new \Sabre\VObject\ITip\Message();
        $itipMessage->uid = 'ab9e450a-3080-4274-affd-fdd0e9eefdcc';
        $itipMessage->component = 'VEVENT';
        $itipMessage->method = 'REQUEST';
        $itipMessage->sequence = 1;
        $itipMessage->sender = 'mailto:' . $this->user1Email;
        $itipMessage->recipient = 'mailto:' . $this->user2Email;
        $itipMessage->scheduleStatus = null;
        $itipMessage->significantChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        $message = $this->getMessageForPublisher($itipMessage, false);

        $message['changes'] = [
            'dtstart' => [
                'previous' => [
                    'isAllDay' => false,
                    'date' => '2020-10-28 17:00:00.000000',
                    'timezone_type' => 3,
                    'timezone' => 'UTC'
                ],
                'current' => [
                    'isAllDay' => false,
                    'date' => $this->formattedAfterCurrentDate .' 17:00:00.000000',
                    'timezone_type' => 3,
                    'timezone' => 'UTC'
                ]
            ],
            'dtend' => [
                'previous' => [
                    'isAllDay' => false,
                    'date' => '2020-10-28 17:30:00.000000',
                    'timezone_type' => 3,
                    'timezone' => 'UTC'
                ],
                'current' => [
                    'isAllDay' => false,
                    'date' => $this->formattedAfterCurrentDate .' 17:30:00.000000',
                    'timezone_type' => 3,
                    'timezone' => 'UTC'
                ]
            ]
        ];

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($message));

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    function testCancelEventWithAttendee()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART:'. $this->afterCurrentDate.'T170000Z',
            'DTEND:'. $this->afterCurrentDate.'T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201029T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);
        $plugin->setNewEvent(false);

        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:CANCEL',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART:'. $this->afterCurrentDate.'T170000Z',
            'DTEND:'. $this->afterCurrentDate.'T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201029T182723Z',
            'SEQUENCE:1',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $itipMessage = new \Sabre\VObject\ITip\Message();
        $itipMessage->uid = 'ab9e450a-3080-4274-affd-fdd0e9eefdcc';
        $itipMessage->component = 'VEVENT';
        $itipMessage->method = 'CANCEL';
        $itipMessage->sequence = 1;
        $itipMessage->sender = 'mailto:' . $this->user1Email;
        $itipMessage->recipient = 'mailto:' . $this->user2Email;
        $itipMessage->scheduleStatus = null;
        $itipMessage->significantChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher($itipMessage, false)));

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    function testCounterMethod() {
        $plugin = $this->getPlugin();
        $plugin->setNewEvent(false);

        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:COUNTER',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART:'. $this->afterCurrentDate.'T170000Z',
            'DTEND:'. $this->afterCurrentDate.'T173000Z',
            'SUMMARY:Test',
            'COMMENT:Propose new time',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201029T182723Z',
            'SEQUENCE:1',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $itipMessage = new \Sabre\VObject\ITip\Message();
        $itipMessage->uid = 'ab9e450a-3080-4274-affd-fdd0e9eefdcc';
        $itipMessage->component = 'VEVENT';
        $itipMessage->method = 'COUNTER';
        $itipMessage->sequence = 1;
        $itipMessage->sender = 'mailto:' . $this->user1Email;
        $itipMessage->recipient = 'mailto:' . $this->user2Email;
        $itipMessage->scheduleStatus = null;
        $itipMessage->significantChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        $messageForPublisher = $this->getMessageForPublisher($itipMessage, false);
        $messageForPublisher['oldEvent'] = $plugin->getServer()->tree->getNodeForPath($messageForPublisher['eventPath'])->get();

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($messageForPublisher));

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    function testEventIsExpired()
    {
        $plugin = $this->getPlugin();
        $plugin->setNewEvent(true);

        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T170000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201029T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $itipMessage = new \Sabre\VObject\ITip\Message();
        $itipMessage->uid = 'ab9e450a-3080-4274-affd-fdd0e9eefdcc';
        $itipMessage->component = 'VEVENT';
        $itipMessage->method = 'REQUEST';
        $itipMessage->sequence = null;
        $itipMessage->sender = 'mailto:' . $this->user1Email;
        $itipMessage->recipient = 'mailto:' . $this->user2Email;
        $itipMessage->recipientName = 'John2 Doe2';
        $itipMessage->scheduleStatus = 'null';
        $itipMessage->significantChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        $this->amqpPublisher->expects($this->never())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher($itipMessage, true)));

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    /**
     * Test for issue #242: Handle malformed events without ORGANIZER
     *
     * When an event has ATTENDEE but no ORGANIZER (malformed), deleting it
     * should not cause a TypeError. The organizer should be inferred from
     * the calendar owner.
     */
    function testCancelEventWithoutOrganizer()
    {
        $plugin = $this->getPlugin();
        $plugin->setNewEvent(false);

        // Event with ATTENDEE but without ORGANIZER (malformed as per RFC 5545)
        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:CANCEL',
            'BEGIN:VEVENT',
            'UID:6feb0657-e890-47bc-a326-63b5859cf1fb',
            'DTSTART:'. $this->afterCurrentDate.'T093000Z',
            'DTEND:'. $this->afterCurrentDate.'T100000Z',
            'SUMMARY:Event without organizer',
            'ATTENDEE;PARTSTAT=ACCEPTED;RSVP=FALSE;ROLE=CHAIR;CUTYPE=INDIVIDUAL;CN=John2 Doe2:mailto:' . $this->user2Email,
            'DTSTAMP:20251211T182947Z',
            'SEQUENCE:2',
            'CLASS:PUBLIC',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $itipMessage = new \Sabre\VObject\ITip\Message();
        $itipMessage->uid = '6feb0657-e890-47bc-a326-63b5859cf1fb';
        $itipMessage->component = 'VEVENT';
        $itipMessage->method = 'CANCEL';
        $itipMessage->sequence = null;
        $itipMessage->sender = null;  // No ORGANIZER in event - this is the key issue
        $itipMessage->recipient = 'mailto:' . $this->user2Email;
        $itipMessage->recipientName = 'John2 Doe2';
        $itipMessage->scheduleStatus = 'null';
        $itipMessage->significantChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        // Before fix: this would throw TypeError in substr()
        // After fix: sender should be inferred from calendar owner
        $plugin->schedule($itipMessage);

        // Verify that sender was inferred (should be user1's email since it's their calendar)
        $this->assertNotNull($itipMessage->sender, 'Sender should be inferred from calendar owner');
        $this->assertStringContainsString('mailto:', $itipMessage->sender, 'Sender should be a mailto URI');
    }

    function testShouldPublishWhenPubliclyCreatedChairMovesFromNeedsActionToAccepted() {
        $plugin = $this->getPlugin();
        $plugin->setNewEvent(false);

        $uid = 'publicly-created-transition-accepted';
        $plugin->setFormerEventICal($this->newPubliclyCreatedEvent($uid, 'NEEDS-ACTION'));

        $itipMessage = $this->newPubliclyCreatedRequestMessage($uid, 'ACCEPTED');

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher($itipMessage, false)));

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    function testShouldNotPublishWhenPubliclyCreatedChairStaysNeedsAction() {
        $plugin = $this->getPlugin();
        $plugin->setNewEvent(false);

        $uid = 'publicly-created-no-transition';
        $plugin->setFormerEventICal($this->newPubliclyCreatedEvent($uid, 'NEEDS-ACTION'));

        $itipMessage = $this->newPubliclyCreatedRequestMessage($uid, 'NEEDS-ACTION');

        $this->amqpPublisher->expects($this->never())
            ->method('publish');

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    private function newPubliclyCreatedRequestMessage(string $uid, string $organizerChairPartstat): \Sabre\VObject\ITip\Message {
        $itipMessage = new \Sabre\VObject\ITip\Message();
        $itipMessage->uid = $uid;
        $itipMessage->component = 'VEVENT';
        $itipMessage->method = 'REQUEST';
        $itipMessage->sequence = 0;
        $itipMessage->sender = 'mailto:' . $this->user1Email;
        $itipMessage->recipient = 'mailto:' . $this->user2Email;
        $itipMessage->scheduleStatus = null;
        $itipMessage->significantChange = true;
        $itipMessage->message = Reader::read($this->newPubliclyCreatedEvent($uid, $organizerChairPartstat, true));

        return $itipMessage;
    }

    private function newPubliclyCreatedEvent(string $uid, string $organizerChairPartstat, bool $withMethod = false): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN'
        ];

        if ($withMethod) {
            $lines[] = 'METHOD:REQUEST';
        }

        $lines = array_merge($lines, [
            'BEGIN:VEVENT',
            'UID:' . $uid,
            'DTSTART:' . $this->afterCurrentDate . 'T170000Z',
            'DTEND:' . $this->afterCurrentDate . 'T173000Z',
            'SUMMARY:Publicly created event',
            'X-PUBLICLY-CREATED:true',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE;PARTSTAT=' . $organizerChairPartstat . ';ROLE=CHAIR:mailto:' . $this->user1Email,
            'ATTENDEE;PARTSTAT=NEEDS-ACTION;ROLE=REQ-PARTICIPANT:mailto:' . $this->user2Email,
            'DTSTAMP:20201029T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        return join("\r\n", $lines);
    }

}
