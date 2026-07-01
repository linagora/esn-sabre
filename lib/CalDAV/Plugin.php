<?php
namespace ESN\CalDAV;

use DateTimeZone;
use ESN\CalDAV\Validation\CalendarObjectValidator;
use ESN\DAV\Sharing\Plugin as SPlugin;
use Sabre\CalDAV\ICalendarObjectContainer;
use Sabre\DAV\Exception\BadRequest;
use Sabre\DAV\Exception\UnsupportedMediaType;
use Sabre\DAV\INode;
use Sabre\DAV\PropFind;
use Sabre\DAV\Server;
use Sabre\DAVACL\Xml\Property\CurrentUserPrivilegeSet;
use Sabre\HTTP\RequestInterface;
use Sabre\HTTP\ResponseInterface;
use Sabre\VObject\Component\VCalendar;
use Sabre\VObject\Reader;

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
     * The stock Sabre implementation fetches the matching URIs with a single
     * query but then reloads every object one at a time through
     * getPropertiesForPath(). On a calendar holding tens of thousands of events
     * that N+1 turns a filter-less REPORT (the one issued by the reindex task,
     * see linagora/esn-sabre#403) into as many Mongo round-trips as there are
     * objects, which blows past every reasonable timeout.
     *
     * We override the Depth: 1 calendar-collection case to fetch the calendar
     * data of all matching objects in a single batch, exactly like
     * ICSExportPlugin does for export. Every other shape (direct object,
     * Depth: 0, ...) is rare and left to the stock implementation.
     *
     * @param \Sabre\CalDAV\Xml\Request\CalendarQueryReport $report
     * @return void
     */
    function calendarQueryReport($report) {
        $path = $this->server->getRequestUri();
        $node = $this->server->tree->getNodeForPath($path);
        $depth = $this->server->getHTTPDepth(0);

        if (!($node instanceof ICalendarObjectContainer) || $depth != 1) {
            parent::calendarQueryReport($report);

            return;
        }

        $needsJson = $report->contentType === 'application/calendar+json';

        $calendarTimeZone = null;
        if ($report->expand) {
            // We're expanding, and for that we need to figure out the
            // calendar's timezone.
            $tzProp = '{' . self::NS_CALDAV . '}calendar-timezone';
            $tzResult = $this->server->getProperties($path, [$tzProp]);
            if (isset($tzResult[$tzProp])) {
                $vtimezoneObj = Reader::read($tzResult[$tzProp]);
                $calendarTimeZone = $vtimezoneObj->VTIMEZONE->getTimeZone();
                $vtimezoneObj->destroy();
            } else {
                $calendarTimeZone = new DateTimeZone('UTC');
            }
        }

        $result = $this->buildCalendarQueryReportResult($report, $path, $node, $needsJson, $calendarTimeZone);

        $prefer = $this->server->getHTTPPrefer();

        $this->server->httpResponse->setStatus(207);
        $this->server->httpResponse->setHeader('Content-Type', 'application/xml; charset=utf-8');
        $this->server->httpResponse->setHeader('Vary', 'Brief,Prefer');
        $this->server->httpResponse->setBody($this->server->generateMultiStatus($result, $prefer['return'] === 'minimal'));
    }

    /**
     * Builds the multistatus response entries for a Depth: 1 calendar-query
     * REPORT on a calendar collection.
     *
     * The matching URIs are resolved with a single query and their properties
     * are fetched in one batch through getPropertiesForMultiplePaths(), turning
     * the stock per-object N+1 into a single additional query.
     *
     * @param \Sabre\CalDAV\Xml\Request\CalendarQueryReport $report
     * @param string $path
     * @param ICalendarObjectContainer $node
     * @param bool $needsJson
     * @param DateTimeZone|null $calendarTimeZone
     * @return array
     */
    protected function buildCalendarQueryReportResult($report, $path, ICalendarObjectContainer $node, $needsJson, $calendarTimeZone) {
        if ($this->canReportFromSingleQuery($report, $node)) {
            return $this->buildCalendarQueryReportResultFromSingleQuery($report, $path, $node, $needsJson);
        }

        $calendarDataProp = '{' . self::NS_CALDAV . '}calendar-data';

        $nodePaths = $node->calendarQuery($report->filters);

        $paths = array_map(function ($nodePath) use ($path) {
            return $path . '/' . $nodePath;
        }, $nodePaths);

        $result = [];
        foreach ($this->server->getPropertiesForMultiplePaths($paths, $report->properties) as $properties) {
            if (($needsJson || $report->expand) && isset($properties[200][$calendarDataProp])) {
                $vObject = Reader::read($properties[200][$calendarDataProp]);

                if ($report->expand) {
                    $vObject = $vObject->expand($report->expand['start'], $report->expand['end'], $calendarTimeZone);
                }

                if ($needsJson) {
                    $properties[200][$calendarDataProp] = json_encode($vObject->jsonSerialize());
                } else {
                    $properties[200][$calendarDataProp] = $vObject->serialize();
                }

                // Destroy circular references so PHP will garbage collect the
                // object.
                $vObject->destroy();
            }

            $result[] = $properties;
        }

        return $result;
    }

    /**
     * Whether the calendar-query REPORT can be answered straight from a single
     * Mongo query (uri + calendardata + etag) without going through
     * getPropertiesForMultiplePaths at all.
     *
     * This is the common filter-less case (the reindex REPORT): the objects are
     * already returned with their data by the query, so we skip the extra node
     * resolution round-trip entirely and parse each object at most once.
     *
     * We stay conservative: anything with a real filter, an expand, a non-Mongo
     * backend, or a requested property we cannot synthesise from the query
     * result falls back to the standard (batched) path.
     *
     * @param \Sabre\CalDAV\Xml\Request\CalendarQueryReport $report
     * @param ICalendarObjectContainer $node
     * @return bool
     */
    private function canReportFromSingleQuery($report, ICalendarObjectContainer $node) {
        if ($report->expand) {
            return false;
        }

        if (!($node instanceof SharedCalendar) || !($node->getBackend() instanceof Backend\Mongo)) {
            return false;
        }

        $filters = $report->filters;
        if (!empty($filters['prop-filters']) || !empty($filters['comp-filters'])) {
            return false;
        }

        if (empty($report->properties)) {
            return false;
        }

        $servable = ['{DAV:}getetag', '{' . self::NS_CALDAV . '}calendar-data'];
        foreach ($report->properties as $property) {
            if (!in_array($property, $servable, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Builds the multistatus entries for a filter-less calendar-query REPORT
     * directly from calendarQueryWithAllData(), i.e. a single query returning
     * uri + calendardata + etag for every object.
     *
     * @param \Sabre\CalDAV\Xml\Request\CalendarQueryReport $report
     * @param string $path
     * @param SharedCalendar $node
     * @param bool $needsJson
     * @return array
     */
    private function buildCalendarQueryReportResultFromSingleQuery($report, $path, SharedCalendar $node, $needsJson) {
        $calendarDataProp = '{' . self::NS_CALDAV . '}calendar-data';
        $etagProp = '{DAV:}getetag';

        $wantsCalendarData = in_array($calendarDataProp, $report->properties, true);
        $wantsEtag = in_array($etagProp, $report->properties, true);

        $currentUser = $this->getCurrentUserPrincipal();
        $calendarOwner = $node->getOwner();
        // Same rule as PrivateEventPlugin: a delegate (not the calendar owner)
        // only sees a sanitized version of PRIVATE / CONFIDENTIAL events.
        $sanitizeForDelegate = $currentUser !== null && $calendarOwner !== null && $calendarOwner !== $currentUser;

        $result = [];
        foreach ($node->getBackend()->calendarQueryWithAllData($node->getFullCalendarId(), $report->filters) as $object) {
            $properties = [];

            if ($wantsCalendarData) {
                $properties[$calendarDataProp] = $this->renderCalendarData($object, $node, $currentUser, $sanitizeForDelegate, $needsJson);
            }
            if ($wantsEtag) {
                $properties[$etagProp] = $object['etag'];
            }

            $result[] = [
                200 => $properties,
                404 => [],
                'href' => $path . '/' . $object['uri']
            ];
        }

        return $result;
    }

    /**
     * Renders the calendar-data of a single query result, applying the same
     * private-event sanitization PrivateEventPlugin would apply on the standard
     * propFind path. Non-private objects are returned verbatim without being
     * parsed, so their serialization is byte-for-byte identical to the standard
     * path.
     *
     * @param array $object Query result with uri/calendardata/etag/classification
     * @param SharedCalendar $node
     * @param string|null $currentUser
     * @param bool $sanitizeForDelegate
     * @param bool $needsJson
     * @return string
     */
    private function renderCalendarData($object, SharedCalendar $node, $currentUser, $sanitizeForDelegate, $needsJson) {
        $classification = isset($object['classification']) ? strtoupper($object['classification']) : null;
        $needsSanitization = $sanitizeForDelegate
            && ($classification === 'PRIVATE' || $classification === 'CONFIDENTIAL');

        if (!$needsJson && !$needsSanitization) {
            return $object['calendardata'];
        }

        $vObject = isset($object['vObject']) && $object['vObject'] !== null
            ? $object['vObject']
            : Reader::read($object['calendardata']);

        $rendered = $vObject;
        if ($needsSanitization) {
            $rendered = \ESN\Utils\Utils::hidePrivateEventInfoForUser($vObject, $node, $currentUser);
        }

        $data = $needsJson ? json_encode($rendered->jsonSerialize()) : $rendered->serialize();

        if ($rendered !== $vObject) {
            $rendered->destroy();
        }
        $vObject->destroy();

        return $data;
    }

    private function getCurrentUserPrincipal() {
        $authPlugin = $this->server->getPlugin('auth');

        return $authPlugin ? $authPlugin->getCurrentPrincipal() : null;
    }

}