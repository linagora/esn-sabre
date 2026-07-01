<?php
namespace ESN\CalDAV;

use ESN\CalDAV\Validation\CalendarObjectValidator;
use ESN\DAV\Sharing\Plugin as SPlugin;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\UnsupportedMediaType;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAVACL\Xml\Property\CurrentUserPrivilegeSet;
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

    function initialize(Server $server) {
        VObjectPropertyRegistry::register();

        parent::initialize($server);
        $server->on('calendarObjectChange', [$this, 'validateCalendarObjectBeforeScheduling'], self::PRIORITY_BEFORE_SCHEDULING);
        $server->on('propFind', [$this, 'propFindSharedCalendar'], 151);
    }

    protected function validateICalendar(&$data, $path, &$modified, RequestInterface $request, ResponseInterface $response, $isNew) {
        try {
            parent::validateICalendar($data, $path, $modified, $request, $response, $isNew);
        } catch (UnsupportedMediaType $e) {
            if (str_starts_with($e->getMessage(), 'Validation error in iCalendar:')) {
                throw new BadRequest($e->getMessage());
            }

            throw $e;
        }
    }

    function validateCalendarObjectBeforeScheduling(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        if ($this->server->getHTTPPrefer()['handling'] !== 'strict') {
            return;
        }

        $this->calendarObjectValidator->validate($vCal);
    }

    function propFindSharedCalendar(PropFind $propFind, INode $node) {
        if (!$node instanceof SharedCalendar || $node->getShareAccess() !== SPlugin::ACCESS_READ) {
            return;
        }

        $prop = '{DAV:}current-user-privilege-set';

        if ($propFind->getStatus($prop) === 200) {
            $currentSet = $propFind->get($prop);
            $filtered = array_values(array_filter($currentSet->getValue(), function ($p) {
                return $p !== '{DAV:}write-properties';
            }));
            $propFind->set($prop, new CurrentUserPrivilegeSet($filtered), 200);
        }
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

    /**
     * This function handles the calendar-query REPORT.
     *
     * The ESN specific overrides live in {@see Report} so that the report
     * handling stays isolated from the rest of the plugin. We only keep the
     * override entrypoint here (Sabre dispatches the report to this method) and
     * delegate, providing the stock implementation as a fallback.
     *
     * @param \Sabre\CalDAV\Xml\Request\CalendarQueryReport $report
     * @return void
     */
    function calendarQueryReport($report) {
        (new Report($this->server))->calendarQueryReport($report, function () use ($report) {
            parent::calendarQueryReport($report);
        });
    }

}