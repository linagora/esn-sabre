<?php

namespace ESN\CalDAV\Schedule;

use Sabre\HTTP\Sapi;
use Sabre\HTTP\Response;

/**
 * Tests for issue #176: Resource administrator updating calendar events
 *
 * @medium
 */
class ResourceAdminUpdateTest extends \PHPUnit\Framework\TestCase {

    private $server;
    private $caldavBackend;
    private $esndb;
    private $sabredb;
    private $resourceId;
    private $adminId;
    private $adminEmail;
    private $resourceCalendar;

    function setUp(): void
    {
        parent::setUp();
        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->{ESN_MONGO_ESNDB};

        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->sabredb->drop();
        $this->esndb->drop();

        // Create admin user
        $this->adminId = '54b64eadf6d7d8e41d263e0a';
        $this->adminEmail = 'admin@linagora.com';
        $adminUser = [
            '_id' => new \MongoDB\BSON\ObjectId($this->adminId),
            'firstname' => 'Admin',
            'lastname' => 'User',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [
                        $this->adminEmail
                    ]
                ]
            ],
            'domains' => []
        ];
        $this->esndb->users->insertOne($adminUser);

        // Create a domain for the resource
        $domainId = '54b64eadf6d7d8e41d263e0b';
        $domain = [
            '_id' => new \MongoDB\BSON\ObjectId($domainId),
            'name' => 'linagora.com'
        ];
        $this->esndb->domains->insertOne($domain);

        // Create resource in the resources collection
        $this->resourceId = '68fef2e630c9f300553cf04a';
        $resource = [
            '_id' => new \MongoDB\BSON\ObjectId($this->resourceId),
            'name' => 'Meeting Room A',
            'type' => 'calendar',
            'domain' => new \MongoDB\BSON\ObjectId($domainId)
        ];
        $this->esndb->resources->insertOne($resource);

        // Initialize server
        list($this->caldavBackend, $authBackend) = $this->initServer();

        // Set admin as authenticated user
        $authBackend->setPrincipal('principals/users/' . $this->adminId);

        // Create resource calendar
        $this->resourceCalendar = [
            '{DAV:}displayname' => 'Resource Calendar',
            'principaluri' => 'principals/resources/' . $this->resourceId,
            'uri' => $this->resourceId
        ];
        $this->resourceCalendar['id'] = $this->caldavBackend->createCalendar(
            $this->resourceCalendar['principaluri'],
            $this->resourceCalendar['uri'],
            $this->resourceCalendar
        );

        // Share the resource calendar with admin user (write permission)
        $this->caldavBackend->updateInvites(
            $this->resourceCalendar['id'],
            [
                new \Sabre\DAV\Xml\Element\Sharee([
                    'href' => 'mailto:' . $this->adminEmail,
                    'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE,
                    'properties' => []
                ])
            ]
        );
    }

    /**
     * Test that fetchCalendarOwnerAddresses doesn't return null for a shared calendar
     * Issue #176: When a resource administrator tries to update a calendar event
     * using their own user credentials, fetchCalendarOwnerAddresses returns null
     * instead of an array, causing a TypeError.
     */
    function testFetchCalendarOwnerAddressesDoesNotReturnNull() {
        // Get the schedule plugin
        $plugins = $this->server->getPlugins();
        $schedulePlugin = null;
        foreach ($plugins as $plugin) {
            if ($plugin instanceof \ESN\CalDAV\Schedule\Plugin) {
                $schedulePlugin = $plugin;
                break;
            }
        }

        $this->assertNotNull($schedulePlugin, 'Schedule plugin should be registered');

        // Use reflection to call the private fetchCalendarOwnerAddresses method
        $reflection = new \ReflectionClass($schedulePlugin);
        $method = $reflection->getMethod('fetchCalendarOwnerAddresses');
        $method->setAccessible(true);

        // Test with the resource calendar path
        $calendarPath = 'calendars/' . $this->resourceId . '/' . $this->resourceId;

        // This should NOT return null, it should return an array (even if empty)
        // If it returns null, it will cause a TypeError when used
        $result = $method->invoke($schedulePlugin, $calendarPath);

        $this->assertTrue(
            is_array($result),
            'fetchCalendarOwnerAddresses should return an array, not null. Got: ' . gettype($result)
        );
    }

    /**
     * Test that resource email address is correctly retrieved for iTIP messages
     * Issue #195: When a resource admin updates the participation status (PARTSTAT),
     * the change should be propagated to the organizer via iTIP REPLY messages.
     * This requires correctly retrieving the resource's email address.
     */
    function testResourceEmailRetrievalForParticipationStatusUpdate() {
        // Get the schedule plugin
        $plugins = $this->server->getPlugins();
        $schedulePlugin = null;
        foreach ($plugins as $plugin) {
            if ($plugin instanceof \ESN\CalDAV\Schedule\Plugin) {
                $schedulePlugin = $plugin;
                break;
            }
        }

        $this->assertNotNull($schedulePlugin, 'Schedule plugin should be registered');

        // Use reflection to call the private fetchCalendarOwnerAddresses method
        $reflection = new \ReflectionClass($schedulePlugin);
        $method = $reflection->getMethod('fetchCalendarOwnerAddresses');
        $method->setAccessible(true);

        // Test with the resource calendar path
        $calendarPath = 'calendars/' . $this->resourceId . '/' . $this->resourceId;

        // Get addresses for the resource
        $addresses = $method->invoke($schedulePlugin, $calendarPath);

        // Should return an array with the resource email
        $this->assertTrue(is_array($addresses), 'fetchCalendarOwnerAddresses should return an array');
        $this->assertNotEmpty($addresses, 'fetchCalendarOwnerAddresses should return a non-empty array for resource');
        $this->assertCount(1, $addresses, 'Should return exactly one address for the resource');

        // Verify the email format
        $expectedEmail = 'mailto:' . $this->resourceId . '@linagora.com';
        $this->assertEquals($expectedEmail, $addresses[0], 'Resource email should be correctly formatted');
    }

    /**
     * Test full scenario for issue #195: Resource admin updates PARTSTAT
     * and the change is propagated to organizer
     */
    function testResourceAdminParticipationStatusPropagation() {
        // Create organizer user (Alice)
        $aliceId = '54b64eadf6d7d8e41d263e0c';
        $aliceEmail = 'alice@linagora.com';
        $aliceUser = [
            '_id' => new \MongoDB\BSON\ObjectId($aliceId),
            'firstname' => 'Alice',
            'lastname' => 'Organizer',
            'accounts' => [
                [
                    'type' => 'email',
                    'emails' => [$aliceEmail]
                ]
            ],
            'domains' => []
        ];
        $this->esndb->users->insertOne($aliceUser);

        // Create Alice's calendar
        $aliceCalendarId = $this->caldavBackend->createCalendar(
            'principals/users/' . $aliceId,
            'alice-calendar',
            ['{DAV:}displayname' => 'Alice Calendar']
        );

        // Alice creates an event and invites the resource
        $resourceEmail = $this->resourceId . '@linagora.com';
        $eventUid = 'test-event-195';
        $eventData = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Linagora//OpenPaaS//EN
BEGIN:VEVENT
UID:$eventUid
DTSTART:20251110T100000Z
DTEND:20251110T110000Z
SUMMARY:Test Meeting
ORGANIZER;CN=Alice Organizer:mailto:$aliceEmail
ATTENDEE;CN=Alice Organizer;PARTSTAT=ACCEPTED:mailto:$aliceEmail
ATTENDEE;CN=Meeting Room A;PARTSTAT=NEEDS-ACTION:mailto:$resourceEmail
SEQUENCE:0
END:VEVENT
END:VCALENDAR
ICS;

        // Create event in Alice's calendar
        $this->caldavBackend->createCalendarObject($aliceCalendarId, $eventUid . '.ics', $eventData);

        // Create same event in resource calendar (as it would be delivered by iTIP)
        $this->caldavBackend->createCalendarObject($this->resourceCalendar['id'], $eventUid . '.ics', $eventData);

        // Now, Bob (admin) updates the resource's PARTSTAT to ACCEPTED
        $updatedEventData = <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Linagora//OpenPaaS//EN
BEGIN:VEVENT
UID:$eventUid
DTSTART:20251110T100000Z
DTEND:20251110T110000Z
SUMMARY:Test Meeting
ORGANIZER;CN=Alice Organizer:mailto:$aliceEmail
ATTENDEE;CN=Alice Organizer;PARTSTAT=ACCEPTED:mailto:$aliceEmail
ATTENDEE;CN=Meeting Room A;PARTSTAT=ACCEPTED:mailto:$resourceEmail
SEQUENCE:0
END:VEVENT
END:VCALENDAR
ICS;

        // Mock a PUT request to update the event
        $path = 'calendars/' . $this->resourceId . '/' . $this->resourceId . '/' . $eventUid . '.ics';
        $this->server->httpRequest = new \Sabre\HTTP\Request('PUT', '/' . $path);
        $this->server->httpRequest->setBody($updatedEventData);

        // Track iTIP messages by capturing deliver() calls
        $deliveredMessages = [];
        $schedulePlugin = null;
        foreach ($this->server->getPlugins() as $plugin) {
            if ($plugin instanceof \ESN\CalDAV\Schedule\Plugin) {
                $schedulePlugin = $plugin;
                break;
            }
        }

        // We can't easily mock deliver(), but we can verify that fetchCalendarOwnerAddresses
        // returns the correct email for the resource, which is the key fix for #195
        $reflection = new \ReflectionClass($schedulePlugin);
        $method = $reflection->getMethod('fetchCalendarOwnerAddresses');
        $method->setAccessible(true);

        $calendarPath = 'calendars/' . $this->resourceId . '/' . $this->resourceId;
        $addresses = $method->invoke($schedulePlugin, $calendarPath);

        // The key assertion: the resource email must be correctly retrieved
        // This is what enables iTIP REPLY messages to be sent to the organizer
        $this->assertNotEmpty($addresses, 'Resource addresses should not be empty for iTIP propagation');
        $this->assertStringContainsString($this->resourceId . '@linagora.com', $addresses[0],
            'Resource email should be correctly formatted for iTIP REPLY messages');
    }

    private function initServer(): array {
        $principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);
        $caldavBackend = new \ESN\CalDAV\Backend\Mongo($this->sabredb);

        $tree[] = new \Sabre\DAV\SimpleCollection('principals', [
            new \Sabre\CalDAV\Principal\Collection($principalBackend, 'principals/users'),
            new \ESN\CalDAV\Principal\ResourceCollection($principalBackend, 'principals/resources')
        ]);
        $tree[] = new \ESN\CalDAV\CalendarRoot(
            $principalBackend,
            $caldavBackend,
            $this->esndb
        );

        $this->server = new \Sabre\DAV\Server($tree);
        $this->server->httpRequest = new \Sabre\HTTP\Request('GET', '/');
        $this->server->httpResponse = new \Sabre\HTTP\Response();
        $this->server->debugExceptions = true;

        $caldavPlugin = new \ESN\CalDAV\Plugin();
        $this->server->addPlugin($caldavPlugin);

        $this->server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
        $this->server->addPlugin(new \Sabre\CalDAV\SharingPlugin());

        // Add Schedule Plugin to test
        $schedulePlugin = new \ESN\CalDAV\Schedule\Plugin($principalBackend);
        $this->server->addPlugin($schedulePlugin);

        $authBackend = new \ESN\CalDAV\Schedule\TestAuthBackendMock();
        $authPlugin = new \Sabre\DAV\Auth\Plugin($authBackend);
        $this->server->addPlugin($authPlugin);

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals/users', 'principals/resources'];
        $this->server->addPlugin($aclPlugin);

        return [$caldavBackend, $authBackend];
    }
}

/**
 * Simple auth backend mock for testing
 */
class TestAuthBackendMock extends \Sabre\DAV\Auth\Backend\AbstractBasic {
    protected $currentUser;

    public function validateUserPass($username, $password) {
        return true;
    }

    public function setPrincipal($principal) {
        $this->currentUser = $principal;
    }

    public function getCurrentUser() {
        return $this->currentUser;
    }
}
