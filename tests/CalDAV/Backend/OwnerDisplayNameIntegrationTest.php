<?php

namespace ESN\CalDAV\Backend;

require_once 'AbstractDatabaseTest.php';

/**
 * Integration tests for owner display name appending functionality
 * Tests subscriptions and sharing with owner names
 *
 * @medium
 */
class OwnerDisplayNameIntegrationTest extends AbstractDatabaseTest {
    protected $principalBackend;
    protected $server;
    protected $db;

    protected function generateId() {
        return [(string) new \MongoDB\BSON\ObjectId(), (string) new \MongoDB\BSON\ObjectId()];
    }

    protected function getBackend() {
        $mc = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->db = $mc->{ESN_MONGO_SABREDB};
        $this->db->drop();
        return new Mongo($this->db);
    }

    function setUp(): void {
        parent::setUp();

        // Get backend to initialize db
        $backend = $this->getBackend();

        // Setup principal backend with test user data
        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->db);

        // Insert test users with display names
        $usersCollection = $this->db->users;
        $usersCollection->insertMany([
            [
                '_id' => new \MongoDB\BSON\ObjectId('54b64eadf6d7d8e41d263e0e'),
                'firstname' => 'Michel',
                'lastname' => 'MAUDET',
                'emails' => ['michel.maudet@example.com'],
                'domains' => []
            ],
            [
                '_id' => new \MongoDB\BSON\ObjectId('54b64eadf6d7d8e41d263e0f'),
                'firstname' => 'Jean',
                'lastname' => 'DUPONT',
                'emails' => ['jean.dupont@example.com'],
                'domains' => []
            ]
        ]);

        // Setup mock server for backend
        $this->server = new \Sabre\DAV\Server();

        // Add Auth plugin BEFORE ACL plugin (required by SabreDAV)
        $authPlugin = new \Sabre\DAV\Auth\Plugin(new \Sabre\DAV\Auth\Backend\BasicCallBack(function() {
            return true; // Allow unauthenticated access for tests
        }));
        $this->server->addPlugin($authPlugin);

        // Now add ACL plugin
        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalBackend = $this->principalBackend;
        $this->server->addPlugin($aclPlugin);
    }

    function testConstruct() {
        $backend = $this->getBackend();
        $this->assertTrue($backend instanceof Mongo);
    }

    /**
     * Test that subscription displayname includes owner name
     * @depends testConstruct
     */
    function testCreateSubscriptionWithOwnerName() {
        $backend = $this->getBackend();
        $backend->server = $this->server;

        // Create a calendar owned by Michel MAUDET
        $calendarId = $backend->createCalendar(
            'principals/users/54b64eadf6d7d8e41d263e0e',
            'publicCal1',
            [
                '{DAV:}displayname' => 'Villa',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' =>
                    new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(['VEVENT'])
            ]
        );

        // Create subscription to this calendar by Jean DUPONT
        $subscriptionId = $backend->createSubscription(
            'principals/users/54b64eadf6d7d8e41d263e0f',
            'subscription1',
            [
                '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href('calendars/54b64eadf6d7d8e41d263e0e/publicCal1'),
                '{DAV:}displayname' => 'Villa'
            ]
        );

        // Fetch subscriptions for Jean DUPONT
        $subscriptions = $backend->getSubscriptionsForUser('principals/users/54b64eadf6d7d8e41d263e0f');

        $this->assertCount(1, $subscriptions);
        $this->assertArrayHasKey('{DAV:}displayname', $subscriptions[0]);

        // Verify owner name is appended
        $this->assertEquals('Villa (Michel MAUDET)', $subscriptions[0]['{DAV:}displayname']);
    }

    /**
     * Test subscription with no displayname provided
     * @depends testConstruct
     */
    function testCreateSubscriptionWithoutDisplayname() {
        $backend = $this->getBackend();
        $backend->server = $this->server;

        // Create a calendar
        $backend->createCalendar(
            'principals/users/54b64eadf6d7d8e41d263e0e',
            'cal1',
            [
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' =>
                    new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(['VEVENT'])
            ]
        );

        // Create subscription without displayname
        $backend->createSubscription(
            'principals/users/54b64eadf6d7d8e41d263e0f',
            'subscription1',
            [
                '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href('calendars/54b64eadf6d7d8e41d263e0e/cal1')
            ]
        );

        $subscriptions = $backend->getSubscriptionsForUser('principals/users/54b64eadf6d7d8e41d263e0f');

        $this->assertCount(1, $subscriptions);
        // Should use default "Calendar" with owner name
        $this->assertEquals('Calendar (Michel MAUDET)', $subscriptions[0]['{DAV:}displayname']);
    }

    /**
     * Test that shared calendar displayname includes owner name
     * @depends testConstruct
     */
    function testShareCalendarWithOwnerName() {
        $backend = $this->getBackend();
        $backend->server = $this->server;

        // Create a calendar owned by Michel MAUDET
        $calendarId = $backend->createCalendar(
            'principals/users/54b64eadf6d7d8e41d263e0e',
            'sharedCal',
            [
                '{DAV:}displayname' => 'Calendrier Partagé',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' =>
                    new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(['VEVENT'])
            ]
        );

        // Share calendar with Jean DUPONT
        $sharees = [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:jean.dupont@example.com',
                'principal' => 'principals/users/54b64eadf6d7d8e41d263e0f',
                'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_READ,
                'properties' => []
            ])
        ];

        $backend->updateInvites($calendarId, $sharees);

        // Fetch calendars for Jean DUPONT (sharee)
        $calendars = $backend->getCalendarsForUser('principals/users/54b64eadf6d7d8e41d263e0f');

        // Find the shared calendar
        $sharedCalendar = null;
        foreach ($calendars as $cal) {
            if (isset($cal['{http://calendarserver.org/ns/}shared-url'])) {
                $sharedCalendar = $cal;
                break;
            }
        }

        $this->assertNotNull($sharedCalendar, 'Shared calendar should be found');
        $this->assertArrayHasKey('{DAV:}displayname', $sharedCalendar);

        // Verify owner name is appended
        $this->assertEquals('Calendrier Partagé (Michel MAUDET)', $sharedCalendar['{DAV:}displayname']);
    }

    /**
     * Test sharing calendar with custom displayname for sharee
     * @depends testConstruct
     */
    function testShareCalendarWithCustomDisplayname() {
        $backend = $this->getBackend();
        $backend->server = $this->server;

        // Create calendar
        $calendarId = $backend->createCalendar(
            'principals/users/54b64eadf6d7d8e41d263e0e',
            'workCal',
            [
                '{DAV:}displayname' => 'Travail',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' =>
                    new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(['VEVENT'])
            ]
        );

        // Share with custom displayname for sharee (should be ignored, use original + owner)
        $sharees = [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href' => 'mailto:jean.dupont@example.com',
                'principal' => 'principals/users/54b64eadf6d7d8e41d263e0f',
                'access' => \Sabre\DAV\Sharing\Plugin::ACCESS_READWRITE,
                'properties' => [
                    '{DAV:}displayname' => 'Custom Name'
                ]
            ])
        ];

        $backend->updateInvites($calendarId, $sharees);

        $calendars = $backend->getCalendarsForUser('principals/users/54b64eadf6d7d8e41d263e0f');

        $sharedCalendar = null;
        foreach ($calendars as $cal) {
            if (isset($cal['{http://calendarserver.org/ns/}shared-url'])) {
                $sharedCalendar = $cal;
                break;
            }
        }

        $this->assertNotNull($sharedCalendar);
        // Should use calendar's displayname + owner, not custom name from sharee
        $this->assertEquals('Travail (Michel MAUDET)', $sharedCalendar['{DAV:}displayname']);
    }

    /**
     * Test that existing calendars (not shared/subscribed) don't get owner names
     * @depends testConstruct
     */
    function testOwnCalendarNoOwnerName() {
        $backend = $this->getBackend();
        $backend->server = $this->server;

        // Create user's own calendar
        $backend->createCalendar(
            'principals/users/54b64eadf6d7d8e41d263e0e',
            'myCal',
            [
                '{DAV:}displayname' => 'Mon Calendrier',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' =>
                    new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(['VEVENT'])
            ]
        );

        $calendars = $backend->getCalendarsForUser('principals/users/54b64eadf6d7d8e41d263e0e');

        $this->assertCount(1, $calendars);
        $this->assertArrayHasKey('{DAV:}displayname', $calendars[0]);

        // Own calendar should NOT have owner name appended
        $this->assertEquals('Mon Calendrier', $calendars[0]['{DAV:}displayname']);
    }

    /**
     * Test subscription when server/principal backend not available
     * @depends testConstruct
     */
    function testSubscriptionWithoutServer() {
        $backend = $this->getBackend();
        // Don't set backend->server, resolver won't initialize

        // Create calendar
        $backend->createCalendar(
            'principals/users/54b64eadf6d7d8e41d263e0e',
            'cal1',
            [
                '{DAV:}displayname' => 'Villa',
                '{urn:ietf:params:xml:ns:caldav}supported-calendar-component-set' =>
                    new \Sabre\CalDAV\Xml\Property\SupportedCalendarComponentSet(['VEVENT'])
            ]
        );

        // Create subscription - should work but without owner name
        $backend->createSubscription(
            'principals/users/54b64eadf6d7d8e41d263e0f',
            'sub1',
            [
                '{http://calendarserver.org/ns/}source' => new \Sabre\DAV\Xml\Property\Href('calendars/54b64eadf6d7d8e41d263e0e/cal1'),
                '{DAV:}displayname' => 'Villa'
            ]
        );

        $subscriptions = $backend->getSubscriptionsForUser('principals/users/54b64eadf6d7d8e41d263e0f');

        $this->assertCount(1, $subscriptions);
        // Without resolver, displayname should remain as provided
        $this->assertEquals('Villa', $subscriptions[0]['{DAV:}displayname']);
    }
}
