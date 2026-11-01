<?php
namespace ESN\CalDAV;

use ESN\CalDAV\Validation\CalendarObjectValidator;
use ESN\DAV\Sharing\Plugin as SPlugin;
use ESN\DAV\VObjectCachePlugin;
use ESN\Utils\Utils;
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

    /**
     * Last in line on calendarObjectChange, after scheduling and everything
     * else that may still rewrite the object.
     */
    const PRIORITY_CAPTURE_WRITTEN_OBJECT = 1000;

    private $calendarObjectValidator;

    /**
     * The object as the last calendarObjectChange listener left it, kept only
     * for the length of one validateICalendar() call.
     *
     * @var VCalendar|null
     */
    private $writtenObject;

    function __construct(?CalendarObjectValidator $calendarObjectValidator = null) {
        $this->calendarObjectValidator = $calendarObjectValidator ?: new CalendarObjectValidator();
    }

    function initialize(Server $server) {
        VObjectPropertyRegistry::register();

        parent::initialize($server);
        $server->on('calendarObjectChange', [$this, 'validateCalendarObjectBeforeScheduling'], self::PRIORITY_BEFORE_SCHEDULING);
        $server->on('calendarObjectChange', [$this, 'captureWrittenObject'], self::PRIORITY_CAPTURE_WRITTEN_OBJECT);
        $server->on('propFind', [$this, 'propFindSharedCalendar'], 151);
        $server->on('calendarObjectChange', [$this, 'validateUniqueUidInTeamCalendar'], self::PRIORITY_BEFORE_SCHEDULING);
        $server->on('beforeMove', [$this, 'validateBeforeMoveToTeamCalendar'], 40);
        $server->on('beforeCopy', [$this, 'validateBeforeCopyToTeamCalendar'], 40);
        $server->on('beforeUnbind', [$this, 'checkCalendarHomeUnbindPrivilege']);
    }

    /**
     * Sabre only checks {DAV:}unbind on the parent of the deleted node. The parent
     * of a calendar home is the calendar root, which grants every privilege to any
     * authenticated user: the privilege is thus checked on the home itself.
     */
    function checkCalendarHomeUnbindPrivilege($path) {
        if (!$this->server->tree->getNodeForPath($path) instanceof CalendarHome) return;

        $aclPlugin = $this->server->getPlugin('acl');
        if ($aclPlugin) {
            $aclPlugin->checkPrivileges($path, '{DAV:}unbind');
        }
    }

    /**
     * A MOVE within one Team Calendar keeps the object it moves: only a MOVE
     * from another calendar can bring a second object with the same UID.
     */
    function validateBeforeMoveToTeamCalendar($sourcePath, $destinationPath) {
        $this->validateCopiedObjectUidInTeamCalendar($sourcePath, $destinationPath, false);
    }

    /**
     * A COPY leaves its source in place: even within one Team Calendar, it
     * would store a second object with the same UID.
     */
    function validateBeforeCopyToTeamCalendar($sourcePath, $destinationPath) {
        $this->validateCopiedObjectUidInTeamCalendar($sourcePath, $destinationPath, true);
    }

    /**
     * Sabre does not emit calendarObjectChange for a COPY or a MOVE: they are
     * checked by validateBeforeMoveToTeamCalendar() / validateBeforeCopyToTeamCalendar().
     */
    function validateUniqueUidInTeamCalendar(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        if (!$isNew || $request->getMethod() === 'ITIP') return;

        $calendar = $this->teamCalendarAt($calendarPath);
        if ($calendar) {
            $this->assertUidNotInTeamCalendar($vCal, $calendar);
        }
    }

    private function validateCopiedObjectUidInTeamCalendar($sourcePath, $destinationPath, bool $sameCalendarConflicts) {
        list($sourceCalendarPath,) = \Sabre\Uri\split($sourcePath);
        list($destinationCalendarPath,) = \Sabre\Uri\split($destinationPath);
        if (!$sourceCalendarPath || !$destinationCalendarPath) return;

        $destinationCalendar = $this->teamCalendarAt($destinationCalendarPath);
        if (!$destinationCalendar) return;

        $sourceCalendar = $this->server->tree->getNodeForPath($sourceCalendarPath);
        if (!$sameCalendarConflicts && $sourceCalendar instanceof SharedCalendar
            && $sourceCalendar->getCalendarId() === $destinationCalendar->getCalendarId()) return;

        $source = $this->server->tree->getNodeForPath($sourcePath);
        if (!$source instanceof \Sabre\CalDAV\ICalendarObject || $source instanceof \Sabre\CalDAV\Schedule\ISchedulingObject) return;
        $data = $source->get();
        $calendar = \Sabre\VObject\Reader::read(is_resource($data) ? stream_get_contents($data) : $data);
        try {
            $this->assertUidNotInTeamCalendar($calendar, $destinationCalendar);
        } finally {
            $calendar->destroy();
        }
    }

    private function teamCalendarAt($calendarPath): ?SharedCalendar {
        try {
            $calendar = $this->server->tree->getNodeForPath($calendarPath);
        } catch (\Sabre\DAV\Exception\NotFound $e) {
            return null;
        }

        return $calendar instanceof SharedCalendar && Utils::isTeamCalendarFromPrincipal($calendar->getOwner())
            ? $calendar
            : null;
    }

    private function assertUidNotInTeamCalendar(VCalendar $calendar, SharedCalendar $teamCalendar) {
        if (isset($calendar->VEVENT->UID) && $teamCalendar->getBackend()->hasCalendarObjectWithUid(
            $teamCalendar->getCalendarId(), (string) $calendar->VEVENT->UID)) {
            throw new \Sabre\DAV\Exception\Forbidden('The destination Team Calendar already contains an event with this UID.');
        }
    }

    /**
     * Takes a copy of the object as the last listener left it.
     *
     * Sabre destroys the parsed object at the end of validateICalendar(), so a
     * copy is the only way to keep it; see validateICalendar() below for what
     * it is kept for. Cloning is markedly cheaper than parsing the payload
     * again, which is what every reader downstream would otherwise do.
     */
    function captureWrittenObject(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        $this->writtenObject = clone $vCal;
    }

    protected function validateICalendar(&$data, $path, &$modified, RequestInterface $request, ResponseInterface $response, $isNew) {
        $this->writtenObject = null;

        try {
            parent::validateICalendar($data, $path, $modified, $request, $response, $isNew);

            // The copy above is what $data now describes: either Sabre serialized
            // the object into $data, or nothing changed it and $data is still the
            // body it was parsed from. Hand it over so the plugins publishing this
            // write, and the backend denormalizing it, work from the copy instead
            // of parsing those same bytes all over again.
            if ($this->writtenObject) {
                VObjectCachePlugin::cacheFor($this->server)->put($data, $this->writtenObject);
            }
        } catch (UnsupportedMediaType $e) {
            if (str_starts_with($e->getMessage(), 'Validation error in iCalendar:')) {
                throw new BadRequest($e->getMessage());
            }

            throw $e;
        } finally {
            $this->writtenObject = null;
        }
    }

    function validateCalendarObjectBeforeScheduling(RequestInterface $request, ResponseInterface $response, VCalendar $vCal, $calendarPath, &$modified, $isNew) {
        $this->calendarObjectValidator->validateEventDates($vCal);

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