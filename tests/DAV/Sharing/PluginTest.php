<?php

namespace ESN\DAV\Sharing;

use \ESN\Utils\AuthTenant;
use \ESN\Utils\TenantType;

require_once ESN_TEST_BASE. '/DAV/ServerMock.php';

class PluginTest extends \ESN\DAV\ServerMock {

    protected $aclMock;
    const TEAM_CALENDAR_ID = '64b64eadf6d7d8e41d263e0f';

    function setUp(): void {
        parent::setUp();

        $this->sharePlugin = new Plugin($this->esndb);
        $this->server->addPlugin($this->sharePlugin);

        $this->authBackend->setAuthTenant(new AuthTenant('54b64eadf6d7d8e41d263e0f', SERVER_MOCK_DOMAIN_ID));

        $this->aclMock = $this->getMockBuilder(\Sabre\DAVACL\Plugin::class)
                       ->onlyMethods(['checkPrivileges'])
                       ->getMock();

        $this->server->addPlugin($this->aclMock);
    }

    function testShareResourceCalledWithRightParameters() {

        $path = '/calendars/54b64eadf6d7d8e41d263e0f/calendar1';

        $sharees = [];
        $sharees[] = new \Sabre\DAV\Xml\Element\Sharee([
            'href'       => 'mailto:johndoe@example.org',
            'properties' => array(),
            'access'     => Plugin::ACCESS_ADMINISTRATION,
            'comment'    => ''
        ]);
        $sharees[] = new \Sabre\DAV\Xml\Element\Sharee([
            'href'       => 'mailto:johndoe2@example.org',
            'properties' => array(),
            'access'     => Plugin::ACCESS_READ,
            'comment'    => ''
        ]);

        $this->aclMock->expects($this->once())
            ->method('checkPrivileges')
            ->with(
                $this->equalTo($path),
                $this->equalTo('{DAV:}share')
            );

        $this->sharePlugin->shareResource($path, $sharees);
    }

    function testTechnicalTokenCanShareTeamCalendarWithoutOwnerAcl() {
        $path = '/calendars/' . self::TEAM_CALENDAR_ID . '/' . self::TEAM_CALENDAR_ID;
        $principalUri = 'principals/team-calendars/' . self::TEAM_CALENDAR_ID;
        $sharees = [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href'       => 'mailto:johndoe@example.org',
                'properties' => [],
                'access'     => Plugin::ACCESS_READ,
                'comment'    => ''
            ])
        ];

        $this->esndb->team_calendars->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::TEAM_CALENDAR_ID),
            'domainId' => new \MongoDB\BSON\ObjectId(SERVER_MOCK_DOMAIN_ID),
            'domainName' => 'example.org',
            'name' => 'sales',
            'displayName' => 'Sales Team'
        ]);
        $calendarId = $this->caldavBackend->createCalendar($principalUri, self::TEAM_CALENDAR_ID, [
            '{DAV:}displayname' => 'Sales Team'
        ]);
        $this->authBackend->setAuthTenant(new AuthTenant('technicalUser', SERVER_MOCK_DOMAIN_ID, TenantType::Technical));

        $this->aclMock->expects($this->never())
            ->method('checkPrivileges');

        $this->sharePlugin->shareResource($path, $sharees);

        $invites = array_values(array_filter(
            $this->caldavBackend->getInvites($calendarId),
            fn($invite) => $invite->href === 'mailto:johndoe@example.org'
        ));
        $this->assertCount(1, $invites);
        $this->assertEquals('principals/users/54b64eadf6d7d8e41d263e0e', $invites[0]->principal);
    }

    function testTechnicalTokenCannotShareTeamCalendarWithUnknownUser() {
        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);

        $path = '/calendars/' . self::TEAM_CALENDAR_ID . '/' . self::TEAM_CALENDAR_ID;
        $principalUri = 'principals/team-calendars/' . self::TEAM_CALENDAR_ID;
        $sharees = [
            new \Sabre\DAV\Xml\Element\Sharee([
                'href'       => 'mailto:unknown@example.org',
                'properties' => [],
                'access'     => Plugin::ACCESS_READ,
                'comment'    => ''
            ])
        ];

        $this->esndb->team_calendars->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::TEAM_CALENDAR_ID),
            'domainId' => new \MongoDB\BSON\ObjectId(SERVER_MOCK_DOMAIN_ID),
            'domainName' => 'example.org',
            'name' => 'sales',
            'displayName' => 'Sales Team'
        ]);
        $this->caldavBackend->createCalendar($principalUri, self::TEAM_CALENDAR_ID, [
            '{DAV:}displayname' => 'Sales Team'
        ]);
        $this->authBackend->setAuthTenant(new AuthTenant('technicalUser', SERVER_MOCK_DOMAIN_ID, TenantType::Technical));

        $this->aclMock->expects($this->never())
            ->method('checkPrivileges');

        $this->sharePlugin->shareResource($path, $sharees);
    }
}
