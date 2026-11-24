<?php

namespace ESN\Publisher\CalDAV;

use ESN\CalDAV\Schedule\AMQPSchedulePlugin;

/**
 * A resource administrator answers for the resource by writing its copy of the event, as the
 * side service's ResourceParticipationRoute does. The organizer's service is then told through
 * resource:calendar:event:accepted|declined, which is never taken from a client-written REPLY.
 *
 * @medium
 */
class ResourceReplyPublicationTest extends \PHPUnit\Framework\TestCase {

    const DOMAIN_ID = '54b64eadf6d7d8e41d263e0b';
    const ADMIN_ID = '54b64eadf6d7d8e41d263e0a';
    const ALICE_ID = '54b64eadf6d7d8e41d263e0c';
    const MALLORY_ID = '54b64eadf6d7d8e41d263e0d';
    const RESOURCE_ID = '68fef2e630c9f300553cf04a';
    const UID = 'resource-reply';

    private $server;
    private $caldavBackend;
    private $authBackend;
    private $publisher;
    private $resourceCalendarId;
    private $aliceCalendarId;

    function testAcceptanceWrittenByTheAdministratorIsPublishedOnceTheSideServiceDeliversIt() {
        $this->initServer(true);

        $this->writeResourceCopyAs(self::ADMIN_ID, 'ACCEPTED');
        $this->assertSame([], $this->resourceTopics(), 'nothing is published before the delivery');

        $reply = $this->publishedReply();
        $this->assertSame(204, $this->postItipAs(self::ALICE_ID, $reply['message'])->getStatus());

        $this->assertSame(['resource:calendar:event:accepted'], $this->resourceTopics());
    }

    function testAcceptanceWrittenByTheAdministratorIsPublishedWithSynchronousScheduling() {
        $this->initServer(false);

        $this->writeResourceCopyAs(self::ADMIN_ID, 'ACCEPTED');

        $this->assertSame(['resource:calendar:event:accepted'], $this->resourceTopics());
    }

    function testReplyForgedInTheResourcesNameIsNotPublished() {
        $this->initServer(true);

        // A co-attendee holds a copy of the event, and posts a REPLY naming the room as sender
        $this->caldavBackend->createCalendarObject($this->calendarOf(self::MALLORY_ID), self::UID . '.ics', $this->event('NEEDS-ACTION'));
        $forged = str_replace('BEGIN:VEVENT', "METHOD:REPLY\r\nBEGIN:VEVENT", $this->event('ACCEPTED'));
        $this->assertSame(204, $this->postItipAs(self::MALLORY_ID, $forged, 'mallory@linagora.com')->getStatus());

        $this->assertSame([], $this->resourceTopics());
    }

    private function writeResourceCopyAs(string $userId, string $partstat): void {
        $this->authBackend->setPrincipal('principals/users/' . $userId);
        $request = new \Sabre\HTTP\Request('PUT', '/calendars/' . self::RESOURCE_ID . '/' . self::RESOURCE_ID . '/' . self::UID . '.ics',
            ['Content-Type' => 'text/calendar'], $this->event($partstat));
        $response = $this->exec($request);

        $this->assertSame(204, $response->getStatus(), (string) $response->getBodyAsString());
    }

    private function postItipAs(string $userId, string $ical, string $recipient = 'alice@linagora.com') {
        $this->authBackend->setPrincipal('principals/users/' . $userId);
        $request = new \Sabre\HTTP\Request('POST', '/itip', ['Content-Type' => 'application/json'], json_encode([
            'method' => 'REPLY', 'uid' => self::UID, 'sequence' => 0,
            'sender' => self::RESOURCE_ID . '@linagora.com', 'recipient' => $recipient, 'ical' => $ical
        ]));

        return $this->exec($request);
    }

    private function exec($request) {
        // The auth plugin keeps whoever authenticated first: each request here has its own user
        (new \ReflectionProperty(\Sabre\DAV\Auth\Plugin::class, 'currentPrincipal'))
            ->setValue($this->server->getPlugin('auth'), null);
        $this->server->httpRequest = $request;
        $this->server->httpResponse = new \Sabre\HTTP\ResponseMock();
        $this->server->exec();

        return $this->server->httpResponse;
    }

    private function publishedReply(): array {
        $deliveries = array_values(array_filter($this->publisher->published,
            fn($message) => $message[0] === AMQPSchedulePlugin::TOPIC_LOCAL_DELIVERY));
        $this->assertCount(1, $deliveries);
        $reply = json_decode($deliveries[0][1], true);
        $this->assertSame('REPLY', $reply['method']);

        return $reply;
    }

    private function resourceTopics(): array {
        return array_values(array_filter(array_column($this->publisher->published, 0),
            fn($topic) => strpos($topic, 'resource:calendar:event:') === 0 && $topic !== 'resource:calendar:event:created'));
    }

    private function event(string $resourcePartstat): string {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Linagora//Twake//EN',
            'BEGIN:VEVENT',
            'UID:' . self::UID,
            'DTSTAMP:20260101T000000Z',
            'DTSTART:20351110T100000Z',
            'DTEND:20351110T110000Z',
            'SUMMARY:Board meeting',
            'ORGANIZER;CN=Alice:mailto:alice@linagora.com',
            'ATTENDEE;PARTSTAT=ACCEPTED:mailto:alice@linagora.com',
            'ATTENDEE;PARTSTAT=NEEDS-ACTION:mailto:mallory@linagora.com',
            'ATTENDEE;CUTYPE=RESOURCE;PARTSTAT=' . $resourcePartstat . ':mailto:' . self::RESOURCE_ID . '@linagora.com',
            'SEQUENCE:0',
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    private function calendarOf(string $userId) {
        return $userId === self::ALICE_ID ? $this->aliceCalendarId
            : $this->caldavBackend->createCalendar('principals/users/' . $userId, 'events', []);
    }

    private function initServer(bool $asynchronousScheduling): void {
        $esndb = (new \MongoDB\Client(ESN_MONGO_ESNURI))->{ESN_MONGO_ESNDB};
        $sabredb = (new \MongoDB\Client(ESN_MONGO_SABREURI))->{ESN_MONGO_SABREDB};
        $sabredb->drop();
        $esndb->drop();

        $esndb->domains->insertOne(['_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID), 'name' => 'linagora.com']);
        foreach ([self::ADMIN_ID => 'admin', self::ALICE_ID => 'alice', self::MALLORY_ID => 'mallory'] as $id => $name) {
            $esndb->users->insertOne([
                '_id' => new \MongoDB\BSON\ObjectId($id),
                'firstname' => ucfirst($name),
                'accounts' => [['type' => 'email', 'emails' => [$name . '@linagora.com']]],
                'domains' => [['domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID)]]
            ]);
        }
        $esndb->resources->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::RESOURCE_ID),
            'name' => 'Meeting Room A',
            'type' => 'calendar',
            'domain' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID)
        ]);

        $authTenant = new \ESN\Utils\AuthTenant(self::ADMIN_ID, self::DOMAIN_ID);
        $principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($esndb, $authTenant);
        $this->caldavBackend = new \ESN\CalDAV\Backend\Mongo($sabredb);
        $calendarRoot = new \ESN\CalDAV\CalendarRoot($principalBackend, $this->caldavBackend, $esndb);
        $calendarRoot->setAuthTenant($authTenant);

        $this->server = new \Sabre\DAV\Server([
            new \Sabre\DAV\SimpleCollection('principals', [
                new \Sabre\CalDAV\Principal\Collection($principalBackend, 'principals/users'),
                new \ESN\CalDAV\Principal\ResourceCollection($principalBackend, 'principals/resources'),
                new \Sabre\CalDAV\Principal\Collection($principalBackend, 'principals/domains')
            ]),
            $calendarRoot
        ]);
        $this->server->sapi = new \Sabre\HTTP\SapiMock();
        $this->server->debugExceptions = true;

        $this->server->addPlugin(new \ESN\CalDAV\Plugin());
        $this->server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
        $this->server->addPlugin(new \Sabre\CalDAV\SharingPlugin());
        $this->authBackend = new \Sabre\DAV\Auth\Backend\Mock();
        $this->server->addPlugin(new \Sabre\DAV\Auth\Plugin($this->authBackend));
        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals/users', 'principals/resources', 'principals/domains'];
        $this->server->addPlugin($aclPlugin);

        $this->publisher = new RecordingResourcePublisher();
        $this->server->addPlugin($asynchronousScheduling
            ? new AMQPSchedulePlugin($this->publisher, $principalBackend)
            : new \ESN\CalDAV\Schedule\Plugin($principalBackend));
        $this->server->addPlugin(new \ESN\CalDAV\Schedule\ITipPlugin());
        $this->server->addPlugin(new EventRealTimePlugin($this->publisher, $this->caldavBackend));

        // Alice organizes the event and invited the room, whose administrator writes its calendar
        $this->aliceCalendarId = $this->caldavBackend->createCalendar('principals/users/' . self::ALICE_ID, 'events', []);
        $this->caldavBackend->createCalendarObject($this->aliceCalendarId, self::UID . '.ics', $this->event('NEEDS-ACTION'));
        $this->resourceCalendarId = $this->caldavBackend->createCalendar('principals/resources/' . self::RESOURCE_ID, self::RESOURCE_ID, []);
        $this->caldavBackend->createCalendarObject($this->resourceCalendarId, self::UID . '.ics', $this->event('NEEDS-ACTION'));
        $this->caldavBackend->updateInvites($this->resourceCalendarId, [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:admin@linagora.com',
                'principal' => 'principals/users/' . self::ADMIN_ID,
                'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE,
                'properties' => []
            ])
        ]);
    }
}

class RecordingResourcePublisher {
    public $published = [];

    function publish($topic, $message) {
        $this->published[] = [$topic, $message];
    }
}
