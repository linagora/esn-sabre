<?php

namespace ESN\CalDAV\Schedule;

require_once 'Sabre/HTTP/ResponseMock.php';
require_once 'Sabre/HTTP/SapiMock.php';
require_once 'Sabre/DAVACL/PrincipalBackend/Mock.php';
require_once 'Sabre/DAV/Auth/Backend/Mock.php';

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

    private function initServer(): array {
        $principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);
        $caldavBackend = new \ESN\CalDAV\Backend\Mongo($this->sabredb);

        $tree[] = new \Sabre\DAV\SimpleCollection('principals', [
            new \Sabre\CalDAV\Principal\Collection($principalBackend, 'principals/users')
        ]);
        $tree[] = new \ESN\CalDAV\CalendarRoot(
            $principalBackend,
            $caldavBackend,
            $this->esndb
        );

        $this->server = new \Sabre\DAV\Server($tree);
        $this->server->sapi = new \Sabre\HTTP\SapiMock();
        $this->server->debugExceptions = true;

        $caldavPlugin = new \ESN\CalDAV\Plugin();
        $this->server->addPlugin($caldavPlugin);

        $this->server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
        $this->server->addPlugin(new \Sabre\CalDAV\SharingPlugin());

        // Add Schedule Plugin to test
        $schedulePlugin = new \ESN\CalDAV\Schedule\Plugin();
        $this->server->addPlugin($schedulePlugin);

        $authBackend = new \Sabre\DAV\Auth\Backend\Mock();
        $authPlugin = new \Sabre\DAV\Auth\Plugin($authBackend);
        $this->server->addPlugin($authPlugin);

        $aclPlugin = new \Sabre\DAVACL\Plugin();
        $aclPlugin->principalCollectionSet = ['principals/users'];
        $this->server->addPlugin($aclPlugin);

        return [$caldavBackend, $authBackend];
    }
}
