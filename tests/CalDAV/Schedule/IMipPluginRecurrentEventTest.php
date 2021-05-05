<?php

namespace ESN\CalDAV\Schedule;

use Sabre\VObject\Reader;

require_once ESN_TEST_BASE. '/CalDAV/Schedule/IMipPluginTestBase.php';

/**
 * @medium
 */
class IMipPluginRecurrentEventTest extends IMipPluginTestBase {
    protected $expectedEventMessage;

    function testCreateRecurringEventWithAttendee()
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
            'RRULE:FREQ=DAILY',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
            'X-OPENPAAS-VIDEOCONFERENCE:sdfsdf',
            'X-MICROSOFT:sdfsdf',
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

    function testAddAttendeeToRecurringEvent()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
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
            'RRULE:FREQ=DAILY',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
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

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher($itipMessage, true)));

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    function testModifyRecurringEventWithAttendee()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
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
            'RRULE:FREQ=DAILY',
            'DTSTART:20201029T170000Z',
            'DTEND:20201029T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:masilto:' . $this->user1Email,
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
                    'date' => '2020-10-29 17:00:00.000000',
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
                    'date' => '2020-10-29 17:30:00.000000',
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

    function testAddDescriptionOfRecurringEvent()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
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
            'RRULE:FREQ=DAILY',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
            'SUMMARY:Test',
            'DESCRIPTION:Test',
            'LOCATION:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);

        $itipMessage = new \Sabre\VObject\ITip\Message();
        $itipMessage->uid = 'ab9e450a-3080-4274-affd-fdd0e9eefdcc';
        $itipMessage->component = 'VEVENT';
        $itipMessage->method = 'REQUEST';
        $itipMessage->sequence = 1;
        $itipMessage->sender = 'mailto:' . $this->user1Email;
        $itipMessage->recipient = 'mailto:' . $this->user2Email;
        $itipMessage->scheduleStatus = null;
        $itipMessage->hasChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        $message = $this->getMessageForPublisher($itipMessage, false);

        $message['changes'] = [
            'location' => [
                'previous' => '',
                'current' => 'Test'
            ],
            'description' => [
                'previous' => '',
                'current' => 'Test'
            ]
        ];

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($message));

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    function testAddLocationOfRecurringEvent()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
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
            'RRULE:FREQ=DAILY',
            'DTSTART:20201029T170000Z',
            'DTEND:20201029T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201027T182723Z',
            'SEQUENCE:0',
            'LOCATION:Test',
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
        $itipMessage->hasChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        $message = $this->getMessageForPublisher($itipMessage, false);

        $message['changes'] = [
            'location' => [
                'previous' => '',
                'current' => 'Test'
            ],
            'dtstart' => [
                'previous' => [
                    'isAllDay' => false,
                    'date' => '2020-10-28 17:00:00.000000',
                    'timezone_type' => 3,
                    'timezone' => 'UTC'
                ],
                'current' => [
                    'isAllDay' => false,
                    'date' => '2020-10-29 17:00:00.000000',
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
                    'date' => '2020-10-29 17:30:00.000000',
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


    function testAddSummaryOfRecurringEvent()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
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
            'RRULE:FREQ=DAILY',
            'DTSTART:20201029T170000Z',
            'DTEND:20201029T173000Z',
            'SUMMAZERY:Test',
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
        $itipMessage->uid = 'ab9e450a-3080-4274-affd-fdd0e9eefdcc';
        $itipMessage->component = 'VEVENT';
        $itipMessage->method = 'REQUEST';
        $itipMessage->sequence = 1;
        $itipMessage->sender = 'mailto:' . $this->user1Email;
        $itipMessage->recipient = 'mailto:' . $this->user2Email;
        $itipMessage->scheduleStatus = null;
        $itipMessage->hasChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        $message = $this->getMessageForPublisher($itipMessage, false);

        $message['changes'] = [
            'summary' => [
                'previous' => 'Test',
                'current' => ''
            ],
            'dtstart' => [
                'previous' => [
                    'isAllDay' => false,
                    'date' => '2020-10-28 17:00:00.000000',
                    'timezone_type' => 3,
                    'timezone' => 'UTC'
                ],
                'current' => [
                    'isAllDay' => false,
                    'date' => '2020-10-29 17:00:00.000000',
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
                    'date' => '2020-10-29 17:30:00.000000',
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

    function testCancelRecurringEventWithAttendee()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201029T145516Z',
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
            'METHOD:CANCEL',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
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

    function testAddInstanceToRecurringEvent()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
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
            'RRULE:FREQ=DAILY',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201027T182723Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RECURRENCE-ID:'.$this->afterCurrentDate.'T170000Z',
            'DTSTART:'.$this->afterCurrentDate.'T160000Z',
            'DTEND:'.$this->afterCurrentDate.'T163000Z',
            'SUMMARY:Test EX#1',
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

        $expectedMessageEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RECURRENCE-ID:'.$this->afterCurrentDate.'T170000Z',
            'DTSTART:'.$this->afterCurrentDate.'T160000Z',
            'DTEND:'.$this->afterCurrentDate.'T163000Z',
            'SUMMARY:Test EX#1',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201027T182723Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $message = $this->getMessageForPublisher($itipMessage, false, $expectedMessageEvent);

        $message['changes'] = [
            'summary' => [
                'previous' => 'Test',
                'current' => 'Test EX#1'
            ],
            'dtstart' => [
                'previous' => [
                    'isAllDay' => false,
                    'date' => $this->formattedAfterCurrentDate . ' 17:00:00.000000',
                    'timezone_type' => 3,
                    'timezone' => 'UTC'
                ],
                'current' => [
                    'isAllDay' => false,
                    'date' => $this->formattedAfterCurrentDate .' 16:00:00.000000',
                    'timezone_type' => 3,
                    'timezone' => 'UTC'
                ]
            ],
            'dtend' => [
                'previous' => [
                    'isAllDay' => false,
                    'date' => $this->formattedAfterCurrentDate . ' 17:30:00.000000',
                    'timezone_type' => 3,
                    'timezone' => 'UTC'
                ],
                'current' => [
                    'isAllDay' => false,
                    'date' => $this->formattedAfterCurrentDate .' 16:30:00.000000',
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

    function testAddInstanceToAllDayRecurringEvent()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
            'DTSTART;VALUE=DATE:20201028',
            'DTEND;VALUE=DATE:20201029',
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

        $newDtStart = date('Ymd', strtotime('+3 days', time()));
        $formattedNewDtStart = date('Y-m-d', strtotime('+3 days', time()));
        $formattedOneDayAfterNewDtStart = date('Y-m-d', strtotime('+4 days', time()));
        $newDtEnd = date('Ymd', strtotime('+5 days', time()));
        $formattedNewDtEnd = date('Y-m-d', strtotime('+5 days', time()));

        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
            'DTSTART;VALUE=DATE:20201028',
            'DTEND;VALUE=DATE:20201029',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201027T182723Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RECURRENCE-ID;VALUE=DATE:'.$newDtStart,
            'DTSTART;VALUE=DATE:'.$newDtStart,
            'DTEND;VALUE=DATE:'.$newDtEnd,
            'SUMMARY:Test EX#1',
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

        $expectedMessageEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RECURRENCE-ID;VALUE=DATE:'.$newDtStart,
            'DTSTART;VALUE=DATE:'.$newDtStart,
            'DTEND;VALUE=DATE:'.$newDtEnd,
            'SUMMARY:Test EX#1',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201027T182723Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $message = $this->getMessageForPublisher($itipMessage, false, $expectedMessageEvent);

        $message['changes'] = [
            'summary' => [
                'previous' => 'Test',
                'current' => 'Test EX#1'
            ],
            'dtend' => [
                'previous' => [
                    'isAllDay' => true,
                    'date' => $formattedOneDayAfterNewDtStart . ' 00:00:00.000000',
                    'timezone_type' => 3,
                    'timezone' => 'UTC'
                ],
                'current' => [
                    'isAllDay' => true,
                    'date' => $formattedNewDtEnd . ' 00:00:00.000000',
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

    function testRemoveInstanceFromRecurringEvent()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T170000Z',
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
            'RRULE:FREQ=DAILY',
            'EXDATE:'. $this->afterCurrentDate.'T170000Z',
            'DTSTART:'. $this->afterCurrentDate.'T170000Z',
            'DTEND:'. $this->afterCurrentDate.'T173000Z',
            'SUMMARY:Test',
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

        $expectedMessageEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:CANCEL',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'DTSTART:'.$this->afterCurrentDate.'T170000Z',
            'DTEND:'.$this->afterCurrentDate.'T170000Z',
            'RECURRENCE-ID:'.$this->afterCurrentDate.'T170000Z',
            'STATUS:CANCELLED',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $expectedMessage = [
            'senderEmail' => $this->user1Email,
            'recipientEmail' => $this->user2Email,
            'method' => 'CANCEL',
            'event' => $expectedMessageEvent,
            'notify' => true,
            'calendarURI' => $this->user1Calendar['uri'],
            'eventPath' => '/calendars/'.$this->user2Id.'/'.$this->user2Calendar['uri'].'/ab9e450a-3080-4274-affd-fdd0e9eefdcc.ics'
        ];

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($expectedMessage));

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }


    function testRemoveExistingInstanceFromRecurringEvent()
    {
        $plugin = $this->getPlugin();

        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RECURRENCE-ID:'. $this->afterCurrentDate.'T170000Z',
            'DTSTART:'. $this->afterCurrentDate.'T160000Z',
            'DTEND:'. $this->afterCurrentDate.'T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201027T182723Z',
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
            'RRULE:FREQ=DAILY',
            'EXDATE:'. $this->afterCurrentDate.'T170000Z',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201027T182723Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RECURRENCE-ID:'. $this->afterCurrentDate.'T170000Z',
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
        $itipMessage->hasChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        $expectedMessageEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:CANCEL',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RECURRENCE-ID:'. $this->afterCurrentDate.'T170000Z',
            'DTSTART:'. $this->afterCurrentDate.'T160000Z',
            'DTEND:'. $this->afterCurrentDate.'T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201027T182723Z',
            'SEQUENCE:0',
            'STATUS:CANCELLED',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $expectedMessage = [
            'senderEmail' => $this->user1Email,
            'recipientEmail' => $this->user2Email,
            'method' => 'CANCEL',
            'event' => $expectedMessageEvent,
            'notify' => true,
            'calendarURI' => $this->user1Calendar['uri'],
            'eventPath' => '/calendars/'.$this->user2Id.'/'.$this->user2Calendar['uri'].'/ab9e450a-3080-4274-affd-fdd0e9eefdcc.ics'
        ];

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($expectedMessage));

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    function testInfiniteRecurrentEvent()
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
            'RRULE:FREQ=DAILY',
            'DTSTART:20201028T170000Z',
            'DTEND:20201030T173000Z',
            'X-OPENPAAS-VIDEOCONFERENCE:sdfsdf',
            'X-MICROSOFT:sdfsdf',
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

    function testExpiredRecurrentEvent()
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
            'RRULE:FREQ=DAILY;COUNT=2',
            'DTSTART:20201028T170000Z',
            'DTEND:20201030T173000Z',
            'X-OPENPAAS-VIDEOCONFERENCE:sdfsdf',
            'X-MICROSOFT:sdfsdf',
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

        $this->amqpPublisher->expects($this->never())
            ->method('publish');

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }
}
