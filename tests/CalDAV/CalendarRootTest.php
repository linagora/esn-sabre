<?php

namespace ESN\CalDAV;

use \ESN\Utils\AuthTenant;

/**
 * @medium
 */
#[\AllowDynamicProperties]
class CalendarRootTest extends \PHPUnit\Framework\TestCase {
    protected $esndb;
    protected $sabredb;
    protected $principalBackend;
    protected $caldavBackend;

    const DOMAIN_ID = '5a095e2c46b72521d03f6d75';
    const OTHER_DOMAIN_ID = '5a095e2c46b72521d03f6d76';
    const USER_ID = '54313fcc398fef406b0041b6';
    const TEAM_CALENDAR_ID = '64313fcc398fef406b0041b6';
    const OTHER_TEAM_CALENDAR_ID = '64313fcc398fef406b0041b7';

    function setUp(): void {
        $mcesn = new \MongoDB\Client(ESN_MONGO_ESNURI);
        $this->esndb = $mcesn->{ESN_MONGO_ESNDB};

        $mcsabre = new \MongoDB\Client(ESN_MONGO_SABREURI);
        $this->sabredb = $mcsabre->{ESN_MONGO_SABREDB};

        $this->esndb->drop();
        $this->sabredb->drop();

        $this->principalBackend = new \ESN\DAVACL\PrincipalBackend\Mongo($this->esndb);
        $this->caldavBackend = new \ESN\CalDAV\Backend\Mongo($this->sabredb);

        $this->root = new CalendarRoot($this->principalBackend,
                                       $this->caldavBackend, $this->esndb);
        $this->root->setAuthTenant(new AuthTenant(self::USER_ID, self::DOMAIN_ID));
    }

    function testConstruct() {
        $this->assertTrue($this->root instanceof CalendarRoot);
        $this->assertTrue($this->root instanceof \Sabre\DAV\Collection);
        $this->assertEquals('calendars', $this->root->getName());
    }

    function testChildren() {
        $this->esndb->users->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId('54313fcc398fef406b0041b6'),
            'domains' => [['domain_id' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID)]]
        ]);
        $this->esndb->resources->insertOne([ '_id' => new \MongoDB\BSON\ObjectId('82113fcc398fef406b0041b7') ]);
        $this->esndb->team_calendars->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::TEAM_CALENDAR_ID),
            'domainId' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID),
            'domainName' => 'example.com',
            'name' => 'sales',
            'displayName' => 'Sales Team'
        ]);
        $this->esndb->team_calendars->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::OTHER_TEAM_CALENDAR_ID),
            'domainId' => new \MongoDB\BSON\ObjectId(self::OTHER_DOMAIN_ID),
            'domainName' => 'other.example.com',
            'name' => 'sales',
            'displayName' => 'Other Sales Team'
        ]);

        $children = $this->root->getChildren();
        $this->assertEquals(3, count($children));

        $user = $children[0];
        $resource = $children[1];
        $teamCalendar = $children[2];

        $this->assertTrue($user instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($user->getName(), '54313fcc398fef406b0041b6');
        $this->assertEquals($user->getOwner(), 'principals/users/54313fcc398fef406b0041b6');

        $this->assertTrue($resource instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($resource->getName(), '82113fcc398fef406b0041b7');
        $this->assertEquals($resource->getOwner(), 'principals/resources/82113fcc398fef406b0041b7');

        $this->assertTrue($teamCalendar instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($teamCalendar->getName(), self::TEAM_CALENDAR_ID);
        $this->assertEquals($teamCalendar->getOwner(), 'principals/team-calendars/' . self::TEAM_CALENDAR_ID);
    }

    function testGetChild() {
        $this->esndb->users->insertOne([ '_id' => new \MongoDB\BSON\ObjectId('54313fcc398fef406b0041b6') ]);
        $this->esndb->team_calendars->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::TEAM_CALENDAR_ID),
            'domainId' => new \MongoDB\BSON\ObjectId(self::DOMAIN_ID),
            'domainName' => 'example.com',
            'name' => 'sales',
            'displayName' => 'Sales Team'
        ]);

        $user = $this->root->getChild('54313fcc398fef406b0041b6');
        $this->assertTrue($user instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($user->getName(), '54313fcc398fef406b0041b6');
        $this->assertEquals($user->getOwner(), 'principals/users/54313fcc398fef406b0041b6');

        $teamCalendar = $this->root->getChild(self::TEAM_CALENDAR_ID);
        $this->assertTrue($teamCalendar instanceof \ESN\CalDAV\CalendarHome);
        $this->assertEquals($teamCalendar->getName(), self::TEAM_CALENDAR_ID);
        $this->assertEquals($teamCalendar->getOwner(), 'principals/team-calendars/' . self::TEAM_CALENDAR_ID);

        $invalid = $this->root->getChild('not_a_mongo_id');
        $this->assertNull($invalid);
    }

    function testGetChildShouldRejectTeamCalendarFromAnotherDomain() {
        $this->expectException(\Sabre\DAV\Exception\Forbidden::class);

        $this->esndb->team_calendars->insertOne([
            '_id' => new \MongoDB\BSON\ObjectId(self::OTHER_TEAM_CALENDAR_ID),
            'domainId' => new \MongoDB\BSON\ObjectId(self::OTHER_DOMAIN_ID),
            'domainName' => 'other.example.com',
            'name' => 'sales',
            'displayName' => 'Other Sales Team'
        ]);

        $this->root->getChild(self::OTHER_TEAM_CALENDAR_ID);
    }

    function testGetChildNotFound() {
        $this->expectException(\Sabre\DAV\Exception\NotFound::class);
        $this->root->getChild('54313fcc398fef406b0041b2');
    }
}
