<?php

namespace ESN\CalDAV\Schedule;

require_once ESN_TEST_BASE . '/Sabre/HTTP/ResponseMock.php';
require_once ESN_TEST_BASE . '/Sabre/HTTP/SapiMock.php';
require_once ESN_TEST_BASE . '/Sabre/DAVACL/PrincipalBackend/Mock.php';
require_once ESN_TEST_BASE . '/Sabre/CalDAV/Backend/Mock.php';
require_once ESN_TEST_BASE . '/Sabre/CardDAV/Backend/Mock.php';
require_once ESN_TEST_BASE . '/Sabre/DAVServerTestBase.php';
require_once ESN_TEST_BASE . '/Sabre/DAV/Auth/Backend/Mock.php';
use \ESN\Utils\Utils as Utils;

#[\AllowDynamicProperties]
class IMipPluginTestBase extends \PHPUnit\Framework\TestCase {
    protected $amqpPublisher;
    const USER_1_CALENDAR_NAME = "calendar1";

    protected $iCalAttendees;

    protected $user1Id;
    protected $user1Email;
    protected $user2Id;
    protected $user2Email;

    protected $user1Calendar;
    protected $user2Calendar;
    protected $afterCurrentDate;
    protected $formattedAfterCurrentDate;

    private $server;
    private $caldavBackend;

    function setUp(): void {
        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $esndb = $mcesn->{ESN_MONGO_ESNDB};

        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $sabredb->drop();
        $esndb->drop();

        $this->afterCurrentDate = date('Ymd', strtotime('+2 days', time()));
        $this->formattedAfterCurrentDate = date('Y-m-d', strtotime('+2 days', time()));

        $this->user1Calendar = [
            '{DAV:}displayname' => 'Calendar',
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
            '{http://apple.com/ns/ical/}calendar-color' => '#0190FFFF',
            '{http://apple.com/ns/ical/}calendar-order' => '2',
            'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
            'uri' => 'calendar1'
        ];

        $this->user2Calendar = [
            '{DAV:}displayname' => 'Calendar',
            '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
            '{http://apple.com/ns/ical/}calendar-color' => '#0190FFFF',
            '{http://apple.com/ns/ical/}calendar-order' => '2',
            'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0e',
            'uri' => 'calendar2'
        ];

        $this->user1Id = '54b64eadf6d7d8e41d263e0f';
        $this->user1Email = 'robertocarlos@realmadrid.com';
        $user1 = [
            '_id' => new \MongoDB\BSON\ObjectId($this->user1Id),
            'firstname' => 'Roberto',
            'lastname' => 'Carlos',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                        $this->user1Email
                    ]
                ]
            ],
            'domains' => []
        ];
        $this->user2Id = '54b64eadf6d7d8e41d263e0e';
        $this->user2Email = 'rudyvoller@om.com';
        $user2 = [
            '_id' => new \MongoDB\BSON\ObjectId($this->user2Id),
            'firstname' => 'Rudy',
            'lastname' => 'Voller',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                        $this->user2Email
                    ]
                ]
            ],
            'domains' => []
        ];

        $esndb->users->insertOne($user1);
        $esndb->users->insertOne($user2);

        list($this->caldavBackend, $authBackend) = $this->initServer($esndb, $sabredb);

        // Set connected user
        $authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0f');

        $this->user1Calendar['id'] = $this->caldavBackend->createCalendar($this->user1Calendar['principaluri'], $this->user1Calendar['uri'], $this->user1Calendar);
        $this->user2Calendar['id'] = $this->caldavBackend->createCalendar($this->user2Calendar['principaluri'], $this->user2Calendar['uri'], $this->user2Calendar);

        $user2matchingEvent = join("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sabre//Sabre VObject 4.1.3//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:ab9e450a-3080-4274-affd-fdd0e9eefdcc',
            'DTSTART:20201028T170000Z',
            'DTEND:20201028T173000Z',
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
        $this->addUser2Event('ab9e450a-3080-4274-affd-fdd0e9eefdcc.ics', $user2matchingEvent);

        $_SERVER["REQUEST_URI"] = "/calendars/54b64eadf6d7d8e41d263e0f/".self::USER_1_CALENDAR_NAME."/ab9e450a-3080-4274-affd-fdd0e9eefdcc.ics";

        $this->server->exec();
    }

    protected function addUser1Event($eventId, $event) {
        $this->caldavBackend->createCalendarObject($this->user1Calendar['id'], $eventId, $event);
    }

    protected function addUser2Event($eventId, $event) {
        $this->caldavBackend->createCalendarObject($this->user2Calendar['id'], $eventId, $event);
    }

    protected function getPlugin() {
        $this->amqpPublisher = $this->getMockBuilder(AMQPPublisherMock::class)->getMock();

        return new IMipPluginMock($this->server, $this->amqpPublisher);
    }

    protected function getMessageForPublisher($iTipMessage, $isNewEvent, $eventIcs = null) {
        $recipientPrincipalUri = Utils::getPrincipalByUri($iTipMessage->recipient, $this->server);
        list($eventPath, ) = Utils::getEventObjectFromAnotherPrincipalHome($recipientPrincipalUri, $iTipMessage->uid, $iTipMessage->method, $this->server);
        $matched = preg_match("|/(calendars/.*/.*)/|", $_SERVER["REQUEST_URI"], $matches);

        if ($matched) {
            $calendarPath = $matches[1];
        }

        $message = [
            'senderEmail' => substr($iTipMessage->sender, 7),
            'recipientEmail' => substr($iTipMessage->recipient, 7),
            'method' => $iTipMessage->method,
            'event' => is_null($eventIcs) ? $iTipMessage->message->serialize() : $eventIcs,
            'notify' => true,
            'calendarURI' => self::USER_1_CALENDAR_NAME,
            'eventPath' => $eventPath ? '/' . $eventPath : '/' . $calendarPath . '/' . $iTipMessage->uid . '.ics'
        ];

        if ($isNewEvent) {
            $message['isNewEvent'] = true;
        }

        return $message;
    }

    private function initServer(\MongoDB\Database $esndb, \MongoDB\Database $sabredb): array
    {
        $principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($esndb);
        $caldavBackend = new \ESN\CalDAV\Backend\Mongo($sabredb);

        $tree[] = new \Sabre\DAV\SimpleCollection('principals', [
            new \Sabre\CalDAV\Principal\Collection($principalBackend, 'principals/users')
        ]);
        $tree[] = new \ESN\CalDAV\CalendarRoot(
            $principalBackend,
            $caldavBackend,
            $esndb
        );

        $this->server = new \Sabre\DAV\Server($tree);
        $this->server->sapi = new \Sabre\HTTP\SapiMock();
        $this->server->debugExceptions = true;

        $caldavPlugin = new \ESN\CalDAV\Plugin();
        $this->server->addPlugin($caldavPlugin);

        $this->server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
        $this->server->addPlugin(new \Sabre\CalDAV\SharingPlugin());

        $authBackend = new \Sabre\DAV\Auth\Backend\Mock();
        $authPlugin = new \Sabre\DAV\Auth\Plugin($authBackend);
        $this->server->addPlugin($authPlugin);

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals', 'principals/users'];
        $this->server->addPlugin($aclPlugin);

        $caldavSchedulePlugin = new \ESN\CalDAV\Schedule\Plugin();
        $this->server->addPlugin($caldavSchedulePlugin);
        return array($caldavBackend, $authBackend);
    }
}

class IMipPluginMock extends IMipPlugin {
    function __construct($server, $amqpPublisher) {
        require_once ESN_TEST_VENDOR . '/sabre/http/tests/HTTP/ClientTest.php';
        parent::__construct($amqpPublisher);
        $this->initialize($server);

        // Init for simple tests
        $this->isNewEvent = true;
    }

    function getServer() {
        return $this->server;
    }

    function setFormerEventICal($iCal) {
        $this->formerEventICal = $iCal;
    }

    function setNewEvent($isNew) {
        $this->isNewEvent = $isNew;
    }
}

class AMQPPublisherMock {
    function publish() {
    }
}
