<?php

namespace ESN\DAVACL;

use Sabre\HTTP\Request;

/**
 * Issue #441: an administrator of a resource must be able to update
 * participation on behalf of the resource by writing directly to the
 * resource calendar (calendars/{resourceId}/{resourceId}/*.ics).
 *
 * The administrator link lives in the resource document (`administrators`
 * field), not as an explicit calendar share, so being an administrator has to
 * grant the resource owner's write privileges through group membership.
 *
 * @medium
 */
class ResourceAdminAclTest extends \PHPUnit\Framework\TestCase {

    private $server;
    private $caldavBackend;
    private $esndb;
    private $sabredb;
    private $aclPlugin;
    private $authPlugin;

    private $resourceId = '68fef2e630c9f300553cf04a';
    private $adminId = '54b64eadf6d7d8e41d263e0a';
    private $strangerId = '54b64eadf6d7d8e41d263e0d';
    private $domainId = '54b64eadf6d7d8e41d263e0b';
    private $eventUid = 'issue-441-event';

    function setUp(): void {
        parent::setUp();

        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->{ESN_MONGO_ESNDB};

        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->sabredb->drop();
        $this->esndb->drop();

        $this->esndb->domains->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($this->domainId),
            'name' => 'linagora.com'
        ]);

        foreach ([$this->adminId, $this->strangerId] as $userId) {
            $this->esndb->users->insertOne([
                '_id' => new \MongoDB\BSON\ObjectId($userId),
                'firstname' => 'User',
                'lastname' => $userId,
                'accounts' => [
                    [ 'type' => 'email', 'emails' => [ $userId . '@linagora.com' ] ]
                ],
                'domains' => [[ 'domain_id' => new \MongoDB\BSON\ObjectId($this->domainId) ]]
            ]);
        }

        // The admin link is stored in the resource document, exactly like the
        // production data model reported in issue #441 (no explicit share).
        $this->esndb->resources->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId($this->resourceId),
            'name' => 'Meeting Room A',
            'type' => 'calendar',
            'domain' => new \MongoDB\BSON\ObjectId($this->domainId),
            'administrators' => [
                [ 'objectType' => 'user', 'id' => new \MongoDB\BSON\ObjectId($this->adminId) ]
            ]
        ]);

        $this->initServer();

        // Resource calendar plus an event to write onto.
        $calendarId = $this->caldavBackend->createCalendar(
            'principals/resources/' . $this->resourceId,
            $this->resourceId,
            [ '{DAV:}displayname' => 'Resource Calendar' ]
        );
        $this->caldavBackend->createCalendarObject($calendarId, $this->eventUid . '.ics', <<<ICS
BEGIN:VCALENDAR
VERSION:2.0
BEGIN:VEVENT
UID:{$this->eventUid}
DTSTART:20260725T090000Z
DTEND:20260725T093000Z
SUMMARY:Booking
END:VEVENT
END:VCALENDAR
ICS);
    }

    function testResourceAdministratorHasWriteContentOnResourceCalendar() {
        $privileges = $this->currentUserPrivilegesFor($this->adminId);

        $this->assertContains('{DAV:}write-content', $privileges,
            'A resource administrator must be able to write to the resource calendar');
    }

    function testNonAdministratorHasNoWriteContentOnResourceCalendar() {
        $privileges = $this->currentUserPrivilegesFor($this->strangerId);

        $this->assertNotContains('{DAV:}write-content', $privileges,
            'A user who does not administer the resource must not get write access');
    }

    private function currentUserPrivilegesFor($userId): array {
        $path = 'calendars/' . $this->resourceId . '/' . $this->resourceId . '/' . $this->eventUid . '.ics';

        $request = new Request('PUT', '/' . $path);
        $request->setHeader('Authorization', 'Basic ' . base64_encode('principals/users/' . $userId . ':secret'));
        $this->server->httpRequest = $request;
        $this->authPlugin->beforeMethod($request, $this->server->httpResponse);

        return $this->aclPlugin->getCurrentUserPrivilegeSet($path);
    }

    private function initServer(): void {
        $authTenant = new \ESN\Utils\AuthTenant($this->adminId, $this->domainId);
        $principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb, $authTenant);
        $this->caldavBackend = new \ESN\CalDAV\Backend\Mongo($this->sabredb);

        $tree = [];
        $tree[] = new \Sabre\DAV\SimpleCollection('principals', [
            new \Sabre\CalDAV\Principal\Collection($principalBackend, 'principals/users'),
            new \ESN\CalDAV\Principal\ResourceCollection($principalBackend, 'principals/resources'),
            new \Sabre\CalDAV\Principal\Collection($principalBackend, 'principals/domains')
        ]);
        $calendarRoot = new \ESN\CalDAV\CalendarRoot($principalBackend, $this->caldavBackend, $this->esndb);
        $calendarRoot->setAuthTenant($authTenant);
        $tree[] = $calendarRoot;

        $this->server = new \Sabre\DAV\Server($tree);
        $this->server->httpResponse = new \Sabre\HTTP\Response();
        $this->server->debugExceptions = true;

        $this->server->addPlugin(new \ESN\CalDAV\Plugin());
        $this->server->addPlugin(new \Sabre\DAV\Sharing\Plugin());
        $this->server->addPlugin(new \Sabre\CalDAV\SharingPlugin());

        $this->authPlugin = new \Sabre\DAV\Auth\Plugin(new ResourceAdminAuthBackendMock());
        $this->server->addPlugin($this->authPlugin);

        $this->aclPlugin = new \Sabre\DAVACL\Plugin();
        $this->aclPlugin->principalCollectionSet = ['principals/users', 'principals/resources'];
        $this->server->addPlugin($this->aclPlugin);
    }
}

/**
 * Auth backend that trusts the principal encoded in the Basic credentials so a
 * test can act as an arbitrary user.
 */
class ResourceAdminAuthBackendMock implements \Sabre\DAV\Auth\Backend\BackendInterface {

    function check(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) {
        $auth = new \Sabre\HTTP\Auth\Basic('SabreDAV', $request, $response);
        $credentials = $auth->getCredentials();

        if (!$credentials) {
            return [false, 'No credentials'];
        }

        return [true, $credentials[0]];
    }

    function challenge(\Sabre\HTTP\RequestInterface $request, \Sabre\HTTP\ResponseInterface $response) {
    }
}
