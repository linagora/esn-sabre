<?php

namespace ESN\CalDAV\Principal;

/**
 * Principal collection for team calendars
 *
 */
class TeamCalendarCollection extends \Sabre\CalDAV\Principal\Collection {

    /**
     * Returns a child object based on principal information
     *
     * @param array $principalInfo
     * @return PrincipalTeamCalendar
     */
    function getChildForPrincipal(array $principalInfo) {

        return new PrincipalTeamCalendar($this->principalBackend, $principalInfo);

    }

}
