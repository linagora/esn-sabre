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

    /**
     * Test for issue #152
     * When organizer modifies an occurrence that doesn't include an attendee,
     * that attendee should NOT receive an iTIP notification
     */
    function testShouldNotSendUpdateToUninvitedAttendeesWhenOrganizerModifiesOtherInstances()
    {
        $plugin = $this->getPlugin();

        // Initial event: recurring event with 3 occurrences
        // Occurrence #2 has user2 invited
        // Occurrence #3 does NOT have user2 invited
        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY;COUNT=3',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RECURRENCE-ID:20201029T170000Z',
            'DTSTART:20201029T170000Z',
            'DTEND:20201029T173000Z',
            'SUMMARY:Test - Instance #2 (user2 invited)',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RECURRENCE-ID:20201030T170000Z',
            'DTSTART:20201030T170000Z',
            'DTEND:20201030T173000Z',
            'SUMMARY:Test - Instance #3 (user2 NOT invited)',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);

        $plugin->setFormerEventICal($user1ExistingEvent);
        $plugin->setNewEvent(false);

        // Organizer modifies occurrence #3 (where user2 is NOT invited)
        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY;COUNT=3',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RECURRENCE-ID:20201029T170000Z',
            'DTSTART:20201029T170000Z',
            'DTEND:20201029T173000Z',
            'SUMMARY:Test - Instance #2 (user2 invited)',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20201025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RECURRENCE-ID:20201030T170000Z',
            'DTSTART:20201030T180000Z',
            'DTEND:20201030T183000Z',
            'SUMMARY:Test - Instance #3 MODIFIED (user2 NOT invited)',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
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

        // EXPECTED: No notification should be sent to user2
        // because the modified occurrence (#3) does not include user2 in ATTENDEE list
        $this->amqpPublisher->expects($this->never())
            ->method('publish');

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    /**
     * Test for issue #152 - complementary test
     * When organizer modifies an occurrence to invite an attendee,
     * that attendee SHOULD receive an iTIP notification
     */
    function testShouldSendRequestWhenOrganizerInvitesAttendeeToSpecificOccurrence()
    {
        $plugin = $this->getPlugin();

        // Initial event: recurring event where user2 is NOT invited to any occurrence
        $user1ExistingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY;COUNT=3',
            'DTSTART:20501028T170000Z',
            'DTEND:20501028T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'DTSTAMP:20501025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);

        $plugin->setFormerEventICal($user1ExistingEvent);
        $plugin->setNewEvent(false);

        // Organizer modifies occurrence #2 to invite user2
        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RRULE:FREQ=DAILY;COUNT=3',
            'DTSTART:20501028T170000Z',
            'DTEND:20501028T173000Z',
            'SUMMARY:Test',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'DTSTAMP:20501025T145516Z',
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'RECURRENCE-ID:20501029T170000Z',
            'DTSTART:20501029T170000Z',
            'DTEND:20501029T173000Z',
            'SUMMARY:Test - Instance #2 (user2 NOW invited)',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'DTSTAMP:20501027T182723Z',
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

        // EXPECTED: user2 SHOULD receive an iTIP notification
        // because the organizer is inviting user2 to occurrence #2
        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(
                $this->equalTo('calendar:event:notificationEmail:send'),
                $this->callback(function ($message) {
                    $decoded = json_decode($message, true);
                    return $decoded['recipientEmail'] === 'rudyvoller@om.com'
                        && $decoded['method'] === 'REQUEST'
                        && isset($decoded['isNewEvent'])
                        && $decoded['isNewEvent'] === true;
                })
            );

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }

    /**
     * Test for issue #152: Organizer modifies an occurrence that attendee is not invited to
     *
     * Scenario:
     * 1. Bob creates recurring event (3 days) with only himself
     * 2. Bob invites Cedric to occurrence #2 only
     * 3. Bob modifies occurrence #3 (creates new exception with SEQUENCE:2)
     * 4. Cedric should NOT receive notification for occurrence #3
     */
    function testShouldNotSendUpdateToUninvitedAttendeeWhenOrganizerModifiesOtherOccurrence()
    {
        $plugin = $this->getPlugin();
        $plugin->setNewEvent(false);

        // STEP 1: Bob had previously invited Cedric to occurrence #2 only
        $formerEventIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:test-event-152',
            'DTSTART:20260322T090000Z',
            'DTEND:20260322T100000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'SUMMARY:Daily meeting',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:test-event-152',
            'DTSTART:20260323T090000Z',
            'DTEND:20260323T100000Z',
            'RECURRENCE-ID:20260323T090000Z',
            'SUMMARY:Daily meeting',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);

        $plugin->setFormerEventICal($formerEventIcal);

        // STEP 2: Bob now creates/modifies occurrence #3 (without Cedric)
        $scheduledIcal = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:test-event-152',
            'DTSTART:20260322T090000Z',
            'DTEND:20260322T100000Z',
            'RRULE:FREQ=DAILY;COUNT=3',
            'SUMMARY:Daily meeting',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:test-event-152',
            'DTSTART:20260323T090000Z',
            'DTEND:20260323T100000Z',
            'RECURRENCE-ID:20260323T090000Z',
            'SUMMARY:Daily meeting',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user2Email,
            'SEQUENCE:0',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:test-event-152',
            'DTSTART:20260324T090000Z',
            'DTEND:20260324T100000Z',
            'RECURRENCE-ID:20260324T090000Z',
            'SUMMARY:Updated instance (Day 3)',
            'ORGANIZER:mailto:' . $this->user1Email,
            'ATTENDEE:mailto:' . $this->user1Email,
            'SEQUENCE:2',
            'END:VEVENT',
            'END:VCALENDAR',
            ''
        ]);

        $itipMessage = new \Sabre\VObject\ITip\Message();
        $itipMessage->uid = 'test-event-152';
        $itipMessage->component = 'VEVENT';
        $itipMessage->method = 'REQUEST';
        $itipMessage->sequence = 2;
        $itipMessage->sender = 'mailto:' . $this->user1Email;
        $itipMessage->recipient = 'mailto:' . $this->user2Email;
        $itipMessage->scheduleStatus = 'null';
        $itipMessage->significantChange = true;
        $itipMessage->hasChange = true;
        $itipMessage->message = Reader::read($scheduledIcal);

        // EXPECTED: user2 (Cedric) should NOT receive notification for occurrence #3
        // because he was never invited to that occurrence
        $this->amqpPublisher->expects($this->never())
            ->method('publish');

        $plugin->schedule($itipMessage);
        $this->assertEquals('1.1', $itipMessage->scheduleStatus);
    }
}
