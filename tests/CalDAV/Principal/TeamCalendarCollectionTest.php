<?php

namespace ESN\CalDAV\Principal;

use \Sabre\DAVACL;

#[\AllowDynamicProperties]
class TeamCalendarCollectionTest extends \PHPUnit\Framework\TestCase {

    function testGetChildForPrincipal() {

        $back = new DAVACL\PrincipalBackend\Mock();
        $col = new TeamCalendarCollection($back);
        $r = $col->getChildForPrincipal([
            'uri' => 'principals/team-calendars/team-calendar-id',
        ]);
        $this->assertInstanceOf('ESN\\CalDAV\\Principal\\PrincipalTeamCalendar', $r);
        $this->assertInstanceOf(DAVACL\Principal::class, $r);
        $this->assertNotInstanceOf(PrincipalResource::class, $r);

    }

}
