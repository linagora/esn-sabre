<?php

namespace ESN\CalDAV\Schedule;

use Sabre\VObject\Reader;

require_once ESN_TEST_BASE. '/CalDAV/Schedule/IMipPluginTestBase.php';

class IMipPluginStandardEventWithTZTest extends IMipPluginTestBase {
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
            'BEGIN:VTIMEZONE',
            'TZID:Asia/Jakarta',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0700',
            'TZOFFSETTO:+0700',
            'TZNAME:WIB',
            'DTSTART:19700101T000000',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART;TZID=Asia/Jakarta:20201028T170000',
            'DTEND;TZID=Asia/Jakarta:20201028T173000',
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
        $itipMessage->hasChange = true;
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
            'BEGIN:VTIMEZONE',
            'TZID:Asia/Jakarta',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0700',
            'TZOFFSETTO:+0700',
            'TZNAME:WIB',
            'DTSTART:19700101T000000',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART;TZID=Asia/Jakarta:20201028T170000',
            'DTEND;TZID=Asia/Jakarta:20201028T173000',
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
            'BEGIN:VTIMEZONE',
            'TZID:Asia/Jakarta',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0700',
            'TZOFFSETTO:+0700',
            'TZNAME:WIB',
            'DTSTART:19700101T000000',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART;TZID=Asia/Jakarta:20201028T170000',
            'DTEND;TZID=Asia/Jakarta:20201028T173000',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201025T145516Z',
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
        $itipMessage->hasChange = true;
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
            'BEGIN:VTIMEZONE',
            'TZID:Asia/Jakarta',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0700',
            'TZOFFSETTO:+0700',
            'TZNAME:WIB',
            'DTSTART:19700101T000000',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART;TZID=Asia/Jakarta:20201028T170000',
            'DTEND;TZID=Asia/Jakarta:20201028T173000',
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
            'BEGIN:VTIMEZONE',
            'TZID:Asia/Jakarta',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0700',
            'TZOFFSETTO:+0700',
            'TZNAME:WIB',
            'DTSTART:19700101T000000',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART;TZID=Asia/Jakarta:20201029T170000',
            'DTEND;TZID=Asia/Jakarta:20201029T173000',
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
        $itipMessage->hasChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher($itipMessage, false)));

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
            'BEGIN:VTIMEZONE',
            'TZID:Asia/Jakarta',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0700',
            'TZOFFSETTO:+0700',
            'TZNAME:WIB',
            'DTSTART:19700101T000000',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART;TZID=Asia/Jakarta:20201028T170000',
            'DTEND;TZID=Asia/Jakarta:20201028T173000',
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
            'BEGIN:VTIMEZONE',
            'TZID:Asia/Jakarta',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0700',
            'TZOFFSETTO:+0700',
            'TZNAME:WIB',
            'DTSTART:19700101T000000',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART;TZID=Asia/Jakarta:20201028T170000',
            'DTEND;TZID=Asia/Jakarta:20201028T173000',
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
        $itipMessage->hasChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher($itipMessage, false)));

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }
}
