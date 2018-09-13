<?php

namespace ESN\CalDAV;

require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVServerTest.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/HTTP/SapiMock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVACL/PrincipalBackend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAV/Auth/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/CalDAV/Backend/Mock.php';
require_once ESN_TEST_VENDOR . '/sabre/dav/tests/Sabre/DAVServerTest.php';

/**
 * @medium
 */
class CollaborationMembersPluginTest extends \Sabre\DAVServerTest {
    protected $setupCalDAV = true;
    protected $setupACL = true;

    function setUp() {
        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->{ESN_MONGO_ESNDB};
        $this->plugin = new CollaborationMembersPluginMock($this->esndb);

        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->esndb->drop();
        $this->sabredb->drop();

        $this->userMongoId = new \MongoDB\BSON\ObjectId();
        $this->commMongoId = new \MongoDB\BSON\ObjectId();
        $this->projMongoId = new \MongoDB\BSON\ObjectId();
        $this->userEmail = 'user@example.com';
        $this->autoLogin = 'users/' . $this->userMongoId;

        parent::setUp();

        $this->server->addPlugin($this->plugin);
        $this->esndb->users->insertOne([
            '_id' => $this->userMongoId,
            'firstname' => 'test',
            'lastname' => 'user',
            'accounts' => [
              [ 'type' => 'email', 'emails' => [ $this->userEmail ] ]
            ]
        ]);

        $this->esndb->communities->insertOne([
            '_id' => $this->commMongoId,
            'title' => 'community',
            'members' => [
              [ 'member' => [ 'id' => $this->userMongoId, 'objectType' => 'user' ] ]
            ]
        ]);
        $this->esndb->projects->insertOne([
            '_id' => $this->projMongoId,
            'title' => 'project',
            'members' => [
              [ 'member' => [ 'id' => $this->userMongoId, 'objectType' => 'user' ] ]
            ]
        ]);
    }

    function setUpBackends() {
        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\EsnRequest($this->esndb);
        $this->caldavBackend = new \ESN\CalDAV\Backend\Esn($this->sabredb);
    }

    function setUpTree() {
        $this->tree[] = new CalendarRoot($this->principalBackend,
                                       $this->caldavBackend, $this->esndb);
    }


    private function emitObjectChange($data, $parentPath = null, $isNew = true) {
        $modified = false;
        $vobj = \Sabre\VObject\Reader::read($data);
        if (is_null($parentPath)) {
            $parentPath = 'calendars/' . $this->commMongoId . '/' . $this->commMongoId;
        }
        $this->server->emit(
            'calendarObjectChange',
            [
                $this->server->httpRequest,
                $this->server->httpResponse,
                $vobj,
                $parentPath,
                &$modified,
                $isNew
            ]
        );
        return [$modified, $vobj];
    }

    private function checkModified($modified, $vcal) {
        $this->assertTrue($modified);

        $vevent = $vcal->VEVENT;
        $attendee = $vevent->ATTENDEE;
        $organizer = $vevent->ORGANIZER;
        $this->assertEquals(count($attendee), 1);
        $this->assertEquals(count($organizer), 1);

        $this->assertEquals($attendee, 'mailto:' . $this->userEmail);
        $this->assertEquals($organizer, 'mailto:' . $this->userEmail);

        $this->assertEquals(count($organizer->parameters), 0);
        $this->assertEquals($attendee['CN'], 'test user');
        $this->assertEquals($attendee['PARTSTAT'], 'ACCEPTED');
        $this->assertEquals($attendee['ROLE'], 'REQ-PARTICIPANT');
    }

    function testAdded() {
        $this->markTestSkipped('To ba reactivate when communities calendar gonna be Ok');
        $data = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:123',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);
        list($modified, $vcal) = $this->emitObjectChange($data);
        $this->checkModified($modified, $vcal);
    }

    function testNonVevent() {
        $data = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VTODO',
            'UID:123',
            'END:VTODO',
            'END:VCALENDAR'
        ]);
        list($modified, $vcal) = $this->emitObjectChange($data);
        $this->assertFalse($modified);
    }

    function testUserCalendar() {
        $data = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:123',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);
        $parentPath = 'calendars/' . $this->userMongoId . '/' . $this->userMongoId;
        list($modified, $vcal) = $this->emitObjectChange($data, $parentPath);
        $this->assertFalse($modified);
    }

    function testProjectCalendar() {
        $data = join("\r\n", [
            'BEGIN:VCALENDAR',
            'BEGIN:VEVENT',
            'UID:123',
            'END:VEVENT',
            'END:VCALENDAR'
        ]);

        $this->plugin->setCollection("projects");
        $parentPath = 'calendars/' . $this->projMongoId . '/' . $this->projMongoId;
        list($modified, $vcal) = $this->emitObjectChange($data, $parentPath);
        $this->checkModified($modified, $vcal);
    }

    function testPluginInfo() {
        $info = $this->plugin->getPluginInfo();
        $this->assertEquals($info['name'], 'collaboration-members');
        $this->assertEquals($info['description'], 'Automatically invite members of a group calendar to events created on calendars.');
    }
}

class MockSapi {

    public $response;

    function sendResponse($response) {
        $this->response = $response;
    }
}
class CollaborationMembersPluginMock extends CollaborationMembersPlugin {
    function __construct($esnDb) {
        parent::__construct($esnDb, "communities");
    }

    function setCollection($collectionName) {
        $this->collectionName = $collectionName;
        $this->collection = $this->db->selectCollection($collectionName);
    }
}
