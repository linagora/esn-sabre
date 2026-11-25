<?php

namespace ESN\CalDAV\Schedule;

/**
 * An organizer invites a resource: the resource's PARTSTAT in the delivered copy is never taken
 * from the organizer's REQUEST, it stays the decision written by the resource's administrators
 * (or by the side service when it auto-accepts).
 *
 * @medium
 */
class ResourcePartStatDeliveryTest extends \PHPUnit\Framework\TestCase {

    const DOMAIN_ID = '54b64eadf6d7d8e41d263e0b';
    const ADMIN_ID = '54b64eadf6d7d8e41d263e0a';
    const ALICE_ID = '54b64eadf6d7d8e41d263e0c';
    const BOB_ID = '54b64eadf6d7d8e41d263e0d';
    const RESOURCE_ID = '68fef2e630c9f300553cf04a';
    const RESOURCE_ADDRESS = 'mailto:68fef2e630c9f300553cf04a@linagora.com';
    const RESOURCE_ALIAS = 'mailto:room-a@linagora.com';
    const UID = 'resource-partstat';

    private $server;
    private $caldavBackend;
    private $authBackend;
    private $aliceCalendarId;
    private $resourceCalendarId;

    function setUp(): void {
        parent::setUp();
        $this->initServer();
    }

    function testOrganizerCannotBookTheResourceAsAccepted() {
        $this->putAliceEvent($this->event('ACCEPTED', 'Board meeting'));

        $this->assertSame('NEEDS-ACTION', $this->resourcePartStat());
    }

    function testOrganizerCannotTurnADeclineBackIntoAnAcceptance() {
        $this->putAliceEvent($this->event('NEEDS-ACTION', 'Board meeting'));
        $this->writeResourceCopyAs(self::ADMIN_ID, $this->event('DECLINED', 'Board meeting'));

        $this->putAliceEvent($this->event('ACCEPTED', 'Board meeting, room please', 1));

        $this->assertSame('DECLINED', $this->resourcePartStat());
        $this->assertStringContainsString('Board meeting, room please', $this->resourceCopy());
    }

    function testAcceptanceWrittenForTheResourceSurvivesLaterRequests() {
        $this->putAliceEvent($this->event('NEEDS-ACTION', 'Board meeting'));
        $this->writeResourceCopyAs(self::ADMIN_ID, $this->event('ACCEPTED', 'Board meeting'));

        $this->putAliceEvent($this->event('NEEDS-ACTION', 'Board meeting, moved', 1));

        $this->assertSame('ACCEPTED', $this->resourcePartStat());
    }

    function testOtherAttendeesStillReceiveTheOrganizerPartStats() {
        $this->putAliceEvent($this->event('ACCEPTED', 'Board meeting', 0, 'TENTATIVE'));

        $bobCopy = $this->copyOf($this->calendarOf(self::BOB_ID));
        $this->assertSame('TENTATIVE', CalendarObjectHelper::attendeePartStat($bobCopy->VEVENT, 'mailto:bob@linagora.com'));
    }

    function testRequestPostedThroughItipKeepsTheStoredPartStat() {
        $this->putAliceEvent($this->event('NEEDS-ACTION', 'Board meeting'));
        $this->writeResourceCopyAs(self::ADMIN_ID, $this->event('DECLINED', 'Board meeting'));

        $request = str_replace('BEGIN:VEVENT', "METHOD:REQUEST\r\nBEGIN:VEVENT", $this->event('ACCEPTED', 'Board meeting', 1));
        $response = $this->postItipAsTheResource($request);

        $this->assertSame(204, $response->getStatus(), (string) $response->getBodyAsString());
        $this->assertSame('DECLINED', $this->resourcePartStat());
    }

    function testOccurrenceTakesTheStoredOccurrenceOrMasterPartStat() {
        $storedCopy = \Sabre\VObject\Reader::read($this->recurringEvent(['ACCEPTED', 'DECLINED']));
        $newObject = \Sabre\VObject\Reader::read($this->recurringEvent(['DECLINED', 'ACCEPTED', 'ACCEPTED']));

        ResourcePartStatGuard::keepStoredPartStats(fn($address) => strtolower($address) === self::RESOURCE_ADDRESS,
            'principals/resources/' . self::RESOURCE_ID, $storedCopy, $newObject);

        $this->assertSame(['ACCEPTED', 'DECLINED', 'ACCEPTED'], array_map(
            fn($vevent) => CalendarObjectHelper::attendeePartStat($vevent, self::RESOURCE_ADDRESS),
            $newObject->select('VEVENT')
        ));
    }

    function testOrganizerCannotBookTheResourceAsAcceptedUnderBothOfItsAddresses() {
        $this->putAliceEvent($this->eventListingBothResourceAddresses('ACCEPTED', 'ACCEPTED', 'Board meeting'));

        $this->assertSame(['NEEDS-ACTION', 'NEEDS-ACTION'], $this->resourcePartStatsOfBothAddresses());
    }

    function testDeclineWrittenOnTheIdAddressHoldsForBothAddressesOfTheResource() {
        $this->putAliceEvent($this->eventListingBothResourceAddresses('NEEDS-ACTION', 'NEEDS-ACTION', 'Board meeting'));
        $this->writeResourceCopyAs(self::ADMIN_ID, $this->eventListingBothResourceAddresses('DECLINED', 'NEEDS-ACTION', 'Board meeting'));

        $this->putAliceEvent($this->eventListingBothResourceAddresses('ACCEPTED', 'ACCEPTED', 'Board meeting, room please', 1));

        $this->assertSame(['DECLINED', 'DECLINED'], $this->resourcePartStatsOfBothAddresses());
    }

    function testReplyOfTheResourceIsNotPropagatedBackToItsOtherAddress() {
        $this->putAliceEvent($this->eventListingBothResourceAddresses('NEEDS-ACTION', 'NEEDS-ACTION', 'Board meeting'));
        $deliveries = [];
        $this->server->on('schedule', function (\Sabre\VObject\ITip\Message $message) use (&$deliveries) {
            $deliveries[] = $message->method . ' ' . strtolower($message->recipient);
        });

        $this->writeResourceCopyAs(self::ADMIN_ID, $this->eventListingBothResourceAddresses('ACCEPTED', 'NEEDS-ACTION', 'Board meeting'));

        $this->assertContains('REPLY mailto:alice@linagora.com', $deliveries);
        $this->assertContains('REQUEST mailto:bob@linagora.com', $deliveries);
        $this->assertNotContains('REQUEST ' . self::RESOURCE_ALIAS, $deliveries);
        $this->assertNotContains('REQUEST ' . self::RESOURCE_ADDRESS, $deliveries);
    }

    private function resourcePartStatsOfBothAddresses(): array {
        $vevent = \Sabre\VObject\Reader::read($this->resourceCopy())->VEVENT;

        return [
            CalendarObjectHelper::attendeePartStat($vevent, self::RESOURCE_ADDRESS),
            CalendarObjectHelper::attendeePartStat($vevent, self::RESOURCE_ALIAS)
        ];
    }

    private function eventListingBothResourceAddresses(string $idPartstat, string $aliasPartstat, string $summary, int $sequence = 0): string {
        return str_replace("\r\nSEQUENCE:", "\r\nATTENDEE;CUTYPE=RESOURCE;PARTSTAT=$aliasPartstat:" . self::RESOURCE_ALIAS . "\r\nSEQUENCE:",
            $this->event($idPartstat, $summary, $sequence));
    }

    private function putAliceEvent(string $ical): void {
        $this->authBackend->setPrincipal('principals/users/' . self::ALICE_ID);
        $response = $this->exec(new \Sabre\HTTP\Request('PUT', '/calendars/' . self::ALICE_ID . '/events/' . self::UID . '.ics',
            ['Content-Type' => 'text/calendar'], $ical));

        $this->assertContains($response->getStatus(), [201, 204], (string) $response->getBodyAsString());
    }

    private function writeResourceCopyAs(string $userId, string $ical): void {
        $this->authBackend->setPrincipal('principals/users/' . $userId);
        $response = $this->exec(new \Sabre\HTTP\Request('PUT', '/' . $this->resourceCopyPath(),
            ['Content-Type' => 'text/calendar'], $ical));

        $this->assertSame(204, $response->getStatus(), (string) $response->getBodyAsString());
    }

    /**
     * Asynchronous scheduling: the side service hands the REQUEST over on behalf of its recipient.
     */
    private function postItipAsTheResource(string $ical) {
        $this->authBackend->setPrincipal('principals/resources/' . self::RESOURCE_ID);

        return $this->exec(new \Sabre\HTTP\Request('POST', '/itip', ['Content-Type' => 'application/json'], json_encode([
            'method' => 'REQUEST', 'uid' => self::UID, 'sequence' => 1,
            'sender' => 'alice@linagora.com', 'recipient' => self::RESOURCE_ID . '@linagora.com', 'ical' => $ical
        ])));
    }

    private function exec($request) {
        // The auth plugin keeps whoever authenticated first: each request here has its own user
        (new \ReflectionProperty(\Sabre\DAV\Auth\Plugin::class, 'currentPrincipal'))
            ->setValue($this->server->getPlugin('auth'), null);
        // Production builds a fresh tree per request: drop the nodes a previous request loaded
        $this->server->tree->markDirty('calendars');
        $this->server->httpRequest = $request;
        $this->server->httpResponse = new \Sabre\HTTP\ResponseMock();
        $this->server->exec();

        return $this->server->httpResponse;
    }

    private function resourcePartStat(): ?string {
        return CalendarObjectHelper::attendeePartStat(
            \Sabre\VObject\Reader::read($this->resourceCopy())->VEVENT,
            self::RESOURCE_ADDRESS
        );
    }

    private function resourceCopy(): string {
        $objects = $this->caldavBackend->getCalendarObjects($this->resourceCalendarId);
        $this->assertCount(1, $objects, 'the resource holds one copy of the event');

        return $this->caldavBackend->getCalendarObject($this->resourceCalendarId, $objects[0]['uri'])['calendardata'];
    }

    private function resourceCopyPath(): string {
        $objects = $this->caldavBackend->getCalendarObjects($this->resourceCalendarId);
        $this->assertCount(1, $objects, 'the resource holds one copy of the event');

        return 'calendars/' . self::RESOURCE_ID . '/' . self::RESOURCE_ID . '/' . $objects[0]['uri'];
    }

    private function copyOf($calendarId) {
        $objects = $this->caldavBackend->getCalendarObjects($calendarId);
        $this->assertCount(1, $objects);

        return \Sabre\VObject\Reader::read($this->caldavBackend->getCalendarObject($calendarId, $objects[0]['uri'])['calendardata']);
    }

    private function event(string $resourcePartstat, string $summary, int $sequence = 0, string $bobPartstat = 'NEEDS-ACTION'): string {
        return implode("\r\n", [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Linagora//Twake//EN',
            'BEGIN:VEVENT',
            'UID:' . self::UID,
            'DTSTAMP:20260101T000000Z',
            'DTSTART:20351110T100000Z',
            'DTEND:20351110T110000Z',
            'SUMMARY:' . $summary,
            'ORGANIZER;CN=Alice:mailto:alice@linagora.com',
            'ATTENDEE;PARTSTAT=ACCEPTED:mailto:alice@linagora.com',
            'ATTENDEE;PARTSTAT=' . $bobPartstat . ':mailto:bob@linagora.com',
            'ATTENDEE;CUTYPE=RESOURCE;PARTSTAT=' . $resourcePartstat . ':' . self::RESOURCE_ADDRESS,
            'SEQUENCE:' . $sequence,
            'END:VEVENT',
            'END:VCALENDAR',
            '',
        ]);
    }

    /**
     * The master then one override per extra PARTSTAT, on the following days.
     */
    private function recurringEvent(array $resourcePartstats): string {
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Linagora//Twake//EN'];
        foreach ($resourcePartstats as $index => $partstat) {
            $day = sprintf('203511%02dT100000Z', 10 + $index);
            $lines = array_merge($lines, [
                'BEGIN:VEVENT',
                'UID:' . self::UID,
                'DTSTAMP:20260101T000000Z',
                'DTSTART:' . $day,
                'DURATION:PT1H',
            ], $index === 0 ? ['RRULE:FREQ=DAILY;COUNT=5'] : ['RECURRENCE-ID:' . $day], [
                'ORGANIZER:mailto:alice@linagora.com',
                'ATTENDEE;CUTYPE=RESOURCE;PARTSTAT=' . $partstat . ':' . self::RESOURCE_ADDRESS,
                'END:VEVENT',
            ]);
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", $lines) . "\r\n";
    }

    private function calendarOf(string $userId) {
        $calendars = $this->caldavBackend->getCalendarsForUser('principals/users/' . $userId);

        return $calendars[0]['id'];
    }

    private function initServer(): void {
        $esndb = (new \MongoDB\Client(ESN_MONGO_ESNURI))->{ESN_MONGO_ESNDB};
        $sabredb = (new \MongoDB\Client(ESN_MONGO_SABREURI))->{ESN_MONGO_SABREDB};
        $sabredb->drop();
        $esndb->drop();

        $esndb->domains->insertOne(['_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID), 'name' => 'linagora.com']);
        foreach ([self::ADMIN_ID => 'admin', self::ALICE_ID => 'alice', self::BOB_ID => 'bob'] as $id => $name) {
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
            'emailAddress' => 'room-a@linagora.com',
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
        $this->server->addPlugin(new Plugin($principalBackend));
        $this->server->addPlugin(new ITipPlugin());

        $this->aliceCalendarId = $this->caldavBackend->createCalendar('principals/users/' . self::ALICE_ID, 'events', []);
        $this->caldavBackend->createCalendar('principals/users/' . self::BOB_ID, 'events', []);
        $this->resourceCalendarId = $this->caldavBackend->createCalendar('principals/resources/' . self::RESOURCE_ID, self::RESOURCE_ID, []);
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
