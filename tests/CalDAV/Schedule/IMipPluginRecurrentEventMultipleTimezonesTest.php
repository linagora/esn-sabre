<?php

namespace ESN\CalDAV\Schedule;

use Sabre\VObject\Reader;

require_once ESN_TEST_BASE. '/CalDAV/Schedule/IMipPluginTestBase.php';

/**
 * @medium
 * Tests for recurrence with multiple timezones and DST handling
 */
class IMipPluginRecurrentEventMultipleTimezonesTest extends IMipPluginTestBase {
    protected $expectedEventMessage;

    function testCreateRecurringEventWithEuropeParisTZ()
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
            'TZID:Europe/Paris',
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:paris-recurring-uid',
            'RRULE:FREQ=WEEKLY;BYDAY=TU',
            'DTSTART;TZID=Europe/Paris:' . $this->afterCurrentDate . 'T140000',
            'DTEND;TZID=Europe/Paris:' . $this->afterCurrentDate . 'T150000',
            'SUMMARY:Weekly Team Meeting Paris',
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
        $itipMessage->uid = 'paris-recurring-uid';
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

    function testCreateRecurringEventWithNewYorkTZ()
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
            'TZID:America/New_York',
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:-0500',
            'TZOFFSETTO:-0400',
            'TZNAME:EDT',
            'DTSTART:19700308T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:-0400',
            'TZOFFSETTO:-0500',
            'TZNAME:EST',
            'DTSTART:19701101T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:newyork-recurring-uid',
            'RRULE:FREQ=DAILY;INTERVAL=2',
            'DTSTART;TZID=America/New_York:' . $this->afterCurrentDate . 'T090000',
            'DTEND;TZID=America/New_York:' . $this->afterCurrentDate . 'T100000',
            'SUMMARY:Daily Standup NY',
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
        $itipMessage->uid = 'newyork-recurring-uid';
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

    function testModifyRecurringEventChangingTimezone()
    {
        $plugin = $this->getPlugin();

        // Original event in UTC
        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:tz-change-uid',
            'RRULE:FREQ=WEEKLY',
            'DTSTART:' . $this->afterCurrentDate . 'T140000Z',
            'DTEND:' . $this->afterCurrentDate . 'T150000Z',
            'SUMMARY:Weekly Sync',
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

        // Modified event with Europe/Paris timezone
        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VTIMEZONE',
            'TZID:Europe/Paris',
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:tz-change-uid',
            'RRULE:FREQ=WEEKLY',
            'DTSTART;TZID=Europe/Paris:' . $this->afterCurrentDate . 'T160000',
            'DTEND;TZID=Europe/Paris:' . $this->afterCurrentDate . 'T170000',
            'SUMMARY:Weekly Sync',
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
        $itipMessage->uid = 'tz-change-uid';
        $itipMessage->component = 'VEVENT';
        $itipMessage->method = 'REQUEST';
        $itipMessage->sequence = 1;
        $itipMessage->sender = 'mailto:' . $this->user1Email;
        $itipMessage->recipient = 'mailto:' . $this->user2Email;
        $itipMessage->scheduleStatus = null;
        $itipMessage->significantChange = true;
        $itipMessage->hasChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        // Verify that the timezone change triggers a notification
        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, $this->anything());

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    function testAddInstanceToRecurringEventWithDifferentTimezone()
    {
        $plugin = $this->getPlugin();

        // Original recurring event in Europe/Paris
        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VTIMEZONE',
            'TZID:Europe/Paris',
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:mixed-tz-instance-uid',
            'RRULE:FREQ=WEEKLY',
            'DTSTART;TZID=Europe/Paris:' . $this->afterCurrentDate . 'T100000',
            'DTEND;TZID=Europe/Paris:' . $this->afterCurrentDate . 'T110000',
            'SUMMARY:Team Sync',
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

        $instanceDate = date('Ymd', strtotime('+7 days'));

        // Add an exception instance in America/New_York timezone
        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VTIMEZONE',
            'TZID:Europe/Paris',
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:+0100',
            'TZOFFSETTO:+0200',
            'TZNAME:CEST',
            'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:+0200',
            'TZOFFSETTO:+0100',
            'TZNAME:CET',
            'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VTIMEZONE',
            'TZID:America/New_York',
            'BEGIN:DAYLIGHT',
            'TZOFFSETFROM:-0500',
            'TZOFFSETTO:-0400',
            'TZNAME:EDT',
            'DTSTART:19700308T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU',
            'END:DAYLIGHT',
            'BEGIN:STANDARD',
            'TZOFFSETFROM:-0400',
            'TZOFFSETTO:-0500',
            'TZNAME:EST',
            'DTSTART:19701101T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU',
            'END:STANDARD',
            'END:VTIMEZONE',
            'BEGIN:VEVENT',
            'UID:mixed-tz-instance-uid',
            'RRULE:FREQ=WEEKLY',
            'DTSTART;TZID=Europe/Paris:' . $this->afterCurrentDate . 'T100000',
            'DTEND;TZID=Europe/Paris:' . $this->afterCurrentDate . 'T110000',
            'SUMMARY:Team Sync',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201027T182723Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:mixed-tz-instance-uid',
            'RECURRENCE-ID;TZID=Europe/Paris:' . $instanceDate . 'T100000',
            'DTSTART;TZID=America/New_York:' . $instanceDate . 'T090000',
            'DTEND;TZID=America/New_York:' . $instanceDate . 'T100000',
            'SUMMARY:Special NY Meeting',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201027T182723Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $itipMessage = new \Sabre\VObject\ITip\Message();
        $itipMessage->uid = 'mixed-tz-instance-uid';
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
            ->method('publish');

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    function testRecurringAllDayEventAcrossTimezones()
    {
        $plugin = $this->getPlugin();
        $plugin->setNewEvent(true);

        // All-day events should not be affected by timezone
        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:allday-recurring-uid',
            'RRULE:FREQ=YEARLY;BYMONTH=7;BYMONTHDAY=4',
            'DTSTART;VALUE=DATE:20210704',
            'DTEND;VALUE=DATE:20210705',
            'SUMMARY:Independence Day',
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
        $itipMessage->uid = 'allday-recurring-uid';
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
}
