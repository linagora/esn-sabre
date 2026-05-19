<?php
namespace ESN\CalDAV;

use ESN\CalDAV\Validation\CalendarObjectValidator;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component\VCalendar;

/**
 * We can not directly use Sabre\CalDAV\Plugin because it's implementation of getCalendarHomeForPrincipal make a false assumption in the case of our DAV backend about the URL of users
 */
class Plugin extends \Sabre\CalDAV\Plugin {
    const PRIORITY_BEFORE_SCHEDULING = 80;

    private $calendarObjectValidator;

    function __construct(?CalendarObjectValidator $calendarObjectValidator = null) {
        $this->calendarObjectValidator = $calendarObjectValidator ?: new CalendarObjectValidator();
    }

    function initialize(\Sabre\DAV\Server $server) {
        parent::initialize($server);
        $server->on('calendarObjectChange', [$this, 'validateCalendarObjectBeforeScheduling'], self::PRIORITY_BEFORE_SCHEDULING);
    }

    function validateCalendarObjectBeforeScheduling(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        if ($this->server->getHTTPPrefer()['handling'] !== 'strict') {
            return;
        }

        $this->calendarObjectValidator->validate($vCal);
    }

    /**
     * Returns the path to a principal's calendar home.
     *
     * The return url must not end with a slash.
     * This function should return null in case a principal did not have
     * a calendar home.
     *
     * @param string $principalUrl
     * @return string
     */
    function getCalendarHomeForPrincipal($principalUrl) {

        $parts = explode('/', trim($principalUrl, '/'));
        if (count($parts) !== 3) return;
        if ($parts[0] !== 'principals') return;
        if ($parts[1] !== 'users' || $parts[1] !== 'resources' ) {
            return self::CALENDAR_ROOT . '/' . $parts[2];
        }

        return;
    }

}