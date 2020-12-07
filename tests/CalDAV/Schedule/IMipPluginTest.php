<?php

namespace ESN\CalDAV\Schedule;

require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/ResponseMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/SapiMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVACL/PrincipalBackend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CalDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CardDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVServerTest.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAV/Auth/Backend/Mock.php';
use \ESN\Utils\Utils as Utils;

/**
 * @medium
 */
class IMipPluginTest extends \PHPUnit_Framework_TestCase {
    protected $amqpPublisher;
    const NAME = "calendar1";

    protected $caldavCalendar = array(
        '{DAV:}displayname' => 'Calendar',
        '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
        '{http://apple.com/ns/ical/}calendar-color' => '#0190FFFF',
        '{http://apple.com/ns/ical/}calendar-order' => '2',
        'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0f',
        'uri' => 'calendar1',
    );

    protected $caldavCalendarUser2 = array(
        '{DAV:}displayname' => 'Calendar',
        '{urn:ietf:params:xml:ns:caldav}calendar-description' => 'description',
        '{http://apple.com/ns/ical/}calendar-color' => '#0190FFFF',
        '{http://apple.com/ns/ical/}calendar-order' => '2',
        'principaluri' => 'principals/users/54b64eadf6d7d8e41d263e0e',
        'uri' => 'calendar2',
    );

    function setUp() {
        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->{ESN_MONGO_ESNDB};

        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->sabredb->drop();
        $this->esndb->drop();

        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId('54b64eadf6d7d8e41d263e0f'),
            'firstname' => 'Roberto',
            'lastname' => 'Carlos',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                    'robertocarlos@realmadrid.com'
                    ]
                ]
            ],
            'domains' => []
        ]);
        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId('54b64eadf6d7d8e41d263e0e'),
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                    'johndoe@example.org'
                    ]
                ]
            ],
            'domains' => []
        ]);

        $this->ical = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:daab17fe-fac4-4946-9105-0f2cdb30f5ab',
            'SUMMARY:Hello',
            'DTSTART:20150228T030000Z',
            'DTEND:20150228T040000Z',
            'END:VEVENT',
            'END:VCALENDAR',
            '']);

        $this->icalRec = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1550',
            'DTSTART:20180305T120000Z',
            'DTEND:20180305T130000Z',
            'SUMMARY:Lunch',
            'RRULE:FREQ=DAILY;COUNT=8',
            'END:VEVENT',
            'BEGIN:VEVENT',
            'UID:e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1550',
            'DTSTART:20180306T120000Z',
            'DTEND:20180306T140000Z',
            'SUMMARY:Lunch',
            'RECURRENCE-ID:20180306T120000Z',
            'END:VEVENT',
            'END:VCALENDAR',
            '']);

        $this->icalAttendees = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1560',
            'DTSTART:20180305T120000Z',
            'DTEND:20180305T130000Z',
            'SUMMARY:Lunch',
            'ORGANIZER:mailto:robertocarlos@realmadrid.com',
            'ATTENDEE:mailto:robertocarlos@realmadrid.com',
            'END:VEVENT',
            'END:VCALENDAR',
            '']);

        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);
        $this->caldavBackend = new \ESN\CalDAV\Backend\Mongo($this->sabredb);
        $this->carddavBackend = new \ESN\CardDAV\Backend\Mongo($this->sabredb);

        $this->tree[] = new \Sabre\DAV\SimpleCollection('principals', [
        new \Sabre\CalDAV\Principal\Collection($this->principalBackend, 'principals/users')
        ]);
        $this->tree[] = new \ESN\CalDAV\CalendarRoot(
            $this->principalBackend,
            $this->caldavBackend,
            $this->esndb
        );

        $this->server = new \Sabre\DAV\Server($this->tree);
        $this->server->sapi = new \Sabre\HTTP\SapiMock();
        $this->server->debugExceptions = true;

        $caldavPlugin = new \ESN\CalDAV\Plugin();
        $this->server->addPlugin($caldavPlugin);

        $this->server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
        $this->server->addPlugin(new \Sabre\CalDAV\SharingPlugin());

        $authBackend = new \Sabre\DAV\Auth\Backend\Mock();
        $authBackend->setPrincipal('principals/users/54b64eadf6d7d8e41d263e0f');
        $authPlugin = new \Sabre\DAV\Auth\Plugin($authBackend);
        $this->server->addPlugin($authPlugin);

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals', 'principals/users'];
        $this->server->addPlugin($aclPlugin);

        $this->caldavSchedulePlugin = new \ESN\CalDAV\Schedule\Plugin();
        $this->server->addPlugin($this->caldavSchedulePlugin);

        $this->cal = $this->caldavCalendar;
        $this->cal['id'] = $this->caldavBackend->createCalendar($this->cal['principaluri'], $this->cal['uri'], $this->cal);
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'simple.ics', $this->ical);
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'rec.ics', $this->icalRec);
        $this->caldavBackend->createCalendarObject($this->cal['id'], 'attendees.ics', $this->icalAttendees);

        $this->calUser2 = $this->caldavCalendarUser2;
        $this->calUser2['id'] = $this->caldavBackend->createCalendar($this->calUser2['principaluri'], $this->calUser2['uri'], $this->calUser2);
        $this->caldavBackend->createCalendarObject($this->calUser2['id'], 'simple.ics', $this->ical);

        $this->calendarURI = self::NAME;
        $_SERVER["REQUEST_URI"] = "/calendars/54b64eadf6d7d8e41d263e0f/".$this->calendarURI."/event1.ics";

        $this->server->exec();
    }

    private function getPlugin() {
        $this->amqpPublisher = $this->getMockBuilder(AMQPPublisherMock::class)->getMock();
        $plugin = new IMipPluginMock($this->server, $this->amqpPublisher);

        $this->msg = new \Sabre\VObject\ITip\Message();
        if ($this->ical) {
            $this->msg->message = \Sabre\VObject\Reader::read($this->ical);
        }

        return $plugin;
    }

    private function getMessageForPublisher($iTipMessage, $iMipPlugin, $eventIcs = null) {
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
            'calendarURI' => $this->caldavCalendar['uri'],
            'eventPath' => $eventPath ? '/' . $eventPath : '/' . $calendarPath . '/' . $iTipMessage->uid . '.ics'
        ];

        if (isset($iMipPlugin->getNewAttendees()[$iTipMessage->recipient])) {
            $message['isNewEvent'] = true;
        }

        return $message;
    }

    function testScheduleNotSignificant() {
        $plugin = $this->getPlugin();
        $this->msg->significantChange = false;
        $this->msg->hasChange = false;

        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, '1.0');
    }

    function testNotMailto() {
        $plugin = $this->getPlugin();
        $this->msg->sender = 'http://example.com';
        $this->msg->recipient = 'http://example.com';
        $this->msg->scheduleStatus = 'unchanged';

        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, 'unchanged');

        $this->msg->sender = 'mailto:valid';

        $plugin->schedule($this->msg);
        $this->assertEquals($this->msg->scheduleStatus, 'unchanged');
    }

    function testSendUpdatedEvent() {
        $plugin = $this->getPlugin();

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:johndoe@example.org';
        $this->msg->method = "REQUEST";
        $this->msg->uid = "daab17fe-fac4-4946-9105-0f2cdb30f5ab";

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher($this->msg, $plugin)));

        $plugin->schedule($this->msg);
    }

    function testSendNewEvent() {
        $plugin = $this->getPlugin();

        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:johndoe@example.org';
        $this->msg->method = "REQUEST";
        $this->msg->uid = "daab17fe-fac4-4946-9105-0f2cdb30f5ab";

        $plugin->setNewAttendees((['mailto:johndoe@example.org' => ['master']]));

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher($this->msg, $plugin)));

        $plugin->schedule($this->msg);
    }

    function testSendRecToOpUser() {
        $plugin = $this->getPlugin();

        $this->msg->message = \Sabre\VObject\Reader::read($this->icalRec);
        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:johndoe@example.org';
        $this->msg->method = "REQUEST";
        $this->msg->uid = "e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1550";

        $this->amqpPublisher->expects($this->once())
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher($this->msg, $plugin)));

        $plugin->schedule($this->msg);
    }

    function testSendRecToExternalUser() {
        $messages[] = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1550',
            'DTSTART:20180305T120000Z',
            'DTEND:20180305T130000Z',
            'SUMMARY:Lunch',
            'RRULE:FREQ=DAILY;COUNT=8',
            'END:VEVENT',
            'END:VCALENDAR',
            '']);

        $messages[] = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1550',
            'DTSTART:20180306T120000Z',
            'DTEND:20180306T140000Z',
            'SUMMARY:Lunch',
            'RECURRENCE-ID:20180306T120000Z',
            'END:VEVENT',
            'END:VCALENDAR',
            '']);

        $plugin = $this->getPlugin();

        $this->msg->message = \Sabre\VObject\Reader::read($this->icalRec);
        $this->msg->sender = 'mailto:test@example.com';
        $this->msg->recipient = 'mailto:johndoe@other.org';
        $this->msg->method = "REQUEST";
        $this->msg->uid = "e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1550";

        $this->amqpPublisher->expects($this->at(0))
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher(
                $this->msg,
                $plugin,
                $messages[0]
            )));


        $this->amqpPublisher->expects($this->at(1))
            ->method('publish')
            ->with(IMipPlugin::SEND_NOTIFICATION_EMAIL_TOPIC, json_encode($this->getMessageForPublisher(
                $this->msg,
                $plugin,
                $messages[1]
            )));

        $plugin->schedule($this->msg);
    }

    function testCalendarObjectChangeEventModification() {
        $data = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1560',
            'DTSTART:20180305T120000Z',
            'DTEND:20180305T130000Z',
            'SUMMARY:Lunch',
            'ORGANIZER:mailto:robertocarlos@realmadrid.com',
            'ATTENDEE:mailto:robertocarlos@realmadrid.com',
            'ATTENDEE;CN=Two:mailto:two@example.org',
            'END:VEVENT',
            'END:VCALENDAR',
            '']);

        $attendees = $this->emitCalendarChange('attendees.ics', $data, false);

        $this->assertEquals($attendees, ['mailto:two@example.org' => 1]);
    }

    function testCalendarObjectChangeNewEvent() {
        $data = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:new-event',
            'DTSTART:20180305T120000Z',
            'DTEND:20180305T130000Z',
            'SUMMARY:Lunch',
            'RRULE:FREQ=DAILY;COUNT=8',
            'ORGANIZER:mailto:robertocarlos@realmadrid.com',
            'ATTENDEE:mailto:robertocarlos@realmadrid.com',
            'ATTENDEE;CN=Two:mailto:two@example.org',
            'END:VEVENT',
            'END:VCALENDAR',
            '']);

        $attendees = $this->emitCalendarChange('new.ics', $data, true);

        $this->assertEquals($attendees, ['mailto:robertocarlos@realmadrid.com' => 1, 'mailto:two@example.org' => 1]);
    }

    function testCalendarObjectChangeEventModificationWithoutAttendee() {
        $data = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:e5f6e3cd-90e5-46fe-9c5a-f9aaa1aa1560',
            'DTSTART:20180305T120000Z',
            'DTEND:20180305T130000Z',
            'SUMMARY:Lunch',
            'ORGANIZER:mailto:robertocarlos@realmadrid.com',
            'END:VEVENT',
            'END:VCALENDAR',
            '']);

        $attendees = $this->emitCalendarChange('simple.ics', $data, false);

        $this->assertEquals($attendees, []);
    }

    private function emitCalendarChange(string $eventId, string $data, bool $isNew): array
    {
        $plugin = $this->getPlugin();

        $request = \Sabre\HTTP\Sapi::createFromServerArray(array(
            'REQUEST_METHOD' => 'PUT',
            'HTTP_CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REQUEST_URI' => '/calendars/54b64eadf6d7d8e41d263e0f/calendar1/' . $eventId,
        ));
        $response = new \Sabre\HTTP\ResponseMock();

        $modified = false;
        $vobj = \Sabre\VObject\Reader::read($data);

        $plugin->calendarObjectChange(
            $request,
            $response,
            $vobj,
            null,
            $modified,
            $isNew
        );

        return $plugin->getNewAttendees();
    }
}

class IMipPluginMock extends IMipPlugin {
    function __construct($server, $amqpPublisher) {
        require_once ESN_TEST_VENDOR . '/sabre/http/tests/HTTP/ClientTest.php';
        parent::__construct($amqpPublisher);
        $this->initialize($server);
    }

    function setApiRoot($val) {
        $this->apiroot = $val;
    }

    function getServer() {
        return $this->server;
    }

    function setNewAttendees($newAttendees) {
        $this->newAttendees = $newAttendees;
    }

    function getNewAttendees() {
        return $this->newAttendees;
    }
}

class AMQPPublisherMock {
    function publish() {
    }
}
